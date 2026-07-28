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

namespace PrintessShopwareIntegration\Service\Product;

use PrintessShopwareIntegration\Service\Configuration\PrintessConfigService;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

/**
 * Resolves one of the plugin's product-level custom fields (`PrintessTemplateName`,
 * `PrintessFormFields`, `PrintessMergeTemplates`, `PrintessPrintSettingName`,
 * `PrintessDropshippingConfig`, `PrintessPriceConfig`, `PrintessBookSettings`,
 * `PrintessPhotobookTheme`, `PrintessBookInsidePageCount`, `PrintessBookInsidePageCountEnabled`,
 * `PrintessMagicPhotobookEnabled`, `PrintessSlimUiEnabled`), honouring the
 * `perLanguageProductSettings` plugin setting.
 *
 * Shopware stores product custom fields per language (the field is a translated column on the
 * product entity) - by default (setting disabled), this resolver ignores whatever language the
 * calling context/loaded entity happens to be in and always re-reads the shop's default (system)
 * language value instead, so there's exactly one Printess configuration per product regardless of
 * which language an admin happens to be editing in, or which language a given order/customer is in.
 * Only when the setting is enabled does this fall back to whatever the already-loaded product
 * entity resolved for its own context's language.
 */
class PrintessProductCustomFieldResolver
{
    /**
     * All product custom fields managed by this plugin - see `ProductCustomFieldsInstaller`.
     */
    public const MANAGED_FIELD_NAMES = [
        'PrintessTemplateName',
        'PrintessFormFields',
        'PrintessMergeTemplates',
        'PrintessPrintSettingName',
        'PrintessDropshippingConfig',
        'PrintessPriceConfig',
        'PrintessBookSettings',
        'PrintessPhotobookTheme',
        'PrintessBookInsidePageCount',
        'PrintessBookInsidePageCountEnabled',
        'PrintessMagicPhotobookEnabled',
        'PrintessSlimUiEnabled',
    ];

    public function __construct(
        private readonly PrintessConfigService $printessConfigService,
        private readonly EntityRepository $productRepository,
    ) {
    }

    public function resolve(?ProductEntity $product, string $productId, string $fieldName): mixed
    {
        if ($this->printessConfigService->isPerLanguageProductSettingsEnabled()) {
            return $product?->getCustomFieldsValue($fieldName);
        }

        return $this->resolveDefaultLanguage($productId, $fieldName);
    }

    /**
     * The effective (inheritance-aware) default-language value, regardless of what the
     * `perLanguageProductSettings` setting is currently set to - used to show/edit "the" value in
     * the admin when per-language mode is off, irrespective of which language tab is active.
     */
    public function resolveDefaultLanguage(string $productId, string $fieldName): mixed
    {
        $context = Context::createDefaultContext();

        $product = $context->enableInheritance(
            fn (Context $inheritanceContext) => $this->productRepository
                ->search(new Criteria([$productId]), $inheritanceContext)
                ->getEntities()
                ->first()
        );

        return $product?->getCustomFieldsValue($fieldName);
    }

    /**
     * Writes the given managed fields onto the product's own default-language translation,
     * merging with (rather than replacing) whatever custom fields already exist there. Reads the
     * existing value without inheritance first, so a parent product's fields never get copied onto
     * a variant's own row just because this happened to also read them for merging.
     *
     * @param array<string, mixed> $values keyed by a subset of self::MANAGED_FIELD_NAMES
     */
    public function saveDefaultLanguage(string $productId, array $values): void
    {
        $context = Context::createDefaultContext();

        $existing = $this->productRepository
            ->search(new Criteria([$productId]), $context)
            ->getEntities()
            ->first();

        $customFields = array_merge($existing?->getCustomFields() ?? [], $values);

        $this->productRepository->update([
            ['id' => $productId, 'customFields' => $customFields],
        ], $context);
    }
}
