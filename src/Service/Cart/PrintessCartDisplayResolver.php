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

use PrintessShopwareIntegration\Service\Pricing\PrintessLabelTranslation;
use PrintessShopwareIntegration\Service\Pricing\PrintessPriceConfigCollector;

/**
 * Turns a product's `PrintessCartDisplayFields` setting plus a line item's payload into the
 * "Number Of Tiles: 10" rows shown under the item in the cart, checkout and order views.
 *
 * The value comes from the price-relevant form field values the editor reported
 * (`_printessPriceRelevantFormFields`) - only price-relevant fields are reported at all, which is
 * why the template check warns about a configured field that isn't. SlimUi only reports fields the
 * shopper actually changed, so a field still at its initial value falls back to the variant option
 * of the same name (the form field typically drives that variant in the first place).
 *
 * Shared by `PrintessCartDisplayCollector` (cart, on every calculation) and the admin order line
 * item editor's refresh endpoint (an already-placed order, which never runs through the cart).
 */
class PrintessCartDisplayResolver
{
    public const CONFIG_FIELD_NAME = 'PrintessCartDisplayFields';
    public const HIDE_QUANTITY_FIELD_NAME = 'PrintessHideQuantitySelector';

    public const DISPLAY_PAYLOAD_KEY = '_printessCartDisplay';
    public const HIDE_QUANTITY_PAYLOAD_KEY = '_printessHideQuantity';
    public const PRICE_RELEVANT_FORM_FIELDS_PAYLOAD_KEY = '_printessPriceRelevantFormFields';

    /**
     * The stored setting, reduced to rows that name a form field. The custom field is JSON-typed but
     * also editable by hand in Shopware's generic custom field UI, so anything else is dropped.
     *
     * @return list<array{formField: string, label: mixed}>
     */
    public static function normalizeConfig(mixed $raw): array
    {
        if (\is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        if (!\is_array($raw)) {
            return [];
        }

        $rows = [];

        foreach ($raw as $entry) {
            $formField = \is_array($entry) && \is_string($entry['formField'] ?? null) ? trim($entry['formField']) : '';

            if ($formField === '') {
                continue;
            }

            $rows[] = ['formField' => $formField, 'label' => $entry['label'] ?? null];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed>|null $payload the line item's payload
     *
     * @return list<array{label: string, value: string}>
     */
    public function buildRows(mixed $config, ?array $payload, string $languageId): array
    {
        $payload ??= [];
        $formFieldValues = PrintessPriceConfigCollector::decodeFormFieldValues($payload[self::PRICE_RELEVANT_FORM_FIELDS_PAYLOAD_KEY] ?? null);
        $variantOptions = \is_array($payload['options'] ?? null) ? $payload['options'] : [];
        $rows = [];

        foreach (self::normalizeConfig($config) as $entry) {
            $label = PrintessLabelTranslation::resolve($entry['label'], $languageId);

            if ($label === null) {
                continue;
            }

            $value = $this->formFieldValue($formFieldValues, $entry['formField'])
                ?? $this->variantOptionValue($variantOptions, $entry['formField']);

            if ($value === null) {
                continue;
            }

            $rows[] = ['label' => $label, 'value' => $value];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $formFieldValues
     */
    private function formFieldValue(array $formFieldValues, string $formField): ?string
    {
        $value = $formFieldValues[$formField] ?? null;

        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (!\is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }

    /**
     * @param array<mixed> $variantOptions `[{group, option}]`, as set by Shopware's `ProductCartProcessor`
     */
    private function variantOptionValue(array $variantOptions, string $formField): ?string
    {
        foreach ($variantOptions as $option) {
            if (!\is_array($option) || !\is_string($option['group'] ?? null) || !\is_scalar($option['option'] ?? null)) {
                continue;
            }

            if (mb_strtolower(trim($option['group'])) === mb_strtolower($formField) && trim((string) $option['option']) !== '') {
                return trim((string) $option['option']);
            }
        }

        return null;
    }
}
