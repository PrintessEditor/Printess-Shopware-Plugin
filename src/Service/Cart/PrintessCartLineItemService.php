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

use PrintessShopwareIntegration\Service\Product\ProductConfiguratorGroupNameResolver;
use PrintessShopwareIntegration\Service\Product\ProductOptionFormFieldService;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Product\SalesChannel\FindVariant\AbstractFindProductVariantRoute;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Backs two storefront flows for personalized line items:
 *
 * - The cart item editor page: finds an editable (personalized) line item still in the CURRENT
 *   CART, builds the data the editor page needs to track configurator/price changes, and replaces
 *   the line item with an updated one once the shopper re-designs it.
 * - The order "reorder" flow (see `PrintessOrderLineItemController`): the same editor experience,
 *   but sourced from an already-placed ORDER's line item instead. An order must never be mutated,
 *   so this only ever ADDS a brand-new line item to the current cart - see
 *   `addLineItemFromOrderLineItem()`.
 *
 * Shopware's cart API has no supported way to change an existing line item's `referencedId`
 * (its product/variant) in place - the pattern used here (also used by other Printess shop
 * integrations) is to add a new line item cloned from the original, preserving whatever other
 * plugins may have set on it, with the updated save token/thumbnail/variant, then remove the
 * original. `LineItem::replacePayload()` (called by `ProductCartProcessor` on every recalculation,
 * including the one triggered by `CartService::add()`) merges rather than replaces the payload
 * array, so custom keys like ours survive.
 */
class PrintessCartLineItemService
{
    private const SAVE_TOKEN_PAYLOAD_KEY = '_printessSaveToken';
    private const THUMBNAIL_URL_PAYLOAD_KEY = '_printessThumbnailUrl';
    private const PAGE_COUNT_PAYLOAD_KEY = '_printessPageCount';
    private const PRICE_RELEVANT_FORM_FIELDS_PAYLOAD_KEY = '_printessPriceRelevantFormFields';

    public function __construct(
        private readonly CartService $cartService,
        private readonly AbstractFindProductVariantRoute $findProductVariantRoute,
        private readonly EntityRepository $productRepository,
        private readonly ProductConfiguratorGroupNameResolver $configuratorGroupNameResolver,
        private readonly ProductOptionFormFieldService $productOptionFormFieldService,
    ) {
    }

    public function findEditableLineItem(Cart $cart, string $lineItemId): ?LineItem
    {
        $lineItem = $cart->getLineItems()->get($lineItemId);

        if ($lineItem === null || $lineItem->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
            return null;
        }

        $saveToken = $lineItem->getPayloadValue(self::SAVE_TOKEN_PAYLOAD_KEY);

        if (!\is_string($saveToken) || $saveToken === '') {
            return null;
        }

        return $lineItem;
    }

    /**
     * Same as `findEditableLineItem()`, but for an already-placed order's line item instead of a
     * cart line item - used by the order-history "reorder" flow.
     */
    public function findEditableOrderLineItem(OrderEntity $order, string $lineItemId): ?OrderLineItemEntity
    {
        $lineItem = $order->getLineItems()?->get($lineItemId);

        if ($lineItem === null || $lineItem->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
            return null;
        }

        $saveToken = $lineItem->getPayloadValue(self::SAVE_TOKEN_PAYLOAD_KEY);

        if (!\is_string($saveToken) || $saveToken === '') {
            return null;
        }

        return $lineItem;
    }

    /**
     * @return array{
     *     saveToken: string,
     *     thumbnailUrl: string|null,
     *     parentProductId: string,
     *     configuratorOptions: list<array{groupId: string, optionId: string, name: string, value: string}>,
     *     currentSelection: array<string, string>,
     * }
     */
    public function buildEditorContext(LineItem $lineItem, SalesChannelContext $context): array
    {
        return $this->buildEditorContextFromPayload((string) $lineItem->getReferencedId(), $lineItem->getPayload(), $context);
    }

    /**
     * Same as `buildEditorContext()`, but for an already-placed order's line item. Both `LineItem`
     * and `OrderLineItemEntity` expose the same `getReferencedId()`/`getPayload()` shape, so the
     * actual context-building logic is shared via `buildEditorContextFromPayload()`.
     *
     * @return array{
     *     saveToken: string,
     *     thumbnailUrl: string|null,
     *     parentProductId: string,
     *     configuratorOptions: list<array{groupId: string, optionId: string, name: string, value: string}>,
     *     currentSelection: array<string, string>,
     * }
     */
    public function buildEditorContextFromOrderLineItem(OrderLineItemEntity $lineItem, SalesChannelContext $context): array
    {
        return $this->buildEditorContextFromPayload((string) $lineItem->getReferencedId(), $lineItem->getPayload(), $context);
    }

