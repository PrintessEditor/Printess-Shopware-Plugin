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

import Plugin from 'src/plugin-system/plugin.class';

const PRICE_REFRESH_DEBOUNCE_MS = 300;

/**
 * Drives the SlimUi variant of the cart/reorder item editor page (see
 * `PrintessCartItemController`/`PrintessOrderLineItemController`, both of which render
 * `cart-item-editor.html.twig` with `isSlimUiItem` true for a line item that was originally designed
 * with SlimUi - see `_printessSlimUiItem`). Counterpart to `printess-cart-item-editor.plugin.js` (the
 * full editor's equivalent) and to `printess-slim-ui.plugin.js` (the product page's SlimUi mount):
 *
 * - Like the full editor's cart-item-editor, `templateName` is the item's own save token (loading its
 *   exact previous state) rather than a product's template name, and there is no storefront
 *   configurator UI on this page to keep in sync - form field changes are only tracked to resolve the
 *   variant/price for the eventual submission.
 * - Unlike the product-page SlimUi mount, this page has a real, dedicated preview panel (styled after
 *   the printess-slim-integration SKILL.md's own basic example) rather than an existing product photo
 *   to redirect a preview callback onto, so `previewImage`/`loader` are left to SlimUi's own default
 *   handling (no `renderPreviewImageCallback` needed here). `progressStateChangedCallback` IS wired,
 *   though, driving the same two loading overlays printess-slim-ui.plugin.js shows on the product page
 *   (over the preview panel and over the SlimUi mount point) - both are rendered already-visible in
 *   `cart-item-editor.html.twig` so they cover the gap between page load and this plugin's first
 *   callback, same as the product page's own `_setLoadingState(true)` in `_mountUi()`.
 * - SlimUi has no built-in "add to basket"/back button chrome the way the full editor's own UI does,
 *   so this page renders its own, wired to `slimApi.createSaveToken()` + the same `updateUrl` POST
 *   the full editor's `addToBasketCallback` already uses.
 */
export default class PrintessSlimUiCartItemEditorPlugin extends Plugin {
    init() {
        this.slimUiLoaderUrl = this.el.dataset.printessSlimUiLoaderUrl;
        this.shopToken = this.el.dataset.printessShopToken;
        this.theme = this.el.dataset.printessTheme;
        this.editorLanguage = this.el.dataset.printessEditorLanguage;
        this.photobookTheme = this.el.dataset.printessPhotobookTheme;
        this.saveToken = this.el.dataset.printessSaveToken;
        this.priceUrl = this.el.dataset.printessPriceUrl;
        this.updateUrl = this.el.dataset.printessUpdateUrl;
        this.cartUrl = this.el.dataset.printessCartUrl;
        this.backUrl = this.el.dataset.printessBackUrl || this.cartUrl;
        this.configuratorOptions = this._parseJsonAttribute(this.el.dataset.printessConfiguratorOptions) ?? [];
        this.currentSelection = this._parseJsonAttribute(this.el.dataset.printessCurrentSelection) ?? {};
        this.debugMode = this.el.dataset.printessDebug === 'true';

        this.previewImage = this.el.querySelector('.printess-preview-image');
        this.loaderEl = this.el.querySelector('.printess-image-loader');
        this.uiContainer = this.el.querySelector('.printess-slim-ui-container');
        this.previewLoadingOverlay = this.el.querySelector('[data-printess-slim-ui-preview-loading-overlay]');
        this.uiLoadingOverlay = this.el.querySelector('[data-printess-slim-ui-ui-loading-overlay]');
        this.priceEl = this.el.querySelector('[data-printess-slim-ui-price]');
        this.statusEl = this.el.querySelector('[data-printess-slim-ui-status]');
        this.backButton = this.el.querySelector('[data-printess-slim-ui-back-button]');
        this.submitButton = this.el.querySelector('[data-printess-slim-ui-submit-button]');

        this.slimApi = null;
        this._selection = { ...this.currentSelection };
        this._priceRelevantFormFields = {};
        this._priceChangeDebounceTimer = null;
        this._lastPriceSelectionKey = null;
        this._lastPrice = null;
        this._priceRequestId = 0;

        this.backButton?.addEventListener('click', () => {
            window.location.href = this.backUrl;
        });
        this.submitButton?.addEventListener('click', () => this._onSubmit());

        this._debugLog('init: loading SlimUi from save token', {
            saveToken: this.saveToken,
            configuratorOptions: this.configuratorOptions,
            currentSelection: this.currentSelection,
        });

        this._createEditor();
    }

