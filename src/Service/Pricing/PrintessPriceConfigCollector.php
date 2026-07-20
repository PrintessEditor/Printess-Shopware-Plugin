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

namespace PrintessShopwareIntegration\Service\Pricing;

use PrintessShopwareIntegration\Service\Product\PrintessProductCustomFieldResolver;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartDataCollectorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Pre-fetches the `PrintessPriceConfig` custom field for every product referenced by a line item
 * carrying Printess page-count/form-field pricing payload, so `PrintessSurchargeCartProcessor` can
 * compute the surcharge without doing its own DB lookups.
 */
class PrintessPriceConfigCollector implements CartDataCollectorInterface
{
    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly PrintessProductCustomFieldResolver $productCustomFieldResolver,
    ) {
    }

    public function collect(CartDataCollection $data, Cart $original, SalesChannelContext $context, CartBehavior $behavior): void
    {
        $productIds = [];

        foreach ($original->getLineItems()->filterFlatByType(LineItem::PRODUCT_LINE_ITEM_TYPE) as $lineItem) {
            if (self::hasPrintessPricingPayload($lineItem) && $lineItem->getReferencedId() !== null) {
                $productIds[] = $lineItem->getReferencedId();
            }
        }

        $productIds = array_values(array_unique($productIds));

        if ($productIds === []) {
            return;
        }

        $products = $this->productRepository->search(new Criteria($productIds), $context->getContext())->getEntities();

        foreach ($productIds as $productId) {
            $key = self::dataKey($productId);

            if ($data->has($key)) {
                continue;
            }

            /** @var ProductEntity|null $product */
            $product = $products->get($productId);
            $priceConfigRaw = $this->productCustomFieldResolver->resolve($product, $productId, 'PrintessPriceConfig');
            $priceConfig = \is_string($priceConfigRaw) ? json_decode($priceConfigRaw, true) : null;

            $data->set($key, \is_array($priceConfig) ? $priceConfig : null);
        }
    }

    public static function dataKey(string $productId): string
    {
        return 'printess-price-config-' . $productId;
    }

    /**
     * The line item payload comes either from a plain HTML form submission (where every value is a
     * string, e.g. `_printessPageCount = "0"` for a non-book product) or from the cart item editor's
     * JSON request body - so an empty/zero value must be treated as "nothing to price", not just
     * checked for presence.
     */
    public static function hasPrintessPricingPayload(LineItem $lineItem): bool
    {
        $payload = $lineItem->getPayload();

        $pageCount = $payload['_printessPageCount'] ?? null;

        if ($pageCount !== null && (int) $pageCount > 0) {
            return true;
        }

        $formFieldValues = self::decodeFormFieldValues($payload['_printessPriceRelevantFormFields'] ?? null);

        return $formFieldValues !== [];
    }

    /**
     * @return array<string, string>
     */
    public static function decodeFormFieldValues(mixed $rawFormFieldValues): array
    {
        if (\is_string($rawFormFieldValues)) {
            $decoded = json_decode($rawFormFieldValues, true);

            return \is_array($decoded) ? $decoded : [];
        }

        return \is_array($rawFormFieldValues) ? $rawFormFieldValues : [];
    }
}
