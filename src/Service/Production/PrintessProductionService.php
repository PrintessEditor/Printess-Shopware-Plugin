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
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
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
 *
 * Dropshipped line items going to the same address form one dropship order at Printess: they are
 * always sent together - on payment, approval and restart (see `getProductionBatch()`) - sharing one
 * address record, a `linkedOrderLineItemsId` that is new on every send, and the number of items
 * sent as `linkedOrderLineItems`. Printess waits until all of them have arrived and then ships
 * them as one shipment.
 */
class PrintessProductionService
{
    private const SAVE_TOKEN_PAYLOAD_KEY = '_printessSaveToken';
    private const THUMBNAIL_URL_PAYLOAD_KEY = '_printessThumbnailUrl';

    /**
     * Must match the path of `PrintessProductionController::productionCallback()`'s route.
     */
    private const CALLBACK_PATH = '/api/_action/printess/production/callback';

    /**
     * Must match the path of `PrintessProductionController::dropshipCallback()`'s route.
     */
    private const DROPSHIP_CALLBACK_PATH = '/api/_action/printess/dropship/callback';

    /**
     * Printess's dropship callback type that posts to a URL and echoes a nonce per line item, also
     * for linked dropship orders (Printess calls it "WooCommerce"; nothing about it is
     * WooCommerce-specific). The general HTTP type (1) only echoes one value per dropship order.
     */
    private const DROPSHIP_CALLBACK_TYPE_URL_WITH_NONCES = 3;

    public function __construct(
        private readonly PrintessConfigService $printessConfigService,
        private readonly ProductionDropshippingResolver $dropshippingResolver,
        private readonly HttpClientInterface $httpClient,
        private readonly EntityRepository $orderLineItemRepository,
        private readonly PrintessProductCustomFieldResolver $productCustomFieldResolver,
        private readonly string $appUrl,
        private readonly PrintessDropshipCallbackService $dropshipCallbackService,
        private readonly PrintessOrderReproductionService $orderReproductionService,
        private readonly EntityRepository $orderRepository,
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
        $pending = [];

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

            $pending[] = $lineItem;
        }