    /**
     * @param array<string, mixed>|null $payload
     *
     * @return array{
     *     saveToken: string,
     *     thumbnailUrl: string|null,
     *     parentProductId: string,
     *     configuratorOptions: list<array{groupId: string, optionId: string, name: string, value: string}>,
     *     currentSelection: array<string, string>,
     * }
     */
    private function buildEditorContextFromPayload(string $productId, ?array $payload, SalesChannelContext $context): array
    {
        $parentProductId = $this->resolveParentProductId($productId, $context->getContext());

        $optionIds = $this->configuratorGroupNameResolver->getConfiguratorOptionIds($productId, $context->getContext());
        $configuratorOptions = $this->productOptionFormFieldService->getConfiguratorOptionsMapForOptionIds($optionIds);

        $ownOptionIds = \is_array($payload['optionIds'] ?? null) ? $payload['optionIds'] : [];
        $currentSelection = $this->resolveCurrentSelection($ownOptionIds, $configuratorOptions);

        $thumbnailUrl = $payload[self::THUMBNAIL_URL_PAYLOAD_KEY] ?? null;

        return [
            'saveToken' => (string) ($payload[self::SAVE_TOKEN_PAYLOAD_KEY] ?? ''),
            'thumbnailUrl' => \is_string($thumbnailUrl) && $thumbnailUrl !== '' ? $thumbnailUrl : null,
            'parentProductId' => $parentProductId,
            'configuratorOptions' => $configuratorOptions,
            'currentSelection' => $currentSelection,
        ];
    }

    /**
     * @param array<string, string> $options group id => option id, as resolved by the editor page's
     *                                        own form-field-change tracking; empty if never changed
     * @param array<string, string> $priceRelevantFormFields name => value, as reported by the
     *                                                        editor's `priceChangeCallback`, for the
     *                                                        custom pricing surcharge (see
     *                                                        `PrintessPriceCalculatorService`)
     */
    public function replaceLineItem(
        Cart $cart,
        LineItem $original,
        string $saveToken,
        ?string $thumbnailUrl,
        array $options,
        int $pageCount,
        array $priceRelevantFormFields,
        SalesChannelContext $context,
    ): Cart {
        $referencedId = $this->resolveVariantId((string) $original->getReferencedId(), $options, $context);

        $replacement = clone $original;
        $replacement->setId(Uuid::randomHex());
        $replacement->setReferencedId($referencedId);

        // `ProductCartProcessor` skips re-fetching/re-enriching a line item (label, price, cover,
        // payload.productNumber, ...) whenever its inherited `dataTimestamp`/`dataContextHash`
        // already look up to date - which, copied over unchanged from the original via `clone`,
        // would make it treat this replacement as already fully enriched for the *old* variant
        // and never actually process the new one. Clearing both forces a fresh fetch/enrich.
        $replacement->setDataTimestamp(null);
        $replacement->setDataContextHash(null);

        $payload = $replacement->getPayload();
        $payload[self::SAVE_TOKEN_PAYLOAD_KEY] = $saveToken;

        if ($thumbnailUrl !== null && $thumbnailUrl !== '') {
            $payload[self::THUMBNAIL_URL_PAYLOAD_KEY] = $thumbnailUrl;
        }

        $payload[self::PAGE_COUNT_PAYLOAD_KEY] = $pageCount;
        $payload[self::PRICE_RELEVANT_FORM_FIELDS_PAYLOAD_KEY] = $priceRelevantFormFields;

        $replacement->setPayload($payload);

        $cart = $this->cartService->add($cart, $replacement, $context);

        return $this->cartService->remove($cart, $original->getId(), $context);
    }

