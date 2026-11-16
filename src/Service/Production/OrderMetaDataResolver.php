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

namespace PrintessShopwareIntegration\Service\Production;

use PrintessShopwareIntegration\Service\Configuration\PrintessConfigService;
use PrintessShopwareIntegration\Service\Product\PrintessProductCustomFieldResolver;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;

/**
 * Builds the `orderMetaData` sent with the produce call: key/value pairs Printess stores on its
 * order. Dropship product definitions and distributors can use them as `{meta.<key>}` placeholders
 * (file names, FTP paths, mails, ...). Not to be confused with the produce call's `meta`, which is
 * only echoed back in the production callback and is used by the plugin itself.
 *
 * Rows are configured plugin-wide (`orderMetaData` setting) and per product (`PrintessOrderMetaData`
 * custom field) as `[{"key": "...", "value": "..."}]`; product rows override plugin rows with the
 * same key and add new ones. Values may contain `{{ variable }}` placeholders (see
 * `OrderPlaceholderResolver`); a value that ends up empty is not sent.
 */
class OrderMetaDataResolver
{
    public const PRODUCT_FIELD_NAME = 'PrintessOrderMetaData';

    /**
     * Printess resolves `{meta.<key>}` by splitting on `.`, ends a placeholder at `}` and uses `~`
     * for modifiers (`{trim~meta.key}`) - so only keys without those can actually be used there.
     */
    public const KEY_PATTERN = '/^[A-Za-z0-9_-]+$/';

    public function __construct(
        private readonly PrintessConfigService $printessConfigService,
        private readonly PrintessProductCustomFieldResolver $productCustomFieldResolver,
        private readonly OrderPlaceholderResolver $placeholderResolver,
    ) {
    }

    /**
     * @return array<string, string> key => filled-in value, only non-empty values
     */
    public function resolve(OrderEntity $order, OrderLineItemEntity $lineItem, ?OrderDeliveryEntity $delivery, Context $context): array
    {
        $templates = self::normalize($this->printessConfigService->getOrderMetaData($order->getSalesChannelId()));
        $productId = $lineItem->getProductId();

        if ($productId !== null) {
            $productRows = self::normalize($this->productCustomFieldResolver->resolve($lineItem->getProduct(), $productId, self::PRODUCT_FIELD_NAME));
            $templates = array_replace($templates, $productRows);
        }

        // Keys are always strings here (validated), but PHP turns numeric ones like "123" into int
        // array keys - cast back so the JSON object keeps them as typed.
        $result = [];

        foreach ($this->placeholderResolver->fillAll($templates, $order, $lineItem, $delivery, $context) as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }

    /**
     * Accepts the stored list or its JSON string (the custom field can be written through the API).
     * Rows with an unusable key or an empty value are dropped; a later row wins over an earlier one
     * with the same key.
     *
     * @return array<string, string> key => value text (with placeholders)
     */
    public static function normalize(mixed $value): array
    {
        if (\is_string($value) && $value !== '') {
            try {
                $value = json_decode($value, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return [];
            }
        }

        if (!\is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $row) {
            if (!\is_array($row) || !\is_string($row['key'] ?? null) || !\is_string($row['value'] ?? null)) {
                continue;
            }

            $key = trim($row['key']);

            if (preg_match(self::KEY_PATTERN, $key) !== 1 || trim($row['value']) === '') {
                continue;
            }

            $result[$key] = $row['value'];
        }

        return $result;
    }
}