    async _createEditor() {
        const slimUiLoader = await import(/* webpackIgnore: true */ this.slimUiLoaderUrl);

        const loadParams = {
            previewContainer: this.el.querySelector('.printess-slim-ui-preview-panel'),
            uiContainer: this.uiContainer,
            previewImage: this.previewImage,
            loader: this.loaderEl,
            templateName: this.saveToken,
            shopToken: this.shopToken,
            published: true,
            formFieldChangedCallback: (name, value, tag, label, ffLabel) => {
                this._onFormFieldChanged(name, value, label, ffLabel);
            },
            progressStateChangedCallback: (show) => {
                this._setLoadingState(show);
            },
        };

        if (this.theme) {
            loadParams.theme = this.theme;
        }

        // The twig template already omits this attribute for "auto" (see cart-item-editor.html.twig),
        // so any value present here is a real, explicit language code.
        if (this.editorLanguage) {
            loadParams.lang = this.editorLanguage;
        }

        // Unlike the product page (which pushes these at load from the product's own untranslated
        // options), this page loads by save token alone - the design already has these values baked
        // in. Re-asserting them here is a no-op value-wise (same as what's already saved), but mirrors
        // the product page's own `createSlimUi()` call exactly, in case the SDK only reports later
        // `formFieldChangedCallback` changes for fields it was explicitly handed via `formFields` at
        // load, rather than for every "Impact-Price" field regardless of how it got its current value.
        const formFields = this._buildFormFieldsFromSelection();

        if (this.photobookTheme) {
            formFields.push({ name: 'PHOTOBOOK_THEME', value: this.photobookTheme });
        }

        if (formFields.length) {
            loadParams.formFields = formFields;
        }

        this._debugLog('createEditor: loading SlimUi', loadParams);

        this.slimApi = await slimUiLoader.createSlimUi(loadParams);
        this._refreshPriceDisplay();
    }

    _buildFormFieldsFromSelection() {
        return Object.entries(this.currentSelection)
            .map(([groupId, optionId]) => this.configuratorOptions.find(
                (entry) => entry.groupId === groupId && entry.optionId === optionId,
            ))
            .filter(Boolean)
            .map((entry) => ({ name: entry.name, value: entry.value }));
    }

    /**
     * Same overlay toggling as printess-slim-ui.plugin.js's own `_setLoadingState()` - both overlays
     * are rendered already-visible server-side (see cart-item-editor.html.twig), so this only ever
     * needs to hide/show them from here on, driven purely by `progressStateChangedCallback`.
     */
    _setLoadingState(show) {
        this.previewLoadingOverlay?.classList.toggle('printess-loading-overlay--visible', show);
        this.uiLoadingOverlay?.classList.toggle('printess-loading-overlay--visible', show);
    }

    /**
     * Matches the changed field against the parent product's configurator options (same mapping used
     * on the product page) so the currently-selected variant is tracked for both price resolution and
     * the final "add to basket" submission - every invocation is itself price-relevant, since SlimUi
     * has no separate price-change callback (see printess-slim-ui.plugin.js's identical reasoning).
     */
    _onFormFieldChanged(name, value, label, ffLabel) {
        this._debugLog('formFieldChangedCallback', { name, value, label, ffLabel });

        this._priceRelevantFormFields[name] = value;

        const match = this.configuratorOptions.find((entry) => (entry.name === name || entry.name === ffLabel)
            && (entry.value === value || entry.value === label));

        if (match) {
            this._debugLog('formFieldChangedCallback: matched configurator option, updating selection', match);

            this._selection = { ...this._selection, [match.groupId]: match.optionId };
        } else {
            this._debugLog('formFieldChangedCallback: no matching configurator option, selection unchanged', {
                name,
                value,
                label,
                ffLabel,
                configuratorOptions: this.configuratorOptions,
            });
        }

        this._schedulePriceRefresh();
    }

    _schedulePriceRefresh() {
        clearTimeout(this._priceChangeDebounceTimer);
        this._priceChangeDebounceTimer = setTimeout(() => {
            this._refreshPriceDisplay();
        }, PRICE_REFRESH_DEBOUNCE_MS);
    }

    async _refreshPriceDisplay() {
        const resolved = await this._resolvePrice();

        if (!resolved || !this.priceEl) {
            this._debugLog('refreshPriceDisplay: no resolved price or no price element, not updating display', {
                resolved,
                hasPriceEl: !!this.priceEl,
            });
            return;
        }

        this.priceEl.textContent = this._formatPrice(resolved.price, resolved.currencyIsoCode);
    }

