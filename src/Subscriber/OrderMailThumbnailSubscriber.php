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

use PrintessShopwareIntegration\Service\Configuration\PrintessConfigService;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\MailTemplate\Service\Event\MailBeforeValidateEvent;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Makes a personalized order line item show its actual design thumbnail
 * (`payload._printessThumbnailUrl`) instead of the product's regular cover image in order-related
 * emails (order confirmation, etc.).
 *
 * Unlike the earlier approach of patching the order-confirmation template's HTML text directly (see
 * the now-reverted `Migration1783926000AddPrintessThumbnailToOrderConfirmationMail`), this swaps the
 * underlying `cover` data on the order line item BEFORE the mail is rendered - so any Twig expression
 * reading `<loopVar>.cover.url` off that line item (`nestedItem.cover.url` in Shopware's default
 * templates, but equally `item.cover.url` or whatever a customized template's loop variable happens
 * to be named) picks it up automatically. This works for every language and survives template
 * customization, as long as the template still sources the line item image from `.cover.url`.
 *
 * Can be disabled per sales channel via {@see PrintessConfigService::isOrderMailThumbnailReplacementEnabled()}.
 */
class OrderMailThumbnailSubscriber implements EventSubscriberInterface
{
    private const THUMBNAIL_URL_PAYLOAD_KEY = '_printessThumbnailUrl';

    public function __construct(private readonly PrintessConfigService $printessConfigService)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            MailBeforeValidateEvent::class => 'onMailBeforeValidate',
        ];
    }

    public function onMailBeforeValidate(MailBeforeValidateEvent $event): void
    {
        $order = $event->getTemplateData()['order'] ?? null;

        if (!$order instanceof OrderEntity) {
            return;
        }

        if (!$this->printessConfigService->isOrderMailThumbnailReplacementEnabled($order->getSalesChannelId())) {
            return;
        }

        foreach ($order->getLineItems() ?? [] as $lineItem) {
            $this->maybeReplaceCover($lineItem);
        }
    }

    private function maybeReplaceCover(OrderLineItemEntity $lineItem): void
    {
        $thumbnailUrl = $lineItem->getPayloadValue(self::THUMBNAIL_URL_PAYLOAD_KEY);

        if (!\is_string($thumbnailUrl) || $thumbnailUrl === '') {
            return;
        }

        $thumbnail = new MediaEntity();
        $thumbnail->setId(Uuid::randomHex());
        $thumbnail->setUrl($thumbnailUrl);

        $lineItem->setCover($thumbnail);
    }
}
