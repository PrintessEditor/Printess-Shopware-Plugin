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

namespace PrintessShopwareIntegration\Controller\Api;

use PrintessShopwareIntegration\Service\Production\PrintessProductionService;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
class PrintessProductionController extends AbstractController
{
    public function __construct(
        private readonly PrintessProductionService $printessProductionService,
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $orderLineItemRepository,
    ) {
    }

    /**
     * Called by Printess itself when a production job finishes (success or failure) - this is the
     * `callbackUrl` passed to the produce call. Unauthenticated (`auth_required: false`) since
     * Printess is a third party with no admin session, same as this route scope's own OAuth token
     * endpoint; the request is instead validated by cross-checking the returned job id against the
     * one stored on the correlated line item (see `PrintessProductionService::handleProductionCallback()`).
     */
    #[Route(
        path: '/api/_action/printess/production/callback',
        name: 'api.action.printess.production.callback',
        defaults: ['auth_required' => false],
        methods: ['POST'],
    )]
    public function productionCallback(Request $request): JsonResponse
    {
        $payload = json_decode((string) $request->getContent(), true);

        if (\is_array($payload)) {
            $this->printessProductionService->handleProductionCallback($payload);
        }

        return new JsonResponse(['status' => 'ok']);
    }

    #[Route(
        path: '/api/_action/printess/orders/{orderId}/line-items/{lineItemId}/approve',
        name: 'api.action.printess.order_line_item.approve',
        methods: ['POST'],
    )]
    public function approve(string $orderId, string $lineItemId, Context $context): JsonResponse
    {
        $order = $this->loadOrder($orderId, $context);
        $lineItem = $order?->getLineItems()?->get($lineItemId);

        if ($order === null || $lineItem === null) {
            return new JsonResponse(['error' => 'Order or line item not found.'], 404);
        }

        $this->printessProductionService->approveLineItem($order, $lineItem, $context);

        return new JsonResponse($this->printessProductionService->getLineItemState($lineItemId, $context));
    }

    #[Route(
        path: '/api/_action/printess/orders/{orderId}/line-items/{lineItemId}/restart',
        name: 'api.action.printess.order_line_item.restart',
        methods: ['POST'],
    )]
    public function restart(string $orderId, string $lineItemId, Context $context): JsonResponse
    {
        $order = $this->loadOrder($orderId, $context);
        $lineItem = $order?->getLineItems()?->get($lineItemId);

        if ($order === null || $lineItem === null) {
            return new JsonResponse(['error' => 'Order or line item not found.'], 404);
        }

        $this->printessProductionService->restartProduction($order, $lineItem, $context);

        return new JsonResponse($this->printessProductionService->getLineItemState($lineItemId, $context));
    }

    #[Route(
        path: '/api/_action/printess/orders/{orderId}/line-items/{lineItemId}/check-status',
        name: 'api.action.printess.order_line_item.check_status',
        methods: ['POST'],
    )]
    public function checkStatus(string $orderId, string $lineItemId, Context $context): JsonResponse
    {
        $criteria = new Criteria([$lineItemId]);
        $lineItem = $this->orderLineItemRepository->search($criteria, $context)->getEntities()->first();

        if ($lineItem === null) {
            return new JsonResponse(['error' => 'Line item not found.'], 404);
        }

        return new JsonResponse($this->printessProductionService->checkStatus($lineItem, $context));
    }

    private function loadOrder(string $orderId, Context $context): ?OrderEntity
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

        // See the same fix in `OrderTransactionPaidSubscriber` - without inheritance, a variant
        // product's own (empty) dropshipping/print-setting custom fields shadow the parent
        // product's, since those are almost always only configured on the parent.
        return $context->enableInheritance(
            fn (Context $inheritanceContext) => $this->orderRepository->search($criteria, $inheritanceContext)->getEntities()->first()
        );
    }
}
