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

/**
 * Resolves the dropship `productDefinitionId` for an order line item from the product's
 * `PrintessDropshippingConfig` custom field and the plugin-wide default in the Printess settings.
 *
 * Both store a single number, as in the Shopify integration:
 *
 * - `-2` -> (product only) use the plugin default.
 * - `-1` -> no dropshipping.
 * - `0` -> dropship with the settings stored on the Printess template.
 * - `> 0` -> dropship with that product definition.
 *
 * A product with nothing configured is not dropshipped; only an explicit `-2` takes the plugin
 * default. Every other value - empty, other negative numbers, anything that is not an integer, and
 * `-2` in the plugin default itself - means no dropshipping, normalized to `-1`.
 *
 * Values saved by earlier versions as JSON (`{"mode": "dropshipping"|"template",
 * "productDefinitionId": int|null}`) are still read: "template" is `0`, "dropshipping" is its
 * product definition id.
 */
class ProductionDropshippingResolver
{
    public const PRODUCT_DEFINITION_ID_PLUGIN_DEFAULT = -2;
    public const PRODUCT_DEFINITION_ID_NONE = -1;
    public const PRODUCT_DEFINITION_ID_TEMPLATE = 0;

    private const LEGACY_MODE_DROPSHIPPING = 'dropshipping';
    private const LEGACY_MODE_TEMPLATE = 'template';

    public function __construct(private readonly PrintessConfigService $printessConfigService)
    {
    }

    /**
     * The `productDefinitionId` to produce with: `0` or a positive id to dropship, `-1` not to.
     * Use `isDropshipActive()` on the result to decide.
     */
    public function resolveProductDefinitionId(mixed $productValue, ?string $salesChannelId): int
    {
        $productDefinitionId = $this->parse($productValue, true);

        if ($productDefinitionId !== self::PRODUCT_DEFINITION_ID_PLUGIN_DEFAULT) {
            return $productDefinitionId;
        }

        return $this->parse($this->printessConfigService->getDropshippingConfig($salesChannelId), false);
    }

    /**
     * The one place that decides whether a product definition id means dropshipping: `0` (the
     * template's settings) and every positive id do, everything else doesn't.
     */
    public function isDropshipActive(int $productDefinitionId): bool
    {
        return $productDefinitionId >= self::PRODUCT_DEFINITION_ID_TEMPLATE;
    }

    /**
     * Reduces a stored product definition id to an int: integers and integer strings (`"12"`,
     * `" 0 "`) keep their value, negative ones and everything else - null, `""`, other strings,
     * fractions, arrays - become `-1`.
     */
    public function normalizeProductDefinitionId(mixed $value): int
    {
        $value = $this->toInt($value);

        if ($value === null || $value < 0) {
            return self::PRODUCT_DEFINITION_ID_NONE;
        }

        return $value;
    }

    /**
     * A stored value as a product definition id. `-2` survives only for a product, where it means
     * "use the plugin default".
     */
    private function parse(mixed $value, bool $allowPluginDefault): int
    {
        $number = $this->toInt($value);

        if ($number === self::PRODUCT_DEFINITION_ID_PLUGIN_DEFAULT && $allowPluginDefault) {
            return $number;
        }

        if ($number !== null) {
            return $this->normalizeProductDefinitionId($number);
        }

        return $this->parseLegacyJson($value);
    }

    private function parseLegacyJson(mixed $value): int
    {
        if (!\is_string($value)) {
            return self::PRODUCT_DEFINITION_ID_NONE;
        }

        try {
            $decoded = json_decode($value, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::PRODUCT_DEFINITION_ID_NONE;
        }

        return match (\is_array($decoded) ? ($decoded['mode'] ?? null) : null) {
            self::LEGACY_MODE_TEMPLATE => self::PRODUCT_DEFINITION_ID_TEMPLATE,
            self::LEGACY_MODE_DROPSHIPPING => $this->normalizeProductDefinitionId($decoded['productDefinitionId'] ?? null),
            default => self::PRODUCT_DEFINITION_ID_NONE,
        };
    }

    /**
     * Integers, integer strings (trimmed) and whole floats as int; anything else as null.
     */
    private function toInt(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value;
        }

        if (\is_float($value)) {
            return floor($value) === $value ? (int) $value : null;
        }

        if (\is_string($value) && preg_match('/^[+-]?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
    }
}
