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

namespace PrintessShopwareIntegration\Service\Configuration;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class PrintessConfigService
{
    private const CONFIG_PREFIX = 'PrintessShopwareIntegration.config.';

    private const VERSION_NIGHTLY = 'nightly';
    private const VERSION_CUSTOM = 'custom';

    /**
     * Lets the editor pick its own UI language based on the visitor's browser - the default, and
     * whatever an invalid `editorLanguage` value falls back to. See {@see getEditorLanguage()}.
     */
    private const EDITOR_LANGUAGE_AUTO = 'auto';

    /**
     * Hard cap for `basketThumbnailMaxWidth`/`basketThumbnailMaxHeight` - a configured value above
     * this is clamped down to it rather than passed through uncapped. See {@see formatPixelDimension()}.
     */
    private const BASKET_THUMBNAIL_MAX_DIMENSION_PX = 1000;

    public function __construct(private readonly SystemConfigService $systemConfigService)
    {
    }

    public function getServiceToken(?string $salesChannelId = null): string
    {
        return $this->systemConfigService->getString(self::CONFIG_PREFIX . 'serviceToken', $salesChannelId);
    }

    public function getShopToken(?string $salesChannelId = null): string
    {
        return $this->systemConfigService->getString(self::CONFIG_PREFIX . 'shopToken', $salesChannelId);
    }

    public function getEditorIntegrationUrl(?string $salesChannelId = null): string
    {
        $domain = $this->normalizeDomain(
            $this->systemConfigService->getString(self::CONFIG_PREFIX . 'editorDomain', $salesChannelId)
        );

        return $domain . $this->getVersionPath($salesChannelId);
    }

    public function getApiUrl(?string $salesChannelId = null): string
    {
        return $this->normalizeDomain(
            $this->systemConfigService->getString(self::CONFIG_PREFIX . 'apiDomain', $salesChannelId)
        );
    }

    public function getEditorLoaderScriptUrl(?string $salesChannelId = null): string
    {
        return rtrim($this->getEditorIntegrationUrl($salesChannelId), '/') . '/printess-editor/loader.js';
    }

    /**
     * Same domain/version convention as {@see getEditorLoaderScriptUrl()}, for the SlimUi bundle
     * instead of the full editor's loader - `{domain}{versionPath}slim-ui.js`, e.g.
     * `https://editor.printess.com/slim-ui.js` for the default (published) version.
     */
    public function getSlimUiLoaderScriptUrl(?string $salesChannelId = null): string
    {
        return rtrim($this->getEditorIntegrationUrl($salesChannelId), '/') . '/slim-ui.js';
    }

    public function getDefaultTheme(?string $salesChannelId = null): string
    {
        return $this->systemConfigService->getString(self::CONFIG_PREFIX . 'defaultTheme', $salesChannelId);
    }

    /**
     * The editor's own UI language, written into its loader parameters' `translationKey` property -
     * always either `"auto"` (the editor picks a language itself, based on the visitor's browser) or
     * a lowercase 2-letter ISO 639-1 code. Any other stored value (unset, blank, malformed) falls
     * back to `"auto"` rather than being passed through as-is.
     */
    public function getEditorLanguage(?string $salesChannelId = null): string
    {
        $language = strtolower(trim($this->systemConfigService->getString(self::CONFIG_PREFIX . 'editorLanguage', $salesChannelId)));

        return preg_match('/^[a-z]{2}$/', $language) === 1 ? $language : self::EDITOR_LANGUAGE_AUTO;
    }

    /**
     * The maximum width, already formatted as a CSS pixel length (e.g. `"800px"`) for direct use as
     * the editor loader parameters' `basketThumbnailMaxWidth` property, or null if unset/not a
     * positive number - the editor then falls back to its own default. Capped at
     * {@see BASKET_THUMBNAIL_MAX_DIMENSION_PX} regardless of what's configured.
     */
    public function getBasketThumbnailMaxWidth(?string $salesChannelId = null): ?string
    {
        return $this->formatPixelDimension($this->systemConfigService->getInt(self::CONFIG_PREFIX . 'basketThumbnailMaxWidth', $salesChannelId));
    }

