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

namespace PrintessShopwareIntegration\Controller\Storefront;

use PrintessShopwareIntegration\Service\Cart\PrintessCartLineItemService;
use PrintessShopwareIntegration\Service\Configuration\PrintessConfigService;
use PrintessShopwareIntegration\Service\Product\PrintessProductCustomFieldResolver;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The "reorder" flow for a personalized line item on an already-placed order, shown on the
 * customer's order-history page (see the "Erneut bestellen" button added by
 * `component/line-item/type/product.html.twig` when `displayMode === 'order'`).
 *
 * Deliberately distinct from `PrintessCartItemController`: an already-placed order must never be
 * mutated, so this only ever ADDS a brand-new line item to the customer's current cart (see
 * `PrintessCartLineItemService::addLineItemFromOrderLineItem()`) and sends the customer back to
 * their order list, not the cart, if they cancel out via the editor's back button.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID]])]
class PrintessOrderLineItemController extends StorefrontController
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly PrintessCartLineItemService $cartLineItemService,
        private readonly PrintessConfigService $printessConfigService,
        private readonly EntityRepository $orderRepository,
        private readonly PrintessProductCustomFieldResolver $productCustomFieldResolver,
    ) {
    }

    #[Route(
        path: '/account/order/printess/edit/{orderId}/{lineItemId}',
        name: 'frontend.printess.order.reorder.edit',
        defaults: [
            PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true,
            PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED_ALLOW_GUEST => true,
        ],
        methods: ['GET'],
    )]
    public function edit(string $orderId, string $lineItemId, SalesChannelContext $context): Response
    {
        $lineItem = $this->findOwnedOrderLineItem($orderId, $lineItemId, $context);

        if ($lineItem === null) {
            return $this->redirectToRoute('frontend.account.order.page');
        }

        $editorContext = $this->cartLineItemService->buildEditorContextFromOrderLineItem($lineItem, $context);
        $salesChannelId = $context->getSalesChannelId();

        return $this->renderStorefront('@PrintessShopwareIntegration/storefront/page/printess/cart-item-editor.html.twig', [
            'lineItemId' => $lineItemId,
            'saveToken' => $editorContext['saveToken'],
            'parentProductId' => $editorContext['parentProductId'],
            'configuratorOptions' => $editorContext['configuratorOptions'],
            'currentSelection' => $editorContext['currentSelection'],
            'loaderScriptUrl' => $this->printessConfigService->getEditorLoaderScriptUrl($salesChannelId),
            'shopToken' => $this->printessConfigService->getShopToken($salesChannelId),
            'defaultTheme' => $this->printessConfigService->getDefaultTheme($salesChannelId),
            'editorLanguage' => $this->printessConfigService->getEditorLanguage($salesChannelId),
            'basketThumbnailMaxWidth' => $this->printessConfigService->getBasketThumbnailMaxWidth($salesChannelId),
            'basketThumbnailMaxHeight' => $this->printessConfigService->getBasketThumbnailMaxHeight($salesChannelId),
            'photobookTheme' => $this->productCustomFieldResolver->resolveDefaultLanguage($editorContext['parentProductId'], 'PrintessPhotobookTheme'),
            'updateUrl' => $this->generateUrl('frontend.printess.order.reorder.update', [
                'orderId' => $orderId,
                'lineItemId' => $lineItemId,
            ]),
            'backUrl' => $this->generateUrl('frontend.account.order.page'),
        ]);
    }

    #[Route(
        path: '/account/order/printess/edit/{orderId}/{lineItemId}',
        name: 'frontend.printess.order.reorder.update',
        defaults: [
            'XmlHttpRequest' => true,
            PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true,
            PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED_ALLOW_GUEST => true,
        ],
        methods: ['POST'],
    )]
    public function update(string $orderId, string $lineItemId, Request $request, SalesChannelContext $context): JsonResponse
    {
        $lineItem = $this->findOwnedOrderLineItem($orderId, $lineItemId, $context);

        if ($lineItem === null) {
            return new JsonResponse(['error' => 'This item can no longer be reordered.'], 404);
        }

        $payload = json_decode((string) $request->getContent(), true);
        $payload = \is_array($payload) ? $payload : [];

        $saveToken = $payload['saveToken'] ?? null;

        if (!\is_string($saveToken) || $saveToken === '') {
            return new JsonResponse(['error' => 'No save token was provided.'], 400);
        }

        $thumbnailUrl = \is_string($payload['thumbnailUrl'] ?? null) ? $payload['thumbnailUrl'] : null;
        $options = \is_array($payload['options'] ?? null)
            ? array_filter($payload['options'], 'is_string')
            : [];
        $pageCount = (int) ($payload['pageCount'] ?? 0);
        $priceRelevantFormFields = \is_array($payload['priceRelevantFormFields'] ?? null)
            ? array_filter($payload['priceRelevantFormFields'], 'is_string')
            : [];

        $cart = $this->cartService->getCart($context->getToken(), $context);

        $this->cartLineItemService->addLineItemFromOrderLineItem(
            $cart,
            $lineItem,
            $saveToken,
            $thumbnailUrl,
            $options,
            $pageCount,
            $priceRelevantFormFields,
            $context,
        );

        return new JsonResponse([
            'redirectUrl' => $this->generateUrl('frontend.checkout.cart.page'),
        ]);
    }

    /**
     * Loads the order scoped to the current customer (never trust a bare `orderId`/`lineItemId`
     * pair across requests without re-verifying ownership) and resolves its editable line item.
     */
    private function findOwnedOrderLineItem(string $orderId, string $lineItemId, SalesChannelContext $context): ?OrderLineItemEntity
    {
        $customerId = $context->getCustomer()?->getId();

        if ($customerId === null) {
            return null;
        }

        $criteria = new Criteria([$orderId]);
        $criteria->addFilter(new EqualsFilter('orderCustomer.customerId', $customerId));
        $criteria->addAssociation('lineItems');

        $order = $this->orderRepository->search($criteria, $context->getContext())->getEntities()->first();

        if (!$order instanceof OrderEntity) {
            return null;
        }

        return $this->cartLineItemService->findEditableOrderLineItem($order, $lineItemId);
    }
}
