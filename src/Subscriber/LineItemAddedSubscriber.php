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

use Shopware\Core\Checkout\Cart\Event\BeforeLineItemAddedEvent;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The storefront buy-widget-form ships hidden inputs - `lineItems[{id}][_printessSaveToken]`,
 * `[_printessThumbnailUrl]`, `[_printessPageCount]`, `[_printessPriceRelevantFormFields]` and
 * `[_printessSlimUiItem]` - only for personalized products. Shopware's line-item-add request
 * handling does not automatically map arbitrary custom keys into the line item's payload, so this
 * copies them across manually. The line item also gets a fresh id so two different personalized
 * designs of the same product don't get merged into a single stacked line item, which would
 * silently discard one of the two save tokens.
 */
class LineItemAddedSubscriber implements EventSubscriberInterface
{
    private const SAVE_TOKEN_KEY = '_printessSaveToken';
    private const THUMBNAIL_URL_KEY = '_printessThumbnailUrl';
    private const PAGE_COUNT_KEY = '_printessPageCount';
    private const PRICE_RELEVANT_FORM_FIELDS_KEY = '_printessPriceRelevantFormFields';
    private const SLIM_UI_ITEM_KEY = '_printessSlimUiItem';

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BeforeLineItemAddedEvent::class => 'onLineItemAdded',
        ];
    }

    public function onLineItemAdded(BeforeLineItemAddedEvent $event): void
    {
        $request = $this->requestStack->getCurrentRequest();

        if ($request === null) {
            return;
        }

        $lineItem = $event->getLineItem();
        $submittedLineItems = $request->get('lineItems');

        if (!\is_array($submittedLineItems) || !\is_array($submittedLineItems[$lineItem->getId()] ?? null)) {
            return;
        }

        $submitted = $submittedLineItems[$lineItem->getId()];

        // Recorded independently of the save-token check below since a future SlimUi add-to-cart
        // flow may not go through the same save-token round trip the full editor uses.
        if (($submitted[self::SLIM_UI_ITEM_KEY] ?? null) === '1') {
            $lineItem->setPayloadValue(self::SLIM_UI_ITEM_KEY, true);
        }

        $saveToken = $submitted[self::SAVE_TOKEN_KEY] ?? null;
        $thumbnailUrl = $submitted[self::THUMBNAIL_URL_KEY] ?? null;

        if (!\is_string($saveToken) || $saveToken === '') {
            return;
        }

        $lineItem->setPayloadValue(self::SAVE_TOKEN_KEY, $saveToken);

        if (\is_string($thumbnailUrl) && $thumbnailUrl !== '') {
            $lineItem->setPayloadValue(self::THUMBNAIL_URL_KEY, $thumbnailUrl);
        }

        $pageCount = $submitted[self::PAGE_COUNT_KEY] ?? null;

        if (\is_string($pageCount) && $pageCount !== '') {
            $lineItem->setPayloadValue(self::PAGE_COUNT_KEY, (int) $pageCount);
        }

        $priceRelevantFormFields = $submitted[self::PRICE_RELEVANT_FORM_FIELDS_KEY] ?? null;

        if (\is_string($priceRelevantFormFields) && $priceRelevantFormFields !== '') {
            $lineItem->setPayloadValue(self::PRICE_RELEVANT_FORM_FIELDS_KEY, $priceRelevantFormFields);
        }

        // Give the line item a unique id so adding another personalized item of the same product
        // creates its own separate line item instead of being merged/grouped with an existing one.
        // Quantity on an already-added line item stays freely changeable (stackable is untouched).
        $lineItem->setId(Uuid::randomHex());
    }
}
