<?php declare(strict_types=1);

/**
 * The MIT License (MIT)
 *
 * Copyright (c) 2026 Printess GmbH & Co. Kg
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * Printess GmbH & Co. Kg does not provide any support for this plugin.
 */

namespace PrintessShopwareIntegration\Service\Production;

use PrintessShopwareIntegration\Service\Configuration\PrintessConfigService;
use PrintessShopwareIntegration\Service\CustomField\OrderCustomFieldsInstaller;
use PrintessShopwareIntegration\Service\CustomField\OrderLineItemCustomFieldsInstaller;
use PrintessShopwareIntegration\Service\Product\PrintessProductCustomFieldResolver;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Starts Printess production jobs for order line items and reconciles their result, whether that
 * result arrives via Printess's production-finished callback or via a manual status check. See
 * `PrintessProductionController` for the HTTP endpoints that call into this service.
 *
 * The save token that identifies what to produce lives on the line item's cart/order payload
 * (`_printessSaveToken`, set by `LineItemAddedSubscriber` when the item is added to the cart) - it
 * is not a custom field. Everything about the *production job itself* (status, job id, result,
 * error) is tracked in `order_line_item` custom fields (see `OrderLineItemCustomFieldsInstaller`),
 * since that data only comes into existence once an order exists.
 */
class PrintessProductionService
{
    private const SAVE_TOKEN_PAYLOAD_KEY = '_printessSaveToken';

    /**
     * Must match the path of `PrintessProductionController::productionCallback()`'s route.
     */
    private const CALLBACK_PATH = '/api/_action/printess/production/callback';

    public function __construct(
        private readonly PrintessConfigService $printessConfigService,
        private readonly ProductionDropshippingResolver $dropshippingResolver,
        private readonly HttpClientInterface $httpClient,
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $orderLineItemRepository,
        private readonly PrintessProductCustomFieldResolver $productCustomFieldResolver,
        private readonly string $appUrl,
    ) {
    }

    /**
     * Called when an order's payment is confirmed. Starts (or queues for approval) production for
     * every line item carrying a Printess save token that hasn't been handled yet.
     */
    public function handleOrderPaid(OrderEntity $order, Context $context): void
    {
        $lineItems = $order->getLineItems();

        if ($lineItems === null) {
            return;
        }

        $requiresApproval = $this->printessConfigService->shouldApproveDesignsBeforeProduction($order->getSalesChannelId());

        foreach ($lineItems as $lineItem) {
            if (!$this->needsProduction($lineItem)) {
                continue;
            }

            if ($requiresApproval) {
                $this->updateLineItem($lineItem->getId(), $context, [
                    OrderLineItemCustomFieldsInstaller::STATUS_FIELD_NAME => PrintessProductionStatus::AWAITING_APPROVAL,
                ]);

                continue;
            }

            $this->produceLineItem($order, $lineItem, $context);
        }
    }

    /**
     * Called from the admin "Approve and send to production" action.
     */
    public function approveLineItem(OrderEntity $order, OrderLineItemEntity $lineItem, Context $context): void
    {
        $this->produceLineItem($order, $lineItem, $context);
    }

    /**
     * Called from the admin "Restart production" action, available once a line item has reached a
     * final state (completed or errored). Wipes the previous run's job id/result/error first, so a
     * completed line item doesn't keep showing its old (about to be superseded) file links while
     * the new job is running, then starts a fresh production job exactly like `approveLineItem()`.
     */
    public function restartProduction(OrderEntity $order, OrderLineItemEntity $lineItem, Context $context): void
    {
        $this->updateLineItem($lineItem->getId(), $context, [
            OrderLineItemCustomFieldsInstaller::STATUS_FIELD_NAME => null,
            OrderLineItemCustomFieldsInstaller::JOB_ID_FIELD_NAME => null,
            OrderLineItemCustomFieldsInstaller::RESULT_FIELD_NAME => null,
            OrderLineItemCustomFieldsInstaller::ERROR_FIELD_NAME => null,
        ]);

        $this->produceLineItem($order, $lineItem, $context);
    }

    /**
     * @return array{status: string|null, result: array<mixed>|null, error: string|null, jobId: string|null}
     */
    public function getLineItemState(string $orderLineItemId, Context $context): array
    {
        $lineItem = $this->loadLineItem($orderLineItemId, $context);

        if ($lineItem === null) {
            return ['status' => null, 'result' => null, 'error' => null, 'jobId' => null];
        }

        return [...$this->currentState($lineItem), 'jobId' => $lineItem->getCustomFieldsValue(OrderLineItemCustomFieldsInstaller::JOB_ID_FIELD_NAME)];
    }

