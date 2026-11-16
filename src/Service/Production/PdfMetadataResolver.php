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
 * Builds the `PDF_Title`, `PDF_Subject` and `PDF_Author` form field values Printess writes into the
 * produced PDF's document properties.
 *
 * Each value is a text with `{{ variable }}` placeholders (see `OrderPlaceholderResolver`), configured
 * plugin-wide (`pdfMetadata` setting) and optionally per product (`PrintessPdfMetadata` custom
 * field); a non-empty product value wins over the plugin value for that one entry. The placeholders
 * are filled in when production starts.
 *
 * An entry that ends up empty is not sent, so the template's own value for that form field applies.
 */
class PdfMetadataResolver
{
    public const PRODUCT_FIELD_NAME = 'PrintessPdfMetadata';

    /**
     * Setting key => form field name sent to Printess.
     */
    public const FORM_FIELDS = [
        'title' => 'PDF_Title',
        'subject' => 'PDF_Subject',
        'author' => 'PDF_Author',
    ];

    public function __construct(
        private readonly PrintessConfigService $printessConfigService,
        private readonly PrintessProductCustomFieldResolver $productCustomFieldResolver,
        private readonly OrderPlaceholderResolver $placeholderResolver,
    ) {
    }

    /**
     * @return array<string, string> form field name (`PDF_Title`, ...) => value, only non-empty values
     */
    public function resolve(OrderEntity $order, OrderLineItemEntity $lineItem, ?OrderDeliveryEntity $delivery, Context $context): array
    {
        $result = [];

        foreach ($this->placeholderResolver->fillAll($this->templates($order, $lineItem), $order, $lineItem, $delivery, $context) as $key => $value) {
            $result[self::FORM_FIELDS[$key]] = $value;
        }

        return $result;
    }

    /**
     * The text per entry: the product's own if it has one, otherwise the plugin's.
     *
     * @return array<string, string> setting key (`title`, ...) => text, only non-empty ones
     */
    private function templates(OrderEntity $order, OrderLineItemEntity $lineItem): array
    {
        $pluginValues = self::normalize($this->printessConfigService->getPdfMetadata($order->getSalesChannelId()));
        $productId = $lineItem->getProductId();
        $productValues = $productId !== null
            ? self::normalize($this->productCustomFieldResolver->resolve($lineItem->getProduct(), $productId, self::PRODUCT_FIELD_NAME))
            : [];

        return array_filter(
            $productValues + $pluginValues,
            static fn (string $value): bool => trim($value) !== ''
        );
    }

    /**
     * Accepts the stored object or its JSON string (the custom field can be written through the API).
     *
     * @return array<string, string>
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

        foreach (array_keys(self::FORM_FIELDS) as $key) {
            if (\is_string($value[$key] ?? null) && trim($value[$key]) !== '') {
                $result[$key] = $value[$key];
            }
        }

        return $result;
    }
}
