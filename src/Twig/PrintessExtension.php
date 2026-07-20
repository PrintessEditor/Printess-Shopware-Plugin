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

namespace PrintessShopwareIntegration\Twig;

use PrintessShopwareIntegration\Service\Configuration\PrintessConfigService;
use PrintessShopwareIntegration\Service\Customer\PrintessCustomerShopUserIdResolver;
use PrintessShopwareIntegration\Service\Customer\PrintessCustomerVariableResolver;
use PrintessShopwareIntegration\Service\Product\PrintessProductCustomFieldResolver;
use PrintessShopwareIntegration\Service\Product\PrintessProductPropertyResolver;
use PrintessShopwareIntegration\Service\Product\ProductOptionFormFieldService;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Property\PropertyGroupCollection;
use Shopware\Core\Framework\Context;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class PrintessExtension extends AbstractExtension
{
    /**
     * Prefix marking a preselected form field's `variable` as a property group id (see
     * `PrintessProductPropertyResolver`) rather than one of `PrintessCustomerVariableResolver::VARIABLE_KEYS`.
     */
    private const PROPERTY_VARIABLE_PREFIX = 'property.';

    public function __construct(
        private readonly PrintessConfigService $printessConfigService,
        private readonly ProductOptionFormFieldService $productOptionFormFieldService,
        private readonly PrintessProductCustomFieldResolver $productCustomFieldResolver,
        private readonly PrintessCustomerVariableResolver $customerVariableResolver,
        private readonly PrintessProductPropertyResolver $productPropertyResolver,
        private readonly PrintessCustomerShopUserIdResolver $customerShopUserIdResolver,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('printess_editor_loader_url', $this->getEditorLoaderScriptUrl(...)),
            new TwigFunction('printess_shop_token', $this->getShopToken(...)),
            new TwigFunction('printess_default_theme', $this->getDefaultTheme(...)),
            new TwigFunction('printess_editor_language', $this->getEditorLanguage(...)),
            new TwigFunction('printess_basket_thumbnail_max_width', $this->getBasketThumbnailMaxWidth(...)),
            new TwigFunction('printess_basket_thumbnail_max_height', $this->getBasketThumbnailMaxHeight(...)),
            new TwigFunction('printess_product_option_form_fields', $this->getProductOptionFormFields(...)),
            new TwigFunction('printess_configurator_options', $this->getConfiguratorOptionsMap(...)),
            new TwigFunction('printess_product_custom_field', $this->getProductCustomField(...)),
            new TwigFunction('printess_resolve_form_field_value', $this->resolveFormFieldValue(...)),
            new TwigFunction('printess_debug_mode', $this->isDebugModeEnabled(...)),
            new TwigFunction('printess_design_saving_enabled', $this->isDesignSavingEnabled(...)),
            new TwigFunction('printess_shop_user_id', $this->getShopUserId(...)),
        ];
    }

    public function getEditorLoaderScriptUrl(?string $salesChannelId = null): string
    {
        return $this->printessConfigService->getEditorLoaderScriptUrl($salesChannelId);
    }

    public function getShopToken(?string $salesChannelId = null): string
    {
        return $this->printessConfigService->getShopToken($salesChannelId);
    }

    public function getDefaultTheme(?string $salesChannelId = null): string
    {
        return $this->printessConfigService->getDefaultTheme($salesChannelId);
    }

    public function getEditorLanguage(?string $salesChannelId = null): string
    {
        return $this->printessConfigService->getEditorLanguage($salesChannelId);
    }

    public function getBasketThumbnailMaxWidth(?string $salesChannelId = null): ?string
    {
        return $this->printessConfigService->getBasketThumbnailMaxWidth($salesChannelId);
    }

    public function getBasketThumbnailMaxHeight(?string $salesChannelId = null): ?string
    {
        return $this->printessConfigService->getBasketThumbnailMaxHeight($salesChannelId);
    }

    /**
     * @param string[] $optionIds
     *
     * @return array<int, array{name: string, value: string}>
     */
    public function getProductOptionFormFields(array $optionIds): array
    {
        return $this->productOptionFormFieldService->getFormFields($optionIds);
    }

    /**
     * @return array<int, array{groupId: string, optionId: string, name: string, value: string}>
     */
    public function getConfiguratorOptionsMap(PropertyGroupCollection $configuratorSettings): array
    {
        return $this->productOptionFormFieldService->getConfiguratorOptionsMap($configuratorSettings);
    }

    /**
     * Reads one of the plugin's product custom fields (`PrintessTemplateName`, `PrintessFormFields`,
     * `PrintessMergeTemplates`), honouring the `perLanguageProductSettings` setting - see
     * `PrintessProductCustomFieldResolver`. Storefront templates should use this instead of
     * `product.customFields.PrintessXxx` directly.
     */
    public function getProductCustomField(ProductEntity $product, string $fieldName): mixed
    {
        return $this->productCustomFieldResolver->resolve($product, $product->getId(), $fieldName);
    }

    /**
     * Resolves a single `PrintessFormFields` entry's effective value: for a `source: "variable"`
     * entry, either the current storefront customer's value for `field.variable` (see
     * `PrintessCustomerVariableResolver::VARIABLE_KEYS`) or, for a `"property.<groupId>"` variable,
     * this product's own value for that property group (see `PrintessProductPropertyResolver`) -
     * falling back to `field.value` whenever the variable can't be resolved (no customer logged in,
     * the variable isn't set on their account, or the product has no option in that property group).
     * Any other entry (or the legacy shape with no `source` key at all) is returned as its plain
     * fixed `field.value`.
     *
     * @param array{name?: string, value?: string, source?: string, variable?: string} $field
     */
    public function resolveFormFieldValue(array $field, ?CustomerEntity $customer, ?ProductEntity $product): string
    {
        $fallback = (string) ($field['value'] ?? '');
        $variable = $field['variable'] ?? null;

        if (($field['source'] ?? 'fixed') !== 'variable' || !\is_string($variable) || $variable === '') {
            return $fallback;
        }

        if (str_starts_with($variable, self::PROPERTY_VARIABLE_PREFIX)) {
            $groupId = substr($variable, \strlen(self::PROPERTY_VARIABLE_PREFIX));

            return $this->productPropertyResolver->resolve($product, $groupId) ?? $fallback;
        }

        return $this->customerVariableResolver->resolve($customer, $variable) ?? $fallback;
    }

    public function isDebugModeEnabled(): bool
    {
        return $this->printessConfigService->isDebugModeEnabled();
    }

    public function isDesignSavingEnabled(?string $salesChannelId = null): bool
    {
        return $this->printessConfigService->isDesignSavingEnabled($salesChannelId);
    }

    /**
     * The stable `shopUserId` handed to the Printess editor - see `PrintessCustomerShopUserIdResolver`.
     * Null for an anonymous visitor or a guest checkout customer (guests never count as "logged in"
     * for design saving - there's no durable account to save against).
     */
    public function getShopUserId(?CustomerEntity $customer, ?Context $context): ?string
    {
        if ($customer === null || $customer->getGuest() || $context === null) {
            return null;
        }

        return $this->customerShopUserIdResolver->resolve($customer, $context);
    }
}