    /**
     * Called from the admin "Check production status" action, and used as a manual fallback for
     * when Printess's production-finished callback never reached the shop.
     *
     * @return array{status: string, result: array<mixed>|null, error: string|null}
     */
    public function checkStatus(OrderLineItemEntity $lineItem, Context $context): array
    {
        $jobId = $lineItem->getCustomFieldsValue(OrderLineItemCustomFieldsInstaller::JOB_ID_FIELD_NAME);

        if (!\is_string($jobId) || $jobId === '') {
            return $this->currentState($lineItem);
        }

        $serviceToken = $this->printessConfigService->getServiceToken();

        if ($serviceToken === '') {
            return $this->currentState($lineItem);
        }

        try {
            $response = $this->httpClient->request(
                'POST',
                $this->printessConfigService->getApiUrl() . '/production/status/get',
                [
                    'auth_bearer' => $serviceToken,
                    'json' => ['jobId' => $jobId],
                ]
            );

            $data = $response->toArray(false);
        } catch (TransportExceptionInterface|\JsonException) {
            return $this->currentState($lineItem);
        }

        return $this->applyJobStatus($lineItem->getId(), $data, $context);
    }

    /**
     * Called by `PrintessProductionController::productionCallback()` once Printess has finished
     * (successfully or not) a production job. Correlates the callback back to a line item via the
     * `meta` field that was sent with the original produce call, and cross-checks the returned job
     * id against the one stored on that line item, mirroring the correlation/integrity approach of
     * the legacy WooCommerce integration's `printess_post_custom_method`.
     *
     * @param array<mixed> $payload
     */
    public function handleProductionCallback(array $payload): void
    {
        $meta = $this->decodeMeta($payload['meta'] ?? null);
        $orderLineItemId = $meta['orderLineItemId'] ?? null;
        $jobId = $payload['jobId'] ?? null;

        if (!\is_string($orderLineItemId) || $orderLineItemId === '' || !\is_string($jobId) || $jobId === '') {
            return;
        }

        $context = Context::createDefaultContext();
        $lineItem = $this->loadLineItem($orderLineItemId, $context);

        if ($lineItem === null) {
            return;
        }

        $storedJobId = $lineItem->getCustomFieldsValue(OrderLineItemCustomFieldsInstaller::JOB_ID_FIELD_NAME);

        if ($storedJobId !== $jobId) {
            return;
        }

        $this->applyJobStatus($orderLineItemId, $payload, $context);
    }

