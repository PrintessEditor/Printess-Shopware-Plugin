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

namespace PrintessShopwareIntegration\Service\Cart;

use PrintessShopwareIntegration\Service\Product\PrintessProductCustomFieldResolver;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartDataCollectorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Applies the product's "Shopping cart" settings (Printess tab) to every personalized line item,
 * on every cart calculation:
 *
 * - `PrintessHideQuantitySelector`: locks the item to quantity 1 and makes it non-stackable, so the
 *   quantity can't be changed through any channel (Store API, an older cart, the edit/reorder
 *   flows), and flags it (`_printessHideQuantity`) so the storefront hides the quantity column.
 *   Turning the setting off again makes items this collector locked stackable again.
 * - `PrintessCartDisplayFields`: rebuilds the `_printessCartDisplay` rows ("Number Of Tiles: 10")
 *   from the item's current form field values - so they follow every re-design of the item. The
 *   payload is copied into the order line item, so confirm/finish/order views and mails get the
 *   rows in the language the order was placed in.
 *
 * Runs after `ProductCartProcessor`'s own collect (priority 5000), which (re-)enriches the
 * payload's `options` with the current variant - the display rows' fallback value - and has
 * already applied its own min/max/step quantity corrections.
 */
class PrintessCartDisplayCollector implements CartDataCollectorInterface
{
    private const SAVE_TOKEN_PAYLOAD_KEY = '_printessSaveToken';

    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly PrintessProductCustomFieldResolver $productCustomFieldResolver,
        private readonly PrintessCartDisplayResolver $cartDisplayResolver,
    ) {
    }

    public function collect(CartDataCollection $data, Cart $original, SalesChannelContext $context, CartBehavior $behavior): void
    {
        $lineItems = [];

        foreach ($original->getLineItems()->filterFlatByType(LineItem::PRODUCT_LINE_ITEM_TYPE) as $lineItem) {
            $saveToken = $lineItem->getPayloadValue(self::SAVE_TOKEN_PAYLOAD_KEY);

            if (\is_string($saveToken) && $saveToken !== '' && $lineItem->getReferencedId() !== null) {
                $lineItems[] = $lineItem;
            }
        }

        if ($lineItems === []) {
            return;
        }

        $productIds = array_values(array_unique(array_map(static fn (LineItem $lineItem) => (string) $lineItem->getReferencedId(), $lineItems)));
        $products = $this->productRepository->search(new Criteria($productIds), $context->getContext())->getEntities();
        $settings = [];

        foreach ($productIds as $productId) {
            $settings[$productId] = $this->productCustomFieldResolver->resolveMany(
                $products->get($productId),
                $productId,
                [PrintessCartDisplayResolver::HIDE_QUANTITY_FIELD_NAME, PrintessCartDisplayResolver::CONFIG_FIELD_NAME],
            );
        }

        foreach ($lineItems as $lineItem) {
            $productSettings = $settings[(string) $lineItem->getReferencedId()] ?? [];

            $this->applyQuantityLock($lineItem, ($productSettings[PrintessCartDisplayResolver::HIDE_QUANTITY_FIELD_NAME] ?? null) === true);

            $rows = $this->cartDisplayResolver->buildRows(
                $productSettings[PrintessCartDisplayResolver::CONFIG_FIELD_NAME] ?? null,
                $lineItem->getPayload(),
                $context->getLanguageId(),
            );

            if ($rows !== []) {
                $lineItem->setPayloadValue(PrintessCartDisplayResolver::DISPLAY_PAYLOAD_KEY, $rows);
            } elseif ($lineItem->hasPayloadValue(PrintessCartDisplayResolver::DISPLAY_PAYLOAD_KEY)) {
                $lineItem->removePayloadValue(PrintessCartDisplayResolver::DISPLAY_PAYLOAD_KEY);
            }
        }
    }

    private function applyQuantityLock(LineItem $lineItem, bool $hideQuantity): void
    {
        if ($hideQuantity) {
            if ($lineItem->getQuantity() !== 1) {
                // `setQuantity()` refuses non-stackable items.
                $lineItem->setStackable(true);
                $lineItem->setQuantity(1);
            }

            $lineItem->setStackable(false);
            $lineItem->setPayloadValue(PrintessCartDisplayResolver::HIDE_QUANTITY_PAYLOAD_KEY, true);

            return;
        }

        if ($lineItem->hasPayloadValue(PrintessCartDisplayResolver::HIDE_QUANTITY_PAYLOAD_KEY)) {
            $lineItem->setStackable(true);
            $lineItem->removePayloadValue(PrintessCartDisplayResolver::HIDE_QUANTITY_PAYLOAD_KEY);
        }
    }
}