    /**
     * Same as {@see getBasketThumbnailMaxWidth()}, for the loader parameters' `basketThumbnailMaxHeight`.
     */
    public function getBasketThumbnailMaxHeight(?string $salesChannelId = null): ?string
    {
        return $this->formatPixelDimension($this->systemConfigService->getInt(self::CONFIG_PREFIX . 'basketThumbnailMaxHeight', $salesChannelId));
    }

    public function getPrintSettingName(?string $salesChannelId = null): string
    {
        return $this->systemConfigService->getString(self::CONFIG_PREFIX . 'printSettingName', $salesChannelId);
    }

    public function shouldApproveDesignsBeforeProduction(?string $salesChannelId = null): bool
    {
        return $this->systemConfigService->getBool(self::CONFIG_PREFIX . 'approveDesignsBeforeProduction', $salesChannelId);
    }

    /**
     * JSON-encoded `{mode: "none"|"dropshipping"|"template", productDefinitionId: number|null}`, or an empty
     * string when unset. Mirrors the shape stored in the `PrintessDropshippingConfig` product custom field.
     */
    public function getDropshippingConfig(?string $salesChannelId = null): string
    {
        return $this->systemConfigService->getString(self::CONFIG_PREFIX . 'dropshippingConfig', $salesChannelId);
    }

    /**
     * Whether a product's Printess template/form fields/merge templates/print setting/dropshipping
     * config may differ per language. Deliberately not sales-channel-scoped like the other getters
     * here - it governs how product data is edited/read in the admin and during production, which
     * isn't tied to any one sales channel.
     */
    public function isPerLanguageProductSettingsEnabled(): bool
    {
        return $this->systemConfigService->getBool(self::CONFIG_PREFIX . 'perLanguageProductSettings');
    }

    /**
     * Whether a personalized order line item should automatically show its design thumbnail instead
     * of the product's regular image in order-related emails - see `OrderMailThumbnailSubscriber`.
     * Enabled by default; exposed per sales channel so a shop can disable it if it ever causes an
     * issue with a specific custom email template.
     */
    public function isOrderMailThumbnailReplacementEnabled(?string $salesChannelId = null): bool
    {
        return $this->systemConfigService->getBool(self::CONFIG_PREFIX . 'orderMailThumbnailReplacementEnabled', $salesChannelId);
    }

    /**
     * Whether the storefront editor integration should log debug details (form fields/merge
     * templates sent to the editor, form field change callbacks, variant switches) to the browser
     * console. Deliberately global like {@see isPerLanguageProductSettingsEnabled()} - it's a
     * troubleshooting toggle, not something that should differ per sales channel.
     */
    public function isDebugModeEnabled(): bool
    {
        return $this->systemConfigService->getBool(self::CONFIG_PREFIX . 'debugMode');
    }

    /**
     * Whether a customer may save their current design to their account (prompting login/register
     * first if needed) - see the shop-login/save callbacks wired in `printess-design-now.plugin.js`.
     */
    public function isDesignSavingEnabled(?string $salesChannelId = null): bool
    {
        return $this->systemConfigService->getBool(self::CONFIG_PREFIX . 'enableDesignSaving', $salesChannelId);
    }

    private function formatPixelDimension(int $value): ?string
    {
        if ($value <= 0) {
            return null;
        }

        return min($value, self::BASKET_THUMBNAIL_MAX_DIMENSION_PX) . 'px';
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = rtrim(trim($domain), '/');

        if (str_starts_with($domain, 'http://')) {
            return 'https://' . substr($domain, \strlen('http://'));
        }

        if (!str_starts_with($domain, 'https://')) {
            return 'https://' . $domain;
        }

        return $domain;
    }

    private function getVersionPath(?string $salesChannelId): string
    {
        $version = $this->systemConfigService->getString(self::CONFIG_PREFIX . 'templateVersion', $salesChannelId);

        if ($version === self::VERSION_NIGHTLY) {
            return '/v/nightly/';
        }

        if ($version === self::VERSION_CUSTOM) {
            return $this->normalizeCustomVersionPath(
                $this->systemConfigService->getString(self::CONFIG_PREFIX . 'customTemplateVersion', $salesChannelId)
            );
        }

        return '';
    }

    private function normalizeCustomVersionPath(string $customVersion): string
    {
        $customVersion = trim($customVersion, '/');

        if ($customVersion === '') {
            return '';
        }

        if (str_starts_with($customVersion, 'v/')) {
            return '/' . $customVersion . '/';
        }

        return '/v/' . $customVersion . '/';
    }
}
