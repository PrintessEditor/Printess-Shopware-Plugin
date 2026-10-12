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

use PrintessShopwareIntegration\Service\CustomField\OrderLineItemCustomFieldsInstaller;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryStates;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionEntity;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;

/**
 * Handles Printess's dropship callback, sent when a dropshipper shipped items:
 *
 * `{orderId, orderStatus: "shipped", lineItems: [{lineItemId, nonce, trackingId, trackingUrl}]}`
 *
 * Only the line items of that shipment are listed - a dropship group can ship in several parts.
 * Each is matched to an order line item by the nonce sent with it to production
 * (`PrintessDropshipNonce`); the order comes from the callback URL. Printess's `orderId` and
 * `lineItemId` are not used, since the plugin doesn't send them (they must be numbers).
 *
 * For every matched line item, the tracking is stored on it (`PrintessDropshipTracking`, a list,
 * since a resend ships again). Its delivery gets the tracking code and moves to "shipped" once all
 * of its line items are, otherwise to "shipped partially" - which also sends Shopware's shipping
 * mail when that flow is active.
 */
class PrintessDropshipCallbackService
{
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $orderLineItemRepository,
        private readonly EntityRepository $orderDeliveryRepository,
        private readonly StateMachineRegistry $stateMachineRegistry,
    ) {
    }

    /**
     * @param array<mixed> $payload
     *
     * @throws PrintessDropshipCallbackException when the callback is refused
     *
     * @return list<string> the ids of the order line items updated
     */
    public function handle(string $orderId, array $payload, Context $context): array
    {
        if (!Uuid::isValid($orderId)) {
            throw new PrintessDropshipCallbackException('Missing or invalid orderId.', 400);
        }

        $entries = \is_array($payload['lineItems'] ?? null) ? $payload['lineItems'] : [];

        if ($entries === []) {
            return [];
        }

        $order = $this->loadOrder($orderId, $context);

        if ($order === null) {
            throw new PrintessDropshipCallbackException('Order not found.', 404);
        }

        /** @var array<string, array{lineItem: OrderLineItemEntity, tracking: list<array<string, string>>}> $shipped */
        $shipped = [];

        foreach ($entries as $entry) {
            $nonce = \is_array($entry) && \is_string($entry['nonce'] ?? null) ? $entry['nonce'] : '';
            $lineItem = $nonce === '' ? null : $this->findByNonce($order, $nonce);

            if ($lineItem === null) {
                continue;
            }

            $tracking = $shipped[$lineItem->getId()]['tracking'] ?? $this->decodeTracking($lineItem);
            $shipment = [
                'trackingId' => \is_string($entry['trackingId'] ?? null) ? trim($entry['trackingId']) : '',
                'trackingUrl' => \is_string($entry['trackingUrl'] ?? null) ? trim($entry['trackingUrl']) : '',
                'shippedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            ];

            // Printess retries a callback that failed, so the same shipment may arrive twice.
            $known = array_filter($tracking, static fn (array $t): bool => ($t['trackingId'] ?? '') === $shipment['trackingId']
                && ($t['trackingUrl'] ?? '') === $shipment['trackingUrl']);

            if ($known === []) {
                $tracking[] = $shipment;
            }

            $shipped[$lineItem->getId()] = ['lineItem' => $lineItem, 'tracking' => $tracking];
        }

        if ($shipped === []) {
            throw new PrintessDropshipCallbackException('No line item of this order matches the callback.', 403);
        }

        $this->orderLineItemRepository->update(array_values(array_map(static fn (array $item): array => [
            'id' => $item['lineItem']->getId(),
            'customFields' => [
                OrderLineItemCustomFieldsInstaller::DROPSHIP_TRACKING_FIELD_NAME => json_encode($item['tracking'], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
            ],
        ], $shipped)), $context);

        $this->updateDeliveries($order, $shipped, $context);

        return array_keys($shipped);
    }

    /**
     * Before line items are dropshipped again: moves the deliveries they belong to from "shipped"
     * or "shipped partially" back to "open", so the new shipment's callback ships them again -
     * which sends Shopware's shipping mail with the new tracking. Other states stay as they are.
     *
     * @param list<string> $lineItemIds
     */
    public function reopenShippedDeliveries(string $orderId, array $lineItemIds, Context $context): void
    {
        $order = $this->loadOrder($orderId, $context);

        foreach ($order?->getDeliveries() ?? [] as $delivery) {
            if (array_intersect($this->deliveryLineItemIds($order, $delivery), $lineItemIds) === []) {
                continue;
            }

            $current = $delivery->getStateMachineState()?->getTechnicalName();

            if (\in_array($current, [OrderDeliveryStates::STATE_SHIPPED, OrderDeliveryStates::STATE_PARTIALLY_SHIPPED], true)) {
                $this->applyTransition($delivery, StateMachineTransitionActions::ACTION_REOPEN, $context);
            }
        }
    }

    /**
     * @param array<string, array{lineItem: OrderLineItemEntity, tracking: list<array<string, string>>}> $shipped
     */
    private function updateDeliveries(OrderEntity $order, array $shipped, Context $context): void
    {
        foreach ($order->getDeliveries() ?? [] as $delivery) {
            $lineItemIds = $this->deliveryLineItemIds($order, $delivery);
            $shippedHere = array_intersect_key($shipped, array_flip($lineItemIds));

            if ($shippedHere === []) {
                continue;
            }

            $trackingCodes = $delivery->getTrackingCodes();

            foreach ($shippedHere as $item) {
                foreach ($item['tracking'] as $shipment) {
                    if ($shipment['trackingId'] !== '' && !\in_array($shipment['trackingId'], $trackingCodes, true)) {
                        $trackingCodes[] = $shipment['trackingId'];
                    }
                }
            }

            // Before the transition, so the shipping mail it may send lists the codes.
            if ($trackingCodes !== $delivery->getTrackingCodes()) {
                $this->orderDeliveryRepository->update([['id' => $delivery->getId(), 'trackingCodes' => $trackingCodes]], $context);
            }

            $allShipped = true;

            foreach ($lineItemIds as $lineItemId) {
                $lineItem = $order->getLineItems()?->get($lineItemId);

                if (!isset($shipped[$lineItemId]) && ($lineItem === null || $this->decodeTracking($lineItem) === [])) {
                    $allShipped = false;
                }
            }

            $this->transition($delivery, $allShipped ? StateMachineTransitionActions::ACTION_SHIP : StateMachineTransitionActions::ACTION_SHIP_PARTIALLY, $context);
        }
    }

    /**
     * The line items a delivery ships. A line item no delivery lists belongs to the first one, as
     * in `PrintessProductionService::resolveDeliveryForLineItem()`.
     *
     * @return list<string>
     */
    private function deliveryLineItemIds(OrderEntity $order, OrderDeliveryEntity $delivery): array
    {
        $ids = [];

        foreach ($delivery->getPositions() ?? [] as $position) {
            $ids[] = $position->getOrderLineItemId();
        }

        if ($delivery->getId() === $order->getDeliveries()?->first()?->getId()) {
            $listed = [];

            foreach ($order->getDeliveries() as $anyDelivery) {
                foreach ($anyDelivery->getPositions() ?? [] as $position) {
                    $listed[$position->getOrderLineItemId()] = true;
                }
            }

            foreach ($order->getLineItems() ?? [] as $lineItem) {
                if (!isset($listed[$lineItem->getId()]) && $lineItem->getPayloadValue('_printessSaveToken') !== null) {
                    $ids[] = $lineItem->getId();
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Moves the delivery on, if that's still a step forward: never back from "shipped", and not
     * at all from a state that doesn't allow it (cancelled, returned, ...).
     */
    private function transition(OrderDeliveryEntity $delivery, string $action, Context $context): void
    {
        $current = $delivery->getStateMachineState()?->getTechnicalName();

        if ($current === OrderDeliveryStates::STATE_SHIPPED
            || ($action === StateMachineTransitionActions::ACTION_SHIP_PARTIALLY && $current === OrderDeliveryStates::STATE_PARTIALLY_SHIPPED)) {
            return;
        }

        $this->applyTransition($delivery, $action, $context);
    }

    /**
     * Runs the transition if the delivery's current state offers it.
     */
    private function applyTransition(OrderDeliveryEntity $delivery, string $action, Context $context): void
    {
        $available = array_map(
            static fn (StateMachineTransitionEntity $transition): string => $transition->getActionName(),
            $this->stateMachineRegistry->getAvailableTransitions(OrderDeliveryDefinition::ENTITY_NAME, $delivery->getId(), 'stateId', $context),
        );

        if (!\in_array($action, $available, true)) {
            return;
        }

        $this->stateMachineRegistry->transition(
            new Transition(OrderDeliveryDefinition::ENTITY_NAME, $delivery->getId(), $action, 'stateId'),
            $context,
        );
    }

    private function findByNonce(OrderEntity $order, string $nonce): ?OrderLineItemEntity
    {
        foreach ($order->getLineItems() ?? [] as $lineItem) {
            $stored = $lineItem->getCustomFieldsValue(OrderLineItemCustomFieldsInstaller::DROPSHIP_NONCE_FIELD_NAME);

            if (\is_string($stored) && $stored !== '' && hash_equals($stored, $nonce)) {
                return $lineItem;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, string>>
     */
    private function decodeTracking(OrderLineItemEntity $lineItem): array
    {
        $raw = $lineItem->getCustomFieldsValue(OrderLineItemCustomFieldsInstaller::DROPSHIP_TRACKING_FIELD_NAME);

        if (!\is_string($raw) || $raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    private function loadOrder(string $orderId, Context $context): ?OrderEntity
    {
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('deliveries.positions');
        $criteria->addAssociation('deliveries.stateMachineState');

        return $this->orderRepository->search($criteria, $context)->getEntities()->first();
    }
}
