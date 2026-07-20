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

const PRICE_CHANGE_DEBOUNCE_MS = 300;

/**
 * Drives the full-page Printess editor for re-designing an already-personalized cart item (see
 * `PrintessCartItemController`). Deliberately simpler than `printess-design-now.plugin.js`: the
 * editor loads once on page load (no "Design now" button/reuse-across-clicks), form field changes
 * are only tracked to know which variant to submit and to resolve its price (there's no storefront
 * buy-widget/variant-switch UI on this page to keep in sync), and `mergeTemplates`/`formFields`
 * aren't passed to the loader at all - the save token already fully describes the design.
 */
export default class PrintessCartItemEditorPlugin extends Plugin {
    init() {
        this.loaderUrl = this.el.dataset.printessLoaderUrl;
        this.shopToken = this.el.dataset.printessShopToken;
        this.theme = this.el.dataset.printessTheme;
        this.editorLanguage = this.el.dataset.printessEditorLanguage;
        this.basketThumbnailMaxWidth = this.el.dataset.printessBasketThumbnailMaxWidth;
        this.basketThumbnailMaxHeight = this.el.dataset.printessBasketThumbnailMaxHeight;
        this.photobookTheme = this.el.dataset.printessPhotobookTheme;
        this.saveToken = this.el.dataset.printessSaveToken;
        this.priceUrl = this.el.dataset.printessPriceUrl;
        this.updateUrl = this.el.dataset.printessUpdateUrl;
        this.cartUrl = this.el.dataset.printessCartUrl;

        /**
         * Where the back button navigates to - the cart page for the cart-item-editing flow, but
         * the order-history page for the order "reorder" flow (see `PrintessOrderLineItemController`),
         * since an already-placed order was never touched and there's nothing new in the cart to
         * show until the shopper actually finishes re-designing and adds it. Falls back to `cartUrl`
         * if not provided.
         */
        this.backUrl = this.el.dataset.printessBackUrl || this.cartUrl;
        this.configuratorOptions = this._parseJsonAttribute(this.el.dataset.printessConfiguratorOptions) ?? [];
        this.currentSelection = this._parseJsonAttribute(this.el.dataset.printessCurrentSelection) ?? {};

        this.printessApi = null;
        this._priceChangeDebounceTimer = null;
        this._lastPriceSelectionKey = null;
        this._lastPrice = null;

        /**
         * Most recent `iExternalProductPriceInfo` from `priceChangeCallback` - see
         * `printess-design-now.plugin.js` for why this is tracked separately from the debounced
         * display refresh (used by `_onAddToBasket` to stamp the replaced line item with the page
         * count/price-relevant form field values the custom pricing surcharge needs).
         */
        this._lastPriceInfo = null;

        this._createEditor();
    }

    async _createEditor() {
        const printessLoader = await import(/* webpackIgnore: true */ this.loaderUrl);

        const loadParams = {
            token: this.shopToken,
            templateName: this.saveToken,
            templateVersion: 'published',
            usePublishedVersion: true,
            addToBasketCallback: (saveToken, thumbnailUrl) => {
                this._onAddToBasket(saveToken, thumbnailUrl);
            },
            backButtonCallback: () => {
                window.location.href = this.backUrl;
            },
            formFieldChangedCallback: (name, value, tag, label, ffLabel) => {
                this._onFormFieldChanged(name, value, label, ffLabel);
            },
            priceChangeCallback: (priceInfo) => {
                this._onPriceChanged(priceInfo);
            },
        };

        if (this.theme) {
            loadParams.theme = this.theme;
        }

        // The twig template already omits this attribute for "auto" (see cart-item-editor.html.twig),
        // so any value present here is a real, explicit language code.
        if (this.editorLanguage) {
            loadParams.translationKey = this.editorLanguage;
        }

        if (this.basketThumbnailMaxWidth) {
            loadParams.basketThumbnailMaxWidth = this.basketThumbnailMaxWidth;
        }

        if (this.basketThumbnailMaxHeight) {
            loadParams.basketThumbnailMaxHeight = this.basketThumbnailMaxHeight;
        }

        if (this.photobookTheme) {
            loadParams.formFields = [{ name: 'PHOTOBOOK_THEME', value: this.photobookTheme }];
        }

        this.printessApi = await printessLoader.load(loadParams);
    }