    private function produceLineItem(OrderEntity $order, OrderLineItemEntity $lineItem, Context $context): void
    {
        $saveToken = $lineItem->getPayloadValue(self::SAVE_TOKEN_PAYLOAD_KEY);

        if (!\is_string($saveToken) || $saveToken === '') {
            return;
        }

        $serviceToken = $this->printessConfigService->getServiceToken();

        if ($serviceToken === '') {
            $this->updateLineItem($lineItem->getId(), $context, [
                OrderLineItemCustomFieldsInstaller::STATUS_FIELD_NAME => PrintessProductionStatus::ERROR,
                OrderLineItemCustomFieldsInstaller::ERROR_FIELD_NAME => 'The Printess service token is not configured.',
            ]);

            return;
        }

        $salesChannelId = $order->getSalesChannelId();
        $dropshippingConfig = $this->dropshippingResolver->resolve(
            $this->productCustomFieldValue($lineItem, 'PrintessDropshippingConfig'),
            $salesChannelId,
        );
        $productDefinitionId = $this->dropshippingResolver->resolveProductDefinitionId($dropshippingConfig);

        $printSettingName = $this->productCustomFieldValue($lineItem, 'PrintessPrintSettingName');

        if (!\is_string($printSettingName) || $printSettingName === '') {
            $printSettingName = $this->printessConfigService->getPrintSettingName($salesChannelId);
        }

        $meta = ['orderId' => $order->getId(), 'orderLineItemId' => $lineItem->getId()];

        $data = [
            'templateName' => $saveToken,
            'externalOrderId' => $order->getId(),
            'copies' => $lineItem->getQuantity(),
            'meta' => json_encode($meta, \JSON_THROW_ON_ERROR),
            'callbackUrl' => rtrim($this->appUrl, '/') . self::CALLBACK_PATH,
            'origin' => 'Shopware ' . $this->appUrl,
            'vdp' => [
                'data' => [
                    'orderId' => $order->getId(),
                    'orderLineItemId' => $lineItem->getId(),
                ],
                'form' => [
                    'orderId' => $order->getId(),
                    'orderLineItemId' => $lineItem->getId(),
                ],
            ],
        ];

        if ($printSettingName !== '') {
            $data['printSettingsTemplate'] = $printSettingName;
        }

        $endpoint = '/production/produce';

        if ($productDefinitionId !== null) {
            $endpoint = '/dropship/produce';
            $data['dropship'] = [
                'dropshipDataId' => $this->getOrCreateDropshipAddressId($order, $lineItem, $context),
                'productDefinitionId' => $productDefinitionId,
                'callbackType' => 0,
                'data' => (object) [],
            ];
        }

        try {
            $response = $this->httpClient->request(
                'POST',
                $this->printessConfigService->getApiUrl() . $endpoint,
                [
                    'auth_bearer' => $serviceToken,
                    'json' => $data,
                ]
            );

            $result = $response->toArray(false);
            $jobId = $result['jobId'] ?? null;
            // On a `402`/`406` rejection, Printess responds with `{c, m}` instead of `{jobId}`.
            $errorMessage = \is_string($result['m'] ?? null) ? $result['m'] : null;
        } catch (TransportExceptionInterface|\JsonException $exception) {
            $jobId = null;
            $errorMessage = $exception->getMessage();
        }

        if (\is_string($jobId) && $jobId !== '') {
            $this->updateLineItem($lineItem->getId(), $context, [
                OrderLineItemCustomFieldsInstaller::JOB_ID_FIELD_NAME => $jobId,
                OrderLineItemCustomFieldsInstaller::STATUS_FIELD_NAME => PrintessProductionStatus::IN_PRODUCTION,
                OrderLineItemCustomFieldsInstaller::ERROR_FIELD_NAME => null,
            ]);

            return;
        }

        $this->updateLineItem($lineItem->getId(), $context, [
            OrderLineItemCustomFieldsInstaller::STATUS_FIELD_NAME => PrintessProductionStatus::ERROR,
            OrderLineItemCustomFieldsInstaller::ERROR_FIELD_NAME => $errorMessage ?? 'Printess did not return a job id for this production request.',
        ]);
    }

    /**
     * Finds the delivery (and therefore shipping address) that actually covers this line item -
     * Shopware supports splitting one order across several deliveries with different shipping
     * addresses, so the address to dropship to can differ per line item within the same order.
     * Falls back to the order's first delivery, then its billing address, if no delivery position
     * references this line item (shouldn't normally happen, but e.g. non-physical items may have no
     * delivery at all).
     */
    private function resolveDeliveryForLineItem(OrderEntity $order, OrderLineItemEntity $lineItem): ?OrderDeliveryEntity
    {
        $deliveries = $order->getDeliveries();

        if ($deliveries === null) {
            return null;
        }

        foreach ($deliveries as $delivery) {
            foreach ($delivery->getPositions() ?? [] as $position) {
                if ($position->getOrderLineItemId() === $lineItem->getId()) {
                    return $delivery;
                }
            }
        }

        return $deliveries->first();
    }

    /**
     * Dropshipping requires a saved shipping-address record on Printess's side (`/dropshipData/save`),
     * referenced by id from the produce call. Rather than creating one per production call, this
     * creates at most one per distinct shipping address on the order (cached in the order's
     * `PrintessDropshipAddressIds` custom field, keyed by `order_address` id) - so line items sharing
     * the same shipping address share the same Printess address record, while line items on a
     * split-shipment order with genuinely different addresses each get their own.
     */
    private function getOrCreateDropshipAddressId(OrderEntity $order, OrderLineItemEntity $lineItem, Context $context): ?int
    {
        $delivery = $this->resolveDeliveryForLineItem($order, $lineItem);
        $address = $delivery?->getShippingOrderAddress() ?? $order->getBillingAddress();

        if ($address === null) {
            return null;
        }

        $addressIds = $this->decodeAddressIdMap($order->getCustomFieldsValue(OrderCustomFieldsInstaller::DROPSHIP_ADDRESS_IDS_FIELD_NAME));

        if (isset($addressIds[$address->getId()])) {
            return $addressIds[$address->getId()];
        }

        $dropshipAddressId = $this->createDropshipAddress($order, $address, $delivery);

        if ($dropshipAddressId === null) {
            return null;
        }

        $addressIds[$address->getId()] = $dropshipAddressId;
        $encoded = json_encode($addressIds, \JSON_THROW_ON_ERROR);

        $this->orderRepository->update([
            ['id' => $order->getId(), 'customFields' => [OrderCustomFieldsInstaller::DROPSHIP_ADDRESS_IDS_FIELD_NAME => $encoded]],
        ], $context);

        // Keep the in-memory order in sync, so subsequent line items processed within the same
        // `handleOrderPaid()` loop see the cached id without needing to reload the order.
        $order->changeCustomFields([OrderCustomFieldsInstaller::DROPSHIP_ADDRESS_IDS_FIELD_NAME => $encoded]);

        return $dropshipAddressId;
    }

