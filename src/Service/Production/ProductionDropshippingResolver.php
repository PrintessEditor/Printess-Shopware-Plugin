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
 * Resolves which dropshipping configuration applies to a given order line item's product, by
 * combining the product-level `PrintessDropshippingConfig` custom field with the plugin-wide
 * default configured in the Printess settings:
 *
 * - Neither configured -> no dropshipping.
 * - Only the plugin default is configured -> use the plugin default.
 * - Only the product is configured -> use the product's configuration.
 * - Both are configured -> the product's configuration wins.
 *
 * In other words: the product's configuration is used whenever it is anything other than "no
 * dropshipping"; the plugin default is only a fallback for products that don't configure it.
 */
class ProductionDropshippingResolver
{
    public const MODE_NONE = 'none';
    public const MODE_DROPSHIPPING = 'dropshipping';
    public const MODE_TEMPLATE = 'template';

    public function __construct(private readonly PrintessConfigService $printessConfigService)
    {
    }

    /**
     * @return array{mode: string, productDefinitionId: int|null}
     */
    public function resolve(?string $productDropshippingConfigJson, ?string $salesChannelId): array
    {
        $productConfig = $this->parse($productDropshippingConfigJson);

        if ($productConfig['mode'] !== self::MODE_NONE) {
            return $productConfig;
        }

        return $this->parse($this->printessConfigService->getDropshippingConfig($salesChannelId));
    }

    /**
     * Translates a resolved config into the `productDefinitionId` value expected by Printess's
     * `/dropship/produce` endpoint, or `null` when no dropshipping should be used at all. Per
     * product requirements: "template" mode (dropshipping configured directly on the Printess
     * template) is signalled to Printess with the sentinel product definition id `-1`.
     */
    public function resolveProductDefinitionId(array $resolvedConfig): ?int
    {
        return match ($resolvedConfig['mode']) {
            self::MODE_DROPSHIPPING => $resolvedConfig['productDefinitionId'],
            self::MODE_TEMPLATE => -1,
            default => null,
        };
    }

    /**
     * @return array{mode: string, productDefinitionId: int|null}
     */
    private function parse(?string $json): array
    {
        if ($json === null || $json === '') {
            return ['mode' => self::MODE_NONE, 'productDefinitionId' => null];
        }

        try {
            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['mode' => self::MODE_NONE, 'productDefinitionId' => null];
        }

        $mode = \is_array($decoded) ? ($decoded['mode'] ?? null) : null;

        if (!\in_array($mode, [self::MODE_DROPSHIPPING, self::MODE_TEMPLATE], true)) {
            return ['mode' => self::MODE_NONE, 'productDefinitionId' => null];
        }

        $productDefinitionId = $decoded['productDefinitionId'] ?? null;

        return [
            'mode' => $mode,
            'productDefinitionId' => \is_int($productDefinitionId) ? $productDefinitionId : null,
        ];
    }
}
