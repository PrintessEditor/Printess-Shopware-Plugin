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

use PrintessShopwareIntegration\Service\Pricing\PrintessPriceCalculatorService;
use PrintessShopwareIntegration\Service\Product\PrintessProductCustomFieldResolver;
use Shopware\Core\Content\Product\SalesChannel\Detail\AbstractProductDetailRoute;
use Shopware\Core\Content\Product\SalesChannel\FindVariant\AbstractFindProductVariantRoute;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Resolves the raw, unformatted price of a product/variant combination via Shopware's own price
 * calculation (respecting the current customer/currency/tax context), plus the Printess-specific
 * page-count/form-field-based surcharge (see `PrintessPriceCalculatorService`) if the product has a
 * `PrintessPriceConfig`. The Printess editor needs this as a plain number rather than a currency
 * string scraped out of rendered HTML.
 *
 * This is a *preview* only - what's actually charged at checkout is enforced independently by
 * `PrintessSurchargeCartProcessor`, using the exact same calculator service, so the two can never
 * drift apart.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID]])]
class PrintessPriceController extends StorefrontController
{
    public function __construct(
        private readonly AbstractFindProductVariantRoute $findProductVariantRoute,
        private readonly AbstractProductDetailRoute $productDetailRoute,
        private readonly PrintessPriceCalculatorService $priceCalculatorService,
        private readonly PrintessProductCustomFieldResolver $productCustomFieldResolver,
    ) {
    }

    #[Route(
        path: '/printess/product/{productId}/price',
        name: 'frontend.printess.product.price',
        defaults: ['XmlHttpRequest' => true],
        methods: ['GET'],
    )]
    public function price(string $productId, Request $request, SalesChannelContext $context): JsonResponse
    {
        try {
            $options = json_decode((string) $request->query->get('options', '[]'), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $options = [];
        }

        $pageCount = $request->query->getInt('pageCount', 0);

        try {
            $formFieldValues = json_decode((string) $request->query->get('formFieldValues', '{}'), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $formFieldValues = [];
        }

        if (!\is_array($formFieldValues)) {
            $formFieldValues = [];
        }

        $variantId = $productId;

        if (\is_array($options) && $options !== []) {
            try {
                // `FindProductVariantRoute` searches by `product.parentId`, so it must always be
                // given the parent id - passing a variant's own id here (e.g. when `$productId` is
                // whatever variant the page currently displays) would never match anything and
                // silently fall through to the catch below, leaving the price unchanged.
                $baseProduct = $this->productDetailRoute->load($productId, $request, $context, new Criteria())->getProduct();
                $parentId = $baseProduct->getParentId() ?? $productId;

                $variantRequest = $request->duplicate(['switchedGroup' => null, 'options' => $options]);
                $variantResponse = $this->findProductVariantRoute->load($parentId, $variantRequest, $context);
                $variantId = $variantResponse->getFoundCombination()->getVariantId();
            } catch (\Throwable) {
                // Fall back to the base product id if no matching variant combination is found.
            }
        }

        $product = $this->productDetailRoute->load($variantId, $request, $context, new Criteria())->getProduct();

        $priceConfigRaw = $this->productCustomFieldResolver->resolve($product, $variantId, 'PrintessPriceConfig');
        $priceConfig = \is_string($priceConfigRaw) ? json_decode($priceConfigRaw, true) : null;
        $priceConfig = \is_array($priceConfig) ? $priceConfig : null;

        $basePrice = $product->getCalculatedPrice()->getUnitPrice();
        $surcharge = $this->priceCalculatorService->calculateSurcharge(
            $priceConfig,
            $pageCount,
            $formFieldValues,
            $context->getContext()->getLanguageId(),
        );

        return new JsonResponse([
            'price' => $basePrice + $surcharge,
            'basePrice' => $basePrice,
            'surcharge' => $surcharge,
            'currencyIsoCode' => $context->getCurrency()->getIsoCode(),
            // The variant this price is actually for - `$productId` if no/no matching options were
            // given, otherwise whatever concrete variant `FindProductVariantRoute` resolved to.
            'productId' => $variantId,
        ]);
    }
}