    private function createDropshipAddress(OrderEntity $order, OrderAddressEntity $address, ?OrderDeliveryEntity $delivery): ?int
    {
        $payload = [
            'companyName' => $address->getCompany() ?? '',
            'firstName' => $address->getFirstName(),
            'lastName' => $address->getLastName(),
            'address1' => $address->getStreet(),
            'address2' => $address->getAdditionalAddressLine1() ?? '',
            'city' => $address->getCity(),
            'zip' => $address->getZipcode() ?? '',
            'country' => $address->getCountry()?->getIso() ?? '',
            'countryState' => $address->getCountryState()?->getShortCode() ?? '',
            'phone' => $address->getPhoneNumber() ?? '',
            'email' => $order->getOrderCustomer()?->getEmail() ?? '',
            'shipping' => $delivery?->getShippingMethod()?->getName() ?? '',
            'dispatchNotice' => $order->getCustomerComment() ?? '',
        ];

        try {
            $response = $this->httpClient->request(
                'POST',
                $this->printessConfigService->getApiUrl() . '/dropshipData/save',
                [
                    'auth_bearer' => $this->printessConfigService->getServiceToken(),
                    'json' => [
                        'userId' => $order->getOrderCustomer()?->getCustomerId() ?? '',
                        'type' => 'printess-shipping',
                        'json' => json_encode($payload, \JSON_THROW_ON_ERROR),
                    ],
                ]
            );

            // Printess returns the new address's id as a bare JSON scalar (e.g. `67530`), not
            // wrapped in an object - `toArray()` requires the decoded JSON to be an array, so it
            // throws on this response and must not be used here (that silently dropped every
            // dropship address, leaving `dropshipDataId` null on every produce call afterwards).
            $data = json_decode($response->getContent(false), true, 512, \JSON_THROW_ON_ERROR);
        } catch (TransportExceptionInterface|\JsonException) {
            return null;
        }

        $id = \is_array($data) ? ($data['id'] ?? $data['dropshipDataId'] ?? null) : $data;

        return \is_numeric($id) ? (int) $id : null;
    }

