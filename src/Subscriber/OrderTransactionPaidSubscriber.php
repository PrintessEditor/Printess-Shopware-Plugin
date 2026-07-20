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

namespace PrintessShopwareIntegration\Subscriber;

use PrintessShopwareIntegration\Service\Production\PrintessProductionService;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\StateMachine\Event\StateMachineTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Starts Printess production once an order's payment is confirmed. `order_transaction.state ->
 * paid` was chosen as the trigger (rather than e.g. the order's own process state) since it's the
 * closest Shopware equivalent to the legacy WooCommerce integration's trigger, "order status ->
 * processing" (payment confirmed, ready to fulfill) - production shouldn't be started, and
 * potentially billed by Printess, before payment has actually been captured.
 */
class OrderTransactionPaidSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly PrintessProductionService $printessProductionService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            StateMachineTransitionEvent::class => 'onStateMachineTransition',
        ];
    }

    public function onStateMachineTransition(StateMachineTransitionEvent $event): void
    {
        if ($event->getEntityName() !== OrderTransactionDefinition::ENTITY_NAME) {
            return;
        }

        if ($event->getToPlace()->getTechnicalName() !== OrderTransactionStates::STATE_PAID) {
            return;
        }

        $context = $event->getContext();

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('transactions.id', $event->getEntityId()));
        $criteria->addAssociation('lineItems.product');
        $criteria->addAssociation('deliveries.positions');
        $criteria->addAssociation('deliveries.shippingMethod');
        $criteria->addAssociation('deliveries.shippingOrderAddress.country');
        $criteria->addAssociation('deliveries.shippingOrderAddress.countryState');
        $criteria->addAssociation('billingAddress.country');
        $criteria->addAssociation('billingAddress.countryState');
        $criteria->addAssociation('orderCustomer');

        // Inheritance is off by default on this context - without it, a variant product's own
        // (empty) `PrintessDropshippingConfig`/`PrintessPrintSettingName` custom fields would shadow
        // the parent product's, since those are almost always only configured on the parent. That
        // silently produced non-dropshipped output for dropship-configured variant products.
        $order = $context->enableInheritance(
            fn (Context $inheritanceContext) => $this->orderRepository->search($criteria, $inheritanceContext)->getEntities()->first()
        );

        if ($order === null) {
            return;
        }

        $this->printessProductionService->handleOrderPaid($order, $context);
    }
}