    /**
     * Same resolution strategy as printess-slim-ui.plugin.js's own `_resolvePrice()` - `pageCount` is
     * always 0, SlimUi never supports a billable page count.
     */
    async _resolvePrice() {
        if (!this.priceUrl) {
            return null;
        }

        const selectionKey = JSON.stringify(this._selection);
        const cacheKey = JSON.stringify({ selectionKey, formFieldValues: this._priceRelevantFormFields });

        if (cacheKey === this._lastPriceSelectionKey && this._lastPrice) {
            return this._lastPrice;
        }

        const requestId = ++this._priceRequestId;

        try {
            const query = new URLSearchParams({
                options: selectionKey,
                pageCount: '0',
                formFieldValues: JSON.stringify(this._priceRelevantFormFields),
            });
            const response = await fetch(`${this.priceUrl}?${query.toString()}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await response.json();

            this._debugLog('resolvePrice: fetched price', { selection: this._selection, formFieldValues: this._priceRelevantFormFields, data });

            if (typeof data?.price !== 'number') {
                return null;
            }

            if (requestId !== this._priceRequestId) {
                return this._lastPrice;
            }

            this._lastPriceSelectionKey = cacheKey;
            this._lastPrice = { price: data.price, currencyIsoCode: data.currencyIsoCode };

            return this._lastPrice;
        } catch (error) {
            this._debugLog('resolvePrice: price lookup failed', error);
            return null;
        }
    }

    _formatPrice(price, currencyIsoCode) {
        if (!currencyIsoCode) {
            return String(price);
        }

        return new Intl.NumberFormat(document.documentElement.lang || undefined, {
            style: 'currency',
            currency: currencyIsoCode,
        }).format(price);
    }

    /**
     * Mirrors the printess-slim-integration SKILL.md's own basic example almost verbatim:
     * `await printess.createSaveToken()`, then hand the result off - here, to the same
     * `updateUrl` POST the full editor's `addToBasketCallback` already uses to replace the line item.
     * `createSaveToken()` rejects with `iSlimValidationErrors` (e.g. missing text/image) rather than
     * throwing a plain Error - surfaced directly rather than swallowed.
     */
    async _onSubmit() {
        if (!this.slimApi || this.submitButton.disabled) {
            return;
        }

        this._setStatus('', false);
        this.submitButton.disabled = true;

        try {
            const { saveToken, thumbnailUrl } = await this.slimApi.createSaveToken();
            await this._submitToCart(saveToken, thumbnailUrl);
        } catch (error) {
            this._debugLog('onSubmit: failed', error);
            this._setStatus(this._describeError(error), true);
            this.submitButton.disabled = false;
        }
    }

    async _submitToCart(saveToken, thumbnailUrl) {
        this._debugLog('submitToCart: submitting', { saveToken, thumbnailUrl, options: this._selection, priceRelevantFormFields: this._priceRelevantFormFields });

        const response = await fetch(this.updateUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                saveToken,
                thumbnailUrl,
                options: this._selection,
                pageCount: 0,
                priceRelevantFormFields: this._priceRelevantFormFields,
            }),
        });

        const data = await response.json();

        if (!response.ok) {
            throw new Error(data?.error || 'The updated design could not be saved.');
        }

        window.location.href = data?.redirectUrl || this.cartUrl;
    }

    /**
     * `error` here is either a plain `Error` (network/server failure) or an `iSlimValidationErrors`
     * array of `{ errorMessage }` (SlimUi's own validation, e.g. missing text/image).
     */
    _describeError(error) {
        if (Array.isArray(error)) {
            return error.map((entry) => entry?.errorMessage).filter(Boolean).join(' ');
        }

        return error?.message || String(error);
    }

    _setStatus(message, isError) {
        if (!this.statusEl) {
            return;
        }

        this.statusEl.textContent = message;
        this.statusEl.classList.toggle('d-none', !message);
        this.statusEl.classList.toggle('text-danger', !!isError);
    }

    _parseJsonAttribute(raw) {
        if (!raw) {
            return null;
        }

        try {
            return JSON.parse(raw);
        } catch (error) {
            return null;
        }
    }

    /**
     * Gated behind the plugin's "Enable storefront debug logging" setting, same as
     * printess-slim-ui.plugin.js/printess-design-now.plugin.js - not left on in production.
     */
    _debugLog(message, data) {
        if (!this.debugMode) {
            return;
        }

        if (typeof data === 'undefined') {
            console.log(`[Printess SlimUi Cart Item Editor] ${message}`);
        } else {
            console.log(`[Printess SlimUi Cart Item Editor] ${message}`, data);
        }
    }
}
