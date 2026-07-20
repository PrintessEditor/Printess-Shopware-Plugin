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

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\AbsolutePriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\PriceCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Adds the Printess page-count/form-field pricing surcharge (see `PrintessPriceCalculatorService`)
 * on top of a product line item's price. This is the actual, enforced checkout price - the
 * storefront's `PrintessPriceController` only ever shows a preview computed by the very same
 * calculator service, so the two can't drift apart.
 *
 * Must run after `Shopware\Core\Content\Product\Cart\ProductCartProcessor` (priority 5000), which
 * unconditionally overwrites the line item's price from its product price definition on every cart
 * recalculation - registered at priority 4950 so it always gets the last word for this pass, and
 * simply re-adds the surcharge fresh every time rather than trying to preserve it across calls.
 */
class PrintessSurchargeCartProcessor implements CartProcessorInterface
{
    /**
     * Itemized surcharge breakdown (see `PrintessPriceCalculatorService::calculateBreakdown()`),
     * stashed on the line item's payload so the storefront cart page can explain to the customer
     * why the price differs from the product's base price - see
     * `component/line-item/type/product.html.twig`.
     */
    private const PRICE_BREAKDOWN_PAYLOAD_KEY = '_printessPriceBreakdown';

    public function __construct(
        private readonly AbsolutePriceCalculator $absolutePriceCalculator,
        private readonly PrintessPriceCalculatorService $priceCalculatorService,
    ) {
    }

    public function process(CartDataCollection $data, Cart $original, Cart $toCalculate, SalesChannelContext $context, CartBehavior $behavior): void
    {
        foreach ($original->getLineItems()->filterFlatByType(LineItem::PRODUCT_LINE_ITEM_TYPE) as $lineItem) {
            if (!PrintessPriceConfigCollector::hasPrintessPricingPayload($lineItem) || $lineItem->getReferencedId() === null) {
                continue;
            }

            $priceConfigRaw = $data->get(PrintessPriceConfigCollector::dataKey($lineItem->getReferencedId()));
            $priceConfig = \is_array($priceConfigRaw) ? $priceConfigRaw : null;

            $payload = $lineItem->getPayload();
            $pageCount = (int) ($payload['_printessPageCount'] ?? 0);
            $formFieldValues = PrintessPriceConfigCollector::decodeFormFieldValues($payload['_printessPriceRelevantFormFields'] ?? null);

            $breakdown = $this->priceCalculatorService->calculateBreakdown(
                $priceConfig,
                $pageCount,
                $formFieldValues,
                $context->getContext()->getLanguageId(),
            );

            // Always overwritten, even when empty, so a stale breakdown from a previous state (e.g.
            // the price config was since removed) never lingers on the line item.
            $lineItem->setPayloadValue(self::PRICE_BREAKDOWN_PAYLOAD_KEY, $breakdown);

            $currentPrice = $lineItem->getPrice();

            if ($breakdown['total'] === 0.0 || $currentPrice === null) {
                continue;
            }

            $surchargePrice = $this->absolutePriceCalculator->calculate(
                $breakdown['total'],
                new PriceCollection([$currentPrice]),
                $context,
                $lineItem->getQuantity()
            );

            $lineItem->setPrice((new PriceCollection([$currentPrice, $surchargePrice]))->sum());
        }
    }
}