    /**
     * @return array<string, int>
     */
    private function decodeAddressIdMap(mixed $raw): array
    {
        if (!\is_string($raw) || $raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<mixed> $data
     *
     * @return array{status: string, result: array<mixed>|null, error: string|null, jobId: string|null}
     */
    private function applyJobStatus(string $orderLineItemId, array $data, Context $context): array
    {
        // `/production/status/get` reports `isFinalStatus: false` while a job is still queued or
        // processing - that's not success or failure, just "still in production", and must not be
        // mistaken for a failure (its `isSuccess` is also `false` while pending). The production-
        // finished callback payload doesn't carry `isFinalStatus` at all (it's only ever sent once
        // a job reaches a final state), so its absence here means "treat as final".
        if (\array_key_exists('isFinalStatus', $data) && $data['isFinalStatus'] === false) {
            return [
                'status' => PrintessProductionStatus::IN_PRODUCTION,
                'result' => null,
                'error' => null,
                'jobId' => \is_string($data['jobId'] ?? null) ? $data['jobId'] : null,
            ];
        }

        $result = \is_array($data['result'] ?? null) ? $data['result'] : null;

        // Defensive against the documented discrepancy between the callback payload (which Printess's
        // own docs describe using `isFailure`/`failureDetails`) and the `/production/status/get`
        // response schema (`isSuccess`/`errorDetails`) - check both namings.
        if (\array_key_exists('isFailure', $data)) {
            $isSuccess = !$data['isFailure'];
        } elseif (\array_key_exists('isSuccess', $data)) {
            $isSuccess = (bool) $data['isSuccess'];
        } else {
            $isSuccess = $result !== null;
        }

        if ($isSuccess) {
            $customFields = [
                OrderLineItemCustomFieldsInstaller::STATUS_FIELD_NAME => PrintessProductionStatus::COMPLETED,
                OrderLineItemCustomFieldsInstaller::RESULT_FIELD_NAME => json_encode([
                    'documents' => \is_array($result['r'] ?? null) ? $result['r'] : [],
                    'pages' => \is_array($result['p'] ?? null) ? $result['p'] : [],
                ], \JSON_THROW_ON_ERROR),
                OrderLineItemCustomFieldsInstaller::ERROR_FIELD_NAME => null,
            ];
            $this->updateLineItem($orderLineItemId, $context, $customFields);

            return [
                'status' => PrintessProductionStatus::COMPLETED,
                'result' => json_decode($customFields[OrderLineItemCustomFieldsInstaller::RESULT_FIELD_NAME], true),
                'error' => null,
                'jobId' => \is_string($data['jobId'] ?? null) ? $data['jobId'] : null,
            ];
        }

        $errorMessage = $data['failureDetails'] ?? $data['errorDetails'] ?? 'Printess reported a production failure.';
        $errorMessage = \is_string($errorMessage) ? $errorMessage : 'Printess reported a production failure.';

        $this->updateLineItem($orderLineItemId, $context, [
            OrderLineItemCustomFieldsInstaller::STATUS_FIELD_NAME => PrintessProductionStatus::ERROR,
            OrderLineItemCustomFieldsInstaller::ERROR_FIELD_NAME => $errorMessage,
        ]);

        return [
            'status' => PrintessProductionStatus::ERROR,
            'result' => null,
            'error' => $errorMessage,
            'jobId' => \is_string($data['jobId'] ?? null) ? $data['jobId'] : null,
        ];
    }

    /**
     * @return array{status: string, result: array<mixed>|null, error: string|null, jobId: string|null}
     */
    private function currentState(OrderLineItemEntity $lineItem): array
    {
        $resultJson = $lineItem->getCustomFieldsValue(OrderLineItemCustomFieldsInstaller::RESULT_FIELD_NAME);

        return [
            'status' => $lineItem->getCustomFieldsValue(OrderLineItemCustomFieldsInstaller::STATUS_FIELD_NAME) ?? PrintessProductionStatus::IN_PRODUCTION,
            'result' => \is_string($resultJson) && $resultJson !== '' ? json_decode($resultJson, true) : null,
            'error' => $lineItem->getCustomFieldsValue(OrderLineItemCustomFieldsInstaller::ERROR_FIELD_NAME),
            'jobId' => $lineItem->getCustomFieldsValue(OrderLineItemCustomFieldsInstaller::JOB_ID_FIELD_NAME),
        ];
    }

    private function needsProduction(OrderLineItemEntity $lineItem): bool
    {
        $saveToken = $lineItem->getPayloadValue(self::SAVE_TOKEN_PAYLOAD_KEY);

        if (!\is_string($saveToken) || $saveToken === '') {
            return false;
        }

        // Idempotency guard, mirroring the legacy plugin: once a line item has any production
        // status at all, it has already been handled (queued for approval, produced, or errored)
        // and must not be re-triggered by a repeated order-paid event.
        return $lineItem->getCustomFieldsValue(OrderLineItemCustomFieldsInstaller::STATUS_FIELD_NAME) === null;
    }

    private function productCustomFieldValue(OrderLineItemEntity $lineItem, string $fieldName): ?string
    {
        $productId = $lineItem->getProductId();

        if ($productId === null) {
            return null;
        }

        $value = $this->productCustomFieldResolver->resolve($lineItem->getProduct(), $productId, $fieldName);

        return \is_string($value) ? $value : null;
    }

    /**
     * @param mixed $meta
     *
     * @return array{orderId?: string, orderLineItemId?: string}
     */
    private function decodeMeta(mixed $meta): array
    {
        if (!\is_string($meta) || $meta === '') {
            return [];
        }

        try {
            $decoded = json_decode($meta, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($decoded) ? $decoded : [];
    }

    private function loadLineItem(string $orderLineItemId, Context $context): ?OrderLineItemEntity
    {
        $criteria = new Criteria([$orderLineItemId]);

        return $this->orderLineItemRepository->search($criteria, $context)->getEntities()->first();
    }

    /**
     * @param array<string, mixed> $customFields
     */
    private function updateLineItem(string $orderLineItemId, Context $context, array $customFields): void
    {
        $this->orderLineItemRepository->update([
            [
                'id' => $orderLineItemId,
                'customFields' => $customFields,
            ],
        ], $context);
    }
}