    /**
     * Adds a brand-new cart line item cloned from an already-placed order's personalized line item -
     * used by the order-history "reorder" flow. Unlike `replaceLineItem()` (which edits an item
     * still sitting in the current cart), this never touches or removes anything: an already-placed
     * order must stay exactly as it was, so re-designing it from the order view can only ever add a
     * new item to the current cart.
     *
     * @param array<string, string> $options
     * @param array<string, string> $priceRelevantFormFields
     */
    public function addLineItemFromOrderLineItem(
        Cart $cart,
        OrderLineItemEntity $original,
        string $saveToken,
        ?string $thumbnailUrl,
        array $options,
        int $pageCount,
        array $priceRelevantFormFields,
        SalesChannelContext $context,
    ): Cart {
        $referencedId = $this->resolveVariantId((string) $original->getReferencedId(), $options, $context);
        $lineItem = $this->buildPersonalizedLineItem($referencedId, $saveToken, $thumbnailUrl, $pageCount, $priceRelevantFormFields);

        return $this->cartService->add($cart, $lineItem, $context);
    }

    /**
     * Adds a brand-new cart line item for a design that was saved to the customer's account while
     * they were anonymous (see the `shopLoginCallback`/"resume saved design" flow) - there's no
     * existing `LineItem`/`OrderLineItemEntity` to clone from here, just the raw variant id and
     * context gathered on the product page before the login/registration hand-off.
     *
     * @param array<string, string> $options
     * @param array<string, string> $priceRelevantFormFields
     */
    public function addLineItemFromPendingDesign(
        Cart $cart,
        string $variantProductId,
        string $saveToken,
        ?string $thumbnailUrl,
        array $options,
        int $pageCount,
        array $priceRelevantFormFields,
        SalesChannelContext $context,
    ): Cart {
        $referencedId = $this->resolveVariantId($variantProductId, $options, $context);
        $lineItem = $this->buildPersonalizedLineItem($referencedId, $saveToken, $thumbnailUrl, $pageCount, $priceRelevantFormFields);

        return $this->cartService->add($cart, $lineItem, $context);
    }

    /**
     * @param array<string, string> $priceRelevantFormFields
     */
    private function buildPersonalizedLineItem(
        string $referencedId,
        string $saveToken,
        ?string $thumbnailUrl,
        int $pageCount,
        array $priceRelevantFormFields,
    ): LineItem {
        $lineItem = new LineItem(Uuid::randomHex(), LineItem::PRODUCT_LINE_ITEM_TYPE, $referencedId, 1);
        $lineItem->setStackable(true);
        $lineItem->setRemovable(true);

        $payload = [self::SAVE_TOKEN_PAYLOAD_KEY => $saveToken];

        if ($thumbnailUrl !== null && $thumbnailUrl !== '') {
            $payload[self::THUMBNAIL_URL_PAYLOAD_KEY] = $thumbnailUrl;
        }

        $payload[self::PAGE_COUNT_PAYLOAD_KEY] = $pageCount;
        $payload[self::PRICE_RELEVANT_FORM_FIELDS_PAYLOAD_KEY] = $priceRelevantFormFields;

        $lineItem->setPayload($payload);

        return $lineItem;
    }

    /**
     * @param array<int, string> $ownOptionIds
     * @param list<array{groupId: string, optionId: string, name: string, value: string}> $configuratorOptions
     *
     * @return array<string, string>
     */
    private function resolveCurrentSelection(array $ownOptionIds, array $configuratorOptions): array
    {
        $selection = [];

        foreach ($configuratorOptions as $option) {
            if (\in_array($option['optionId'], $ownOptionIds, true)) {
                $selection[$option['groupId']] = $option['optionId'];
            }
        }

        return $selection;
    }

    /**
     * @param array<string, string> $options
     */
    private function resolveVariantId(string $originalProductId, array $options, SalesChannelContext $context): string
    {
        if ($options === []) {
            return $originalProductId;
        }

        try {
            // `FindProductVariantRoute` searches by `product.parentId`, so it must be given the
            // parent id, not the currently referenced variant's own id.
            $parentProductId = $this->resolveParentProductId($originalProductId, $context->getContext());

            $variantRequest = (new Request())->duplicate(['switchedGroup' => null, 'options' => $options]);
            $response = $this->findProductVariantRoute->load($parentProductId, $variantRequest, $context);

            return $response->getFoundCombination()->getVariantId();
        } catch (\Throwable) {
            // Keep the original variant if no matching combination is found.
            return $originalProductId;
        }
    }

    private function resolveParentProductId(string $productId, Context $context): string
    {
        $product = $this->productRepository->search(new Criteria([$productId]), $context)->getEntities()->first();

        return $product?->getParentId() ?? $productId;
    }
}