    async _onAddToBasket(saveToken, thumbnailUrl) {
        try {
            const response = await fetch(this.updateUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    saveToken,
                    thumbnailUrl,
                    options: this.currentSelection,
                    pageCount: this._lastPriceInfo?.pageCount ?? 0,
                    priceRelevantFormFields: this._extractFormFieldValues(this._lastPriceInfo),
                }),
            });

            const data = await response.json();

            window.location.href = (response.ok && data && data.redirectUrl) ? data.redirectUrl : this.cartUrl;
        } catch (error) {
            window.location.href = this.cartUrl;
        }
    }

    /**
     * Matches the changed editor form field against the parent product's configurator options, so
     * the currently-selected variant (per group) is tracked for both price resolution and the final
     * "add to basket" submission - mirrors `printess-design-now.plugin.js`'s `_onEditorFormFieldChanged`,
     * minus the storefront variant-switch/page-sync part, which doesn't apply on this page.
     */
    _onFormFieldChanged(name, value, label, ffLabel) {
        const match = this.configuratorOptions.find((entry) => (entry.name === name || entry.name === ffLabel)
            && (entry.value === value || entry.value === label));

        if (!match) {
            return;
        }

        this.currentSelection = { ...this.currentSelection, [match.groupId]: match.optionId };
    }

    _onPriceChanged(priceInfo) {
        this._lastPriceInfo = priceInfo;

        clearTimeout(this._priceChangeDebounceTimer);
        this._priceChangeDebounceTimer = setTimeout(() => {
            this._refreshPriceDisplay(priceInfo);
        }, PRICE_CHANGE_DEBOUNCE_MS);
    }

    async _refreshPriceDisplay(priceInfo) {
        if (!this.printessApi) {
            return;
        }

        const resolved = await this._resolvePrice(priceInfo);

        if (!resolved) {
            return;
        }

        this.printessApi.ui.refreshPriceDisplay({
            price: this._formatPrice(resolved.price, resolved.currencyIsoCode),
        });
    }

    /**
     * Same reconciliation approach as `printess-design-now.plugin.js`'s `_resolvePrice`: the
     * currently-tracked configurator selection is the baseline, overlaid with whichever
     * price-relevant fields from `priceInfo` actually match a configurator option.
     */
    async _resolvePrice(priceInfo) {
        if (!this.priceUrl) {
            return null;
        }

        const resolvedSelection = { ...this.currentSelection };

        Object.entries(priceInfo?.priceRelevantFormFields ?? {}).forEach(([name, field]) => {
            const match = this.configuratorOptions.find((entry) => entry.name === name && entry.value === field.value);

            if (match) {
                resolvedSelection[match.groupId] = match.optionId;
            }
        });

        const pageCount = priceInfo?.pageCount ?? 0;
        const formFieldValues = this._extractFormFieldValues(priceInfo);
        const selectionKey = JSON.stringify(resolvedSelection);
        const cacheKey = JSON.stringify({ selectionKey, pageCount, formFieldValues });

        if (cacheKey === this._lastPriceSelectionKey && this._lastPrice) {
            return this._lastPrice;
        }

        try {
            const query = new URLSearchParams({
                options: selectionKey,
                pageCount: String(pageCount),
                formFieldValues: JSON.stringify(formFieldValues),
            });
            const response = await fetch(`${this.priceUrl}?${query.toString()}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await response.json();

            if (typeof data?.price !== 'number') {
                return null;
            }

            this._lastPriceSelectionKey = cacheKey;
            this._lastPrice = { price: data.price, currencyIsoCode: data.currencyIsoCode };

            return this._lastPrice;
        } catch (error) {
            return null;
        }
    }

    /**
     * `iExternalProductPriceInfo.priceRelevantFormFields` is `{ [name]: { value, tag } }` - flattened
     * here to plain `{ [name]: value }`.
     */
    _extractFormFieldValues(priceInfo) {
        const result = {};

        Object.entries(priceInfo?.priceRelevantFormFields ?? {}).forEach(([name, field]) => {
            result[name] = field?.value ?? '';
        });

        return result;
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
}