        $this->produceLineItems($order, $pending, $context);
    }

    /**
     * Called from the admin "Approve and send to production" action. Approves the whole production
     * batch of the line item, see `getProductionBatch()`.
     *
     * @throws PrintessProductionActionException when the line item is not awaiting approval
     *
     * @return list<string> the ids of the line items sent to production
     */
    public function approveLineItem(OrderEntity $order, OrderLineItemEntity $lineItem, Context $context): array
    {
        if ($this->status($lineItem) !== PrintessProductionStatus::AWAITING_APPROVAL) {
            throw new PrintessProductionActionException('This item is not awaiting approval.');
        }

        $batch = $this->getProductionBatch($order, $lineItem);
        $this->produceLineItems($order, $batch, $context);

        return array_map(static fn (OrderLineItemEntity $item): string => $item->getId(), $batch);
    }

    /**
     * Called from the admin "Restart production" action, available once a line item was sent to
     * production (in production, completed or errored). Wipes the previous run's job id/result/error first, so a
     * completed line item doesn't keep showing its old (about to be superseded) file links while
     * the new job is running, then starts a fresh production job exactly like `approveLineItem()`.
     *
     * Restarts the whole production batch of the line item (see `getProductionBatch()`): a dropship
     * order at Printess can't be completed by resending one of its items, so all items of the
     * dropship group are sent again as a new dropship order.
     *
     * Only for a line item that went to production before: before payment, production starts with
     * the payment, and while awaiting approval with the approval.
     *
     * @throws PrintessProductionActionException when the line item hasn't been sent to production yet
     *
     * @return list<string> the ids of the line items sent to production
     */
    public function restartProduction(OrderEntity $order, OrderLineItemEntity $lineItem, Context $context): array
    {
        $this->assertRestartable($lineItem);

        $batch = $this->getProductionBatch($order, $lineItem);

        foreach ($batch as $item) {
            $this->updateLineItem($item->getId(), $context, [
                OrderLineItemCustomFieldsInstaller::STATUS_FIELD_NAME => null,
                OrderLineItemCustomFieldsInstaller::JOB_ID_FIELD_NAME => null,
                OrderLineItemCustomFieldsInstaller::RESULT_FIELD_NAME => null,
                OrderLineItemCustomFieldsInstaller::ERROR_FIELD_NAME => null,
            ]);
        }

        $this->produceLineItems($order, $batch, $context);

        return array_map(static fn (OrderLineItemEntity $item): string => $item->getId(), $batch);
    }

    /**
     * The line items that approving or restarting this line item sends to production: just this one,
     * or - for a dropshipped line item - every dropshipped line item of the order going to the same
     * address (address data and shipping method, see `dropshipGroupKey()`), in order position.
     *
     * @return list<OrderLineItemEntity>
     */
    public function getProductionBatch(OrderEntity $order, OrderLineItemEntity $lineItem): array
    {
        if (!$this->isDropshipLineItem($order, $lineItem)) {
            return [$lineItem];
        }

        $key = $this->dropshipGroupKey($this->dropshipAddressPayload($order, $lineItem));
        $batch = [];

        foreach ($order->getLineItems() ?? [] as $candidate) {
            if ($candidate->getId() === $lineItem->getId()
                || ($key !== null
                    && $this->isDropshipLineItem($order, $candidate)
                    && $this->dropshipGroupKey($this->dropshipAddressPayload($order, $candidate)) === $key)
            ) {
                $batch[] = $candidate;
            }
        }

        return $batch;
    }

    /**
     * What the admin shows before approving or restarting: the line items of the batch, with the
     * personalization thumbnail, and whether they are a dropship group.
     *
     * @return array{dropship: bool, lineItems: list<array<string, mixed>>, reproductionMode: string, nextShopOrderId: string|null, printessLineItems: list<array<string, mixed>>}
     */
    public function describeProductionBatch(OrderEntity $order, OrderLineItemEntity $lineItem): array
    {
        $batch = $this->getProductionBatch($order, $lineItem);
        $dropship = $this->isDropshipLineItem($order, $lineItem);
        $printessLineItems = array_values(array_filter(
            $order->getLineItems()?->getElements() ?? [],
            fn (OrderLineItemEntity $item): bool => $this->saveToken($item) !== null,
        ));
        usort($printessLineItems, static fn (OrderLineItemEntity $a, OrderLineItemEntity $b): int => $a->getPosition() <=> $b->getPosition());
        $nextShopOrderId = null;

        if ($dropship) {
            $sendNumber = 1 + max(array_map(fn (OrderLineItemEntity $item): int => $this->dropshipSendCount($item), $batch));
            $nextShopOrderId = $this->dropshipShopOrderId($order, (string) $this->dropshipGroupKey($this->dropshipAddressPayload($order, $lineItem)), $sendNumber);
        }

        return [
            'dropship' => $dropship,
            'lineItems' => array_map($this->describeLineItem(...), $batch),
            // What a dropship restart may do instead of sending again, see `reproduceAsCopy()`.
            'reproductionMode' => $this->printessConfigService->getDropshipReproductionMode($order->getSalesChannelId()),
            // The order number the dropshipper gets when the batch is sent again, e.g. `10015-R1`.
            'nextShopOrderId' => $nextShopOrderId,
            // What the merchant can pick for a copy of the order.
            'printessLineItems' => array_map($this->describeLineItem(...), $printessLineItems),
        ];
    }

    /**
     * Reproduces dropship items as a copy of the order (see `PrintessOrderReproductionService`)
     * instead of sending them again under the same order: for dropshippers that refuse an order
     * number twice. The copy's Printess items go to production right away - the merchant just
     * confirmed them, so no approval is asked for.
     *
     * @param list<string> $lineItemIds the original's Printess line items to copy
     *
     * @throws PrintessProductionActionException when the line item can't be reproduced this way
     *
     * @return array{id: string, orderNumber: string}
     */
    public function reproduceAsCopy(OrderEntity $order, OrderLineItemEntity $lineItem, array $lineItemIds, string $message, bool $reduceStock, Context $context): array
    {
        if ($this->printessConfigService->getDropshipReproductionMode($order->getSalesChannelId()) !== 'ask') {
            throw new PrintessProductionActionException('Duplicating the order is switched off in the Printess settings.');
        }

        if (!$this->isDropshipLineItem($order, $lineItem)) {
            throw new PrintessProductionActionException('Only dropshipped items are reproduced as a copy of the order.');
        }

        $this->assertRestartable($lineItem);

        $printessIds = [];

        foreach ($order->getLineItems() ?? [] as $item) {
            if ($this->saveToken($item) !== null) {
                $printessIds[] = $item->getId();
            }
        }

        $lineItemIds = array_values(array_intersect($lineItemIds, $printessIds));

        if ($lineItemIds === []) {
            throw new PrintessProductionActionException('Choose at least one item for the copy.');
        }

        if (trim($message) === '') {
            throw new PrintessProductionActionException('Enter a message for the customer.');
        }

        $copy = $this->orderReproductionService->copy($order->getId(), $lineItemIds, $message, $reduceStock, $context);
        $copyOrder = $this->loadOrderForProduction($copy['id'], $context);

        if ($copyOrder !== null) {
            $this->produceLineItems($copyOrder, array_values($copyOrder->getLineItems()?->getElements() ?? []), $context);
        }

        return $copy;
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

    /**
     * Sends line items to production together. Dropshipped ones are grouped by address: each group
     * gets one address record and one new `linkedOrderLineItemsId`, and `linkedOrderLineItems` is
     * the number of its items sent here - so a group must always be sent in one call.
     *
     * @param list<OrderLineItemEntity> $lineItems
     */
    private function produceLineItems(OrderEntity $order, array $lineItems, Context $context): void
    {
        $lineItems = array_values(array_filter($lineItems, fn (OrderLineItemEntity $item): bool => $this->saveToken($item) !== null));

        if ($lineItems === []) {
            return;
        }

        if ($this->printessConfigService->getServiceToken() === '') {
            $this->setError($lineItems, 'The Printess service token is not configured.', $context);

            return;
        }

        /** @var array<string, array{address: array<string, string>|null, items: list<array{OrderLineItemEntity, int}>}> $groups */
        $groups = [];

        foreach ($lineItems as $lineItem) {
            $productDefinitionId = $this->productDefinitionIdFor($order, $lineItem);

            if (!$this->dropshippingResolver->isDropshipActive($productDefinitionId)) {
                $this->produceLineItem($order, $lineItem, $productDefinitionId, null, $context);

                continue;
            }

            $address = $this->dropshipAddressPayload($order, $lineItem);
            $key = $this->dropshipGroupKey($address) ?? '';
            $groups[$key]['address'] = $address;
            $groups[$key]['items'][] = [$lineItem, $productDefinitionId];
        }

        foreach ($groups as $key => $group) {
            $items = array_map(static fn (array $entry): OrderLineItemEntity => $entry[0], $group['items']);

            if ($group['address'] === null) {
                $this->setError($items, 'The order has no shipping address to dropship to.', $context);

                continue;
            }

            $dropshipDataId = $this->createDropshipAddress($order, $group['address']);

            if ($dropshipDataId === null) {
                $this->setError($items, 'Printess could not save the dropship address (/dropshipData/save), so nothing was sent to production.', $context);

                continue;
            }

            // The how-many-th send of this group: 1 the first time, more on every reproduction.
            $sendNumber = 1 + max(array_map(fn (OrderLineItemEntity $item): int => $this->dropshipSendCount($item), $items));

            // Sent before, possibly already shipped: back to "open", so the new shipment's callback
            // ships it again and Shopware's shipping mail tells the customer the new tracking.
            if ($sendNumber > 1) {
                $this->dropshipCallbackService->reopenShippedDeliveries($order->getId(), array_map(static fn (OrderLineItemEntity $item): string => $item->getId(), $items), $context);
            }

            $dropship = [
                'dropshipDataId' => $dropshipDataId,
                'linkedOrderLineItems' => \count($items),
                // New on every send: Printess finds a dropship order by this id, and an id reused by a
                // restart would be counted together with the items sent before.
                'linkedOrderLineItemsId' => Uuid::randomHex(),
                'sendNumber' => $sendNumber,
                'shopOrderId' => $this->dropshipShopOrderId($order, (string) $key, $sendNumber),
            ];

            foreach ($group['items'] as [$lineItem, $productDefinitionId]) {
                $this->produceLineItem($order, $lineItem, $productDefinitionId, $dropship, $context);
            }
        }
    }

    /**
     * @param array{dropshipDataId: int, linkedOrderLineItems: int, linkedOrderLineItemsId: string, sendNumber: int, shopOrderId: string}|null $dropship null for normal production
     */
    private function produceLineItem(OrderEntity $order, OrderLineItemEntity $lineItem, int $productDefinitionId, ?array $dropship, Context $context): void
    {
        $saveToken = $this->saveToken($lineItem);

        if ($saveToken === null) {
            return;
        }

        $serviceToken = $this->printessConfigService->getServiceToken();
        $salesChannelId = $order->getSalesChannelId();

        $printSettingName = $this->productCustomFieldValue($lineItem, 'PrintessPrintSettingName');

        if (!\is_string($printSettingName) || $printSettingName === '') {
            $printSettingName = $this->printessConfigService->getPrintSettingName($salesChannelId);
        }

        $meta = ['orderId' => $order->getId(), 'orderLineItemId' => $lineItem->getId()];
        // The number the merchant knows the order by; the callback finds the line item via `meta`.
        $orderNumber = $order->getOrderNumber() ?? $order->getId();
        $vdpValues = $this->vdpValues($order, $lineItem, $saveToken, $orderNumber);

        $data = [
            'templateName' => $saveToken,
            'externalOrderId' => $orderNumber,
            'copies' => $lineItem->getQuantity(),
            'meta' => json_encode($meta, \JSON_THROW_ON_ERROR),
            'callbackUrl' => $this->callbackBaseUrl() . self::CALLBACK_PATH,
            'origin' => 'Shopware ' . $this->appUrl,
            'vdp' => [
                'data' => $vdpValues,
                'form' => $vdpValues,
            ],
        ];

        if ($printSettingName !== '') {
            $data['printSettingsTemplate'] = $printSettingName;
        }

        $endpoint = '/production/produce';

        if ($dropship !== null) {
            // Printess echoes this nonce when the item ships, which proves the callback came from
            // Printess (see `PrintessDropshipCallbackService`). New on every send, and stored
            // before sending, so a callback can't arrive before the shop knows it.
            $nonce = bin2hex(random_bytes(32));
            $this->updateLineItem($lineItem->getId(), $context, [
                OrderLineItemCustomFieldsInstaller::DROPSHIP_NONCE_FIELD_NAME => $nonce,
                OrderLineItemCustomFieldsInstaller::DROPSHIP_SEND_COUNT_FIELD_NAME => $dropship['sendNumber'],
                OrderLineItemCustomFieldsInstaller::DROPSHIP_SHOP_ORDER_ID_FIELD_NAME => $dropship['shopOrderId'],
            ]);

            $endpoint = '/dropship/produce';
            $data['dropship'] = [
                'dropshipDataId' => $dropship['dropshipDataId'],
                'productDefinitionId' => $productDefinitionId,
                'linkedOrderLineItems' => $dropship['linkedOrderLineItems'],
                'linkedOrderLineItemsId' => $dropship['linkedOrderLineItemsId'],
                'callbackType' => self::DROPSHIP_CALLBACK_TYPE_URL_WITH_NONCES,
                // No `orderId`/`lineItemId`: Printess reads them as numbers, and a Shopware id isn't
                // one. The order is in the URL, the line item is found by its nonce.
                'callbackPayload' => [
                    'url' => $this->callbackBaseUrl() . self::DROPSHIP_CALLBACK_PATH . '?orderId=' . $order->getId(),
                    'nonce' => $nonce,
                ],
                // The order number the dropshipper shows (Siteflow: `sourceOrderId`, email dropshipper:
                // in the mail). Siteflow refuses one it has seen before, hence the suffixes.
                'data' => ['shopOrderId' => $dropship['shopOrderId']],
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
     * The order data a template's form fields can be mapped to, sent as `vdp.data` and `vdp.form`.
     * The keys are the ones the pre-rewrite plugin sent (e.g. `orderName`, `ShippingCity`), so print
     * settings set up back then keep working. Values Shopware doesn't have are left out, so the
     * template keeps its default for them.
     *
     * @return array<string, string>
     */
    private function vdpValues(OrderEntity $order, OrderLineItemEntity $lineItem, string $saveToken, string $orderNumber): array
    {
        $product = $lineItem->getProduct();
        $productNumber = $product?->getProductNumber() ?? (($lineItem->getPayload() ?? [])['productNumber'] ?? null);
        $delivery = $this->resolveDeliveryForLineItem($order, $lineItem);
        $customer = $order->getOrderCustomer();

        $values = [
            'orderId' => $order->getId(),
            'orderNumber' => $orderNumber,
            'orderName' => $orderNumber,
            'orderDate' => $order->getOrderDateTime()->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            'orderLineItemId' => $lineItem->getId(),
            'lineItemId' => $lineItem->getId(),
            'itemQuantity' => (string) $lineItem->getQuantity(),
            'saveToken' => $saveToken,
            'productName' => $lineItem->getLabel(),
            'itemSku' => \is_string($productNumber) ? $productNumber : null,
            'variantId' => $product?->getParentId() !== null ? $product?->getId() : null,
            'shippingDateEarliest' => $delivery?->getShippingDateEarliest()->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            'shippingDateLatest' => $delivery?->getShippingDateLatest()->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            'ShippingMethod' => $delivery?->getShippingMethod()?->getName(),
            'customerEmail' => $customer?->getEmail(),
            'customerFirstName' => $customer?->getFirstName(),
            'customerLastName' => $customer?->getLastName(),
            'customerName' => $customer !== null ? trim($customer->getFirstName() . ' ' . $customer->getLastName()) : null,
        ];

        $values += $this->addressVdpValues($delivery?->getShippingOrderAddress(), 'Shipping');
        $values += $this->addressVdpValues($order->getBillingAddress(), 'Billing');

        return array_filter($values, static fn (?string $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, string|null>
     */
    private function addressVdpValues(?OrderAddressEntity $address, string $prefix): array
    {
        if ($address === null) {
            return [];
        }

        return [
            $prefix . 'CompanyName' => $address->getCompany(),
            $prefix . 'DepartmentName' => $address->getDepartment(),
            $prefix . 'FirstName' => $address->getFirstName(),
            $prefix . 'LastName' => $address->getLastName(),
            $prefix . 'Address1' => $address->getStreet(),
            $prefix . 'Address2' => $address->getAdditionalAddressLine1(),
            $prefix . 'Address3' => $address->getAdditionalAddressLine2(),
            $prefix . 'Zip' => $address->getZipcode(),
            $prefix . 'City' => $address->getCity(),
            $prefix . 'Phone' => $address->getPhoneNumber(),
            $prefix . 'Country' => $address->getCountry()?->getIso3(),
            $prefix . 'CountryState' => $address->getCountryState()?->getShortCode(),
        ];
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
     * The address data a dropshipped line item is shipped with: the shipping address of its delivery
     * (or the billing address), the shipping method and the customer's comment. Null when the order
     * has no address at all.
     *
     * @return array<string, string>|null
     */
    private function dropshipAddressPayload(OrderEntity $order, OrderLineItemEntity $lineItem): ?array
    {
        $delivery = $this->resolveDeliveryForLineItem($order, $lineItem);
        $address = $delivery?->getShippingOrderAddress() ?? $order->getBillingAddress();

        if ($address === null) {
            return null;
        }

        return [
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
            'dispatchNotice' => $this->customerComment($order),
        ];
    }

    /**
     * Line items with the same address data go into one dropship order, whether or not their
     * deliveries share the same `order_address` row.
     *
     * @param array<string, string>|null $addressPayload
     */
    private function dropshipGroupKey(?array $addressPayload): ?string
    {
        return $addressPayload === null ? null : hash('sha256', json_encode($addressPayload, \JSON_THROW_ON_ERROR));
    }

    /**
     * Saves the address record a dropship order references by `dropshipDataId`. One per dropship
     * group and send.
     *
     * @param array<string, string> $payload
     */
    private function createDropshipAddress(OrderEntity $order, array $payload): ?int
    {
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

        // Printess refuses a dropship order without a positive `dropshipDataId`.
        return \is_numeric($id) && (int) $id > 0 ? (int) $id : null;
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
        if ($this->saveToken($lineItem) === null) {
            return false;
        }

        // Idempotency guard, mirroring the legacy plugin: once a line item has any production
        // status at all, it has already been handled (queued for approval, produced, or errored)
        // and must not be re-triggered by a repeated order-paid event.
        return $lineItem->getCustomFieldsValue(OrderLineItemCustomFieldsInstaller::STATUS_FIELD_NAME) === null;
    }

    /**
     * Restart (and reproduction as a copy) only redoes production that already happened.
     *
     * @throws PrintessProductionActionException
     */
    private function assertRestartable(OrderLineItemEntity $lineItem): void
    {
        $status = $this->status($lineItem);

        if ($status === null) {
            throw new PrintessProductionActionException('Production starts once the order is paid.');
        }

        if ($status === PrintessProductionStatus::AWAITING_APPROVAL) {
            throw new PrintessProductionActionException('This item is awaiting approval. Approve it to start production.');
        }
    }

    /**
     * @return array{id: string, label: string, quantity: int, thumbnailUrl: string|null}
     */
    private function describeLineItem(OrderLineItemEntity $item): array
    {
        $thumbnailUrl = $item->getPayloadValue(self::THUMBNAIL_URL_PAYLOAD_KEY);

        return [
            'id' => $item->getId(),
            'label' => $item->getLabel(),
            'quantity' => $item->getQuantity(),
            'thumbnailUrl' => \is_string($thumbnailUrl) && $thumbnailUrl !== '' ? $thumbnailUrl : null,
        ];
    }

    /**
     * An order with everything production reads: products, deliveries with their addresses and
     * shipping methods, the billing address and the customer.
     */
    private function loadOrderForProduction(string $orderId, Context $context): ?OrderEntity
    {
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('lineItems.product');
        $criteria->addAssociation('deliveries.positions');
        $criteria->addAssociation('deliveries.shippingMethod');
        $criteria->addAssociation('deliveries.shippingOrderAddress.country');
        $criteria->addAssociation('deliveries.shippingOrderAddress.countryState');
        $criteria->addAssociation('billingAddress.country');
        $criteria->addAssociation('billingAddress.countryState');
        $criteria->addAssociation('orderCustomer');

        $order = $this->orderRepository->search($criteria, $context)->first();

        return $order instanceof OrderEntity ? $order : null;
    }

    private function status(OrderLineItemEntity $lineItem): ?string
    {
        $status = $lineItem->getCustomFieldsValue(OrderLineItemCustomFieldsInstaller::STATUS_FIELD_NAME);

        return \is_string($status) && $status !== '' ? $status : null;
    }

    /**
     * Where Printess sends its callbacks: the shop's own URL, unless the debugging setting points
     * them to a publicly reachable one (e.g. an HTTP tunnel to a local shop).
     */
    private function callbackBaseUrl(): string
    {
        $override = $this->printessConfigService->getCallbackBaseUrl();

        return $override !== '' ? $override : rtrim($this->appUrl, '/');
    }

    /**
     * How often the line item was sent to dropshipping so far.
     */
    private function dropshipSendCount(OrderLineItemEntity $lineItem): int
    {
        $count = $lineItem->getCustomFieldsValue(OrderLineItemCustomFieldsInstaller::DROPSHIP_SEND_COUNT_FIELD_NAME);

        return is_numeric($count) ? max(0, (int) $count) : 0;
    }

    /**
     * The order number sent to the dropshipper for a group: the shop's order number, `-2`, `-3`, ...
     * for the order's further address groups (each is a dropship order of its own), and `-R1`,
     * `-R2`, ... for every reproduction - dropshippers like Siteflow refuse a number twice.
     */
    private function dropshipShopOrderId(OrderEntity $order, string $groupKey, int $sendNumber): string
    {
        $shopOrderId = $order->getOrderNumber() ?? $order->getId();
        $groupIndex = $this->dropshipGroupIndex($order, $groupKey);

        if ($groupIndex > 1) {
            $shopOrderId .= '-' . $groupIndex;
        }

        if ($sendNumber > 1) {
            $shopOrderId .= '-R' . ($sendNumber - 1);
        }

        return $shopOrderId;
    }

    /**
     * 1 for the order's first dropship group, 2 for the second, ... - in line item position order,
     * so a group keeps its number however its items are approved or restarted.
     */
    private function dropshipGroupIndex(OrderEntity $order, string $groupKey): int
    {
        $lineItems = array_values($order->getLineItems()?->getElements() ?? []);
        usort($lineItems, static fn (OrderLineItemEntity $a, OrderLineItemEntity $b): int => $a->getPosition() <=> $b->getPosition());
        $keys = [];

        foreach ($lineItems as $lineItem) {
            if (!$this->isDropshipLineItem($order, $lineItem)) {
                continue;
            }

            $key = $this->dropshipGroupKey($this->dropshipAddressPayload($order, $lineItem));

            if ($key !== null && !\in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        $index = array_search($groupKey, $keys, true);

        return $index === false ? 1 : $index + 1;
    }

    /**
     * The customer's own order comment. On a copy made to reproduce dropship items it is the
     * original's - the copy's comment field holds the merchant's message to the customer.
     */
    private function customerComment(OrderEntity $order): string
    {
        $reproductionOf = $order->getCustomFieldsValue(OrderCustomFieldsInstaller::REPRODUCTION_OF_FIELD_NAME);

        if (\is_string($reproductionOf) && $reproductionOf !== '') {
            try {
                $decoded = json_decode($reproductionOf, true, 512, \JSON_THROW_ON_ERROR);

                if (\is_array($decoded)) {
                    return \is_string($decoded['customerComment'] ?? null) ? $decoded['customerComment'] : '';
                }
            } catch (\JsonException) {
            }
        }

        return $order->getCustomerComment() ?? '';
    }

    private function saveToken(OrderLineItemEntity $lineItem): ?string
    {
        $saveToken = $lineItem->getPayloadValue(self::SAVE_TOKEN_PAYLOAD_KEY);

        return \is_string($saveToken) && $saveToken !== '' ? $saveToken : null;
    }

    private function productDefinitionIdFor(OrderEntity $order, OrderLineItemEntity $lineItem): int
    {
        return $this->dropshippingResolver->resolveProductDefinitionId(
            $this->productCustomFieldValue($lineItem, 'PrintessDropshippingConfig'),
            $order->getSalesChannelId(),
        );
    }

    private function isDropshipLineItem(OrderEntity $order, OrderLineItemEntity $lineItem): bool
    {
        return $this->saveToken($lineItem) !== null
            && $this->dropshippingResolver->isDropshipActive($this->productDefinitionIdFor($order, $lineItem));
    }

    /**
     * @param list<OrderLineItemEntity> $lineItems
     */
    private function setError(array $lineItems, string $message, Context $context): void
    {
        foreach ($lineItems as $lineItem) {
            $this->updateLineItem($lineItem->getId(), $context, [
                OrderLineItemCustomFieldsInstaller::STATUS_FIELD_NAME => PrintessProductionStatus::ERROR,
                OrderLineItemCustomFieldsInstaller::ERROR_FIELD_NAME => $message,
            ]);
        }
    }

    private function productCustomFieldValue(OrderLineItemEntity $lineItem, string $fieldName): ?string
    {
        $productId = $lineItem->getProductId();

        if ($productId === null) {
            return null;
        }

        $value = $this->productCustomFieldResolver->resolve($lineItem->getProduct(), $productId, $fieldName);

        // A number written through the API instead of the admin still counts, e.g. a dropship id.
        return \is_string($value) ? $value : (\is_int($value) ? (string) $value : null);
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
