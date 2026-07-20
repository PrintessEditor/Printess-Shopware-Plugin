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
 * Hosts the Printess editor for a design that was saved to the customer's account while they were
 * still anonymous, once they've logged in/registered (see `PrintessSavedDesignController::resume()`
 * and `shopLoginCallback` in `printess-design-now.plugin.js`). Structurally close to
 * `printess-cart-item-editor.plugin.js` (loads a save token, not a template name, no storefront
 * buy-widget/variant-switch UI to keep in sync) but this page always exists for an already logged-in
 * customer, so the shop-login/save callbacks are wired unconditionally, not gated behind a "saving
 * enabled" flag - the flag only matters for whether this page can be reached at all.
 */
export default class PrintessSavedDesignResumePlugin extends Plugin {
    init() {
        this.loaderUrl = this.el.dataset.printessLoaderUrl;
        this.shopToken = this.el.dataset.printessShopToken;
        this.theme = this.el.dataset.printessTheme;
        this.editorLanguage = this.el.dataset.printessEditorLanguage;
        this.basketThumbnailMaxWidth = this.el.dataset.printessBasketThumbnailMaxWidth;
        this.basketThumbnailMaxHeight = this.el.dataset.printessBasketThumbnailMaxHeight;
        this.photobookTheme = this.el.dataset.printessPhotobookTheme;
        this.saveToken = this.el.dataset.printessSaveToken;
        this.thumbnailUrl = this.el.dataset.printessThumbnailUrl || null;
        this.autoSaveToShop = this.el.dataset.printessAutoSaveToShop === 'true';
        this.savedDesignSortKey = this.el.dataset.printessSavedDesignSortKey || null;
        this.deleteSavedDesignUrl = this.el.dataset.printessDeleteSavedDesignUrl;
        this.priceUrl = this.el.dataset.printessPriceUrl;
        this.updateUrl = this.el.dataset.printessUpdateUrl;
        this.cartUrl = this.el.dataset.printessCartUrl;
        this.backUrl = this.el.dataset.printessBackUrl || this.cartUrl;
        this.configuratorOptions = this._parseJsonAttribute(this.el.dataset.printessConfiguratorOptions) ?? [];
        this.currentSelection = this._parseJsonAttribute(this.el.dataset.printessCurrentSelection) ?? {};

        this.shopId = this.el.dataset.printessShopId;
        this.shopUserId = this.el.dataset.printessShopUserId || null;
        this.shopUserDisplay = this.el.dataset.printessShopUserDisplay || null;
        this.parentProductId = this.el.dataset.printessParentProductId;
        this.variantId = this.el.dataset.printessVariantId;
        this.productDisplayName = this.el.dataset.printessProductDisplayName;
        this.productThumbnailUrl = this.el.dataset.printessProductThumbnailUrl || null;
        this.productShopUrl = this.el.dataset.printessProductShopUrl;
        this.pendingDisplayName = this.el.dataset.printessPendingDisplayName || null;

        this.printessApi = null;
        this._priceChangeDebounceTimer = null;
        this._lastPriceSelectionKey = null;
        this._lastPrice = null;
        this._updateSavedDesignModal = null;

        // Seeded from the pending design's own saved values (rather than left `null`) so that if
        // `saveTemplateToShop`/`addToBasketCallback` fires before the editor's own `priceChangeCallback`
        // has run even once, `_buildShopDataPayload` still reports the design's real page count/price-
        // relevant form fields instead of 0/empty.
        const pendingPageCount = Number(this.el.dataset.printessPendingPageCount || 0);
        const pendingPriceRelevantFormFields = this._parseJsonAttribute(this.el.dataset.printessPendingPriceRelevantFormFields) ?? {};

        this._lastPriceInfo = {
            pageCount: pendingPageCount,
            priceRelevantFormFields: Object.fromEntries(
                Object.entries(pendingPriceRelevantFormFields).map(([name, value]) => [name, { value }])
            ),
        };

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
                this._onAddToBasketRequested(saveToken, thumbnailUrl);
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

        this._applyShopLoginParams(loadParams);

        this.printessApi = await printessLoader.load(loadParams);

        if (this.autoSaveToShop) {
            this._scheduleSaveTemplateToShop();
        }
    }

    /**
     * The design shown on this page was auto-saved by Printess itself while the visitor was still
     * anonymous (see `shopLoginCallback` in `printess-design-now.plugin.js`) - at that point there was
     * no `shopUserId` yet, so that auto-save is not actually associated with any account. Now that the
     * visitor is logged in, proactively call `saveTemplateToShop` right after the editor loads so the
     * design is genuinely saved to their account even if they close the editor or add it to the basket
     * without pressing [Save] again. Pulled out via `setTimeout(..., 0)`, matching Printess's own
     * recommendation for calls made right after load (see `printess-design-now.plugin.js`'s
     * `_scheduleAfterEditorReadyActions`). Not called at all for the "My saved designs" edit flow
     * (`this.autoSaveToShop` is false there - see `PrintessSavedDesignController::renderSavedDesignEditor()`),
     * since that design is already properly associated with the account.
     */
    _scheduleSaveTemplateToShop() {
        setTimeout(() => {
            this._saveTemplateToShop().catch((error) => {
                console.log('[Printess] saveTemplateToShop: failed', error);
            });
        }, 0);
    }

    async _saveTemplateToShop() {
        if (!this.printessApi || !this.shopUserId) {
            return;
        }

        await this.printessApi.api.saveTemplateToShop(this._buildSavedShopData(this.saveToken, this.thumbnailUrl));

        console.log('[Printess] saveTemplateToShop: design associated with account');
    }

    /**
     * Shared `ISavedShopData` shape for both the post-login auto-save above and
     * `_replaceSavedDesign()` below - only `saveToken`/`thumbnailUrl` differ between the two callers.
     */
    _buildSavedShopData(saveToken, thumbnailUrl) {
        return {
            saveToken,
            displayName: this.pendingDisplayName || this.productDisplayName,
            thumbnailUrl: thumbnailUrl || '',
            shopData: {
                shopId: this.shopId,
                shopUserId: this.shopUserId,
                product: {
                    id: this.parentProductId,
                    displayName: this.productDisplayName,
                    thumbnailUrl: this.productThumbnailUrl || undefined,
                    shopUrl: this.productShopUrl,
                },
                data: this._buildShopDataPayload(),
            },
        };
    }

    /**
     * The customer is always logged in on this page (its route requires login), so
     * `isShopUserLoggedInCallback` always resolves true and `shopLoginCallback` can never fire -
     * only the other four shop-login/save callbacks apply here.
     */
    _applyShopLoginParams(loadParams) {
        if (this.shopUserId) {
            loadParams.shopUserId = this.shopUserId;
        }

        if (this.shopUserDisplay) {
            loadParams.shopUserDisplay = this.shopUserDisplay;
        }

        loadParams.isShopUserLoggedInCallback = async () => {
            console.log('[Printess] isShopUserLoggedInCallback: returning', true);
            return true;
        };

        loadParams.getShopProjectDisplayNameCallback = async () => {
            const displayName = this.pendingDisplayName || this.productDisplayName;
            console.log('[Printess] getShopProjectDisplayNameCallback: returning', displayName);
            return displayName;
        };

        loadParams.getShopDataCallback = async () => {
            const shopData = {
                shopId: this.shopId,
                shopUserId: this.shopUserId,
                product: {
                    id: this.parentProductId,
                    displayName: this.productDisplayName,
                    thumbnailUrl: this.productThumbnailUrl || undefined,
                    shopUrl: this.productShopUrl,
                },
                data: this._buildShopDataPayload(),
            };
            console.log('[Printess] getShopDataCallback: returning', shopData);
            return shopData;
        };

        loadParams.getShopSavedDataCallback = (shopData) => {
            console.log('[Printess] getShopSavedDataCallback: received', shopData);
            this._onShopSavedDataLoaded(shopData);
        };
    }

    /**
     * Same shape as `printess-design-now.plugin.js`'s own `_buildShopDataPayload` - everything needed
     * to recreate a basket item for this design without a product page loaded.
     */
    _buildShopDataPayload() {
        return {
            parentProductId: this.parentProductId,
            variantId: this.variantId,
            options: { ...this.currentSelection },
            pageCount: this._lastPriceInfo?.pageCount ?? 0,
            priceRelevantFormFields: this._extractFormFieldValues(this._lastPriceInfo),
        };
    }

    /**
     * Fires when the visitor picks a different saved design from Printess's own list while this page's
     * editor instance is still open - there's no buy-widget DOM to swap here (unlike the product page's
     * `printess-design-now.plugin.js`), so "switching variant" just means updating the tracked
     * variant/selection state and refreshing the displayed price to match the newly loaded design.
     */
    _onShopSavedDataLoaded(shopData) {
        const data = shopData?.data;

        if (!data) {
            return;
        }

        if (typeof data.variantId === 'string' && data.variantId) {
            this.variantId = data.variantId;
        }

        if (data.options && typeof data.options === 'object') {
            this.currentSelection = { ...this.currentSelection, ...data.options };
        }

        // `data.priceRelevantFormFields` is already the flat `{ name: value }` shape - wrapped back
        // into `iExternalProductPriceInfo`'s nested shape so `_refreshPriceDisplay`/`_resolvePrice`
        // can be reused unchanged, same as for a live `priceChangeCallback`.
        const syntheticPriceInfo = {
            pageCount: data.pageCount ?? 0,
            priceRelevantFormFields: Object.fromEntries(
                Object.entries(data.priceRelevantFormFields ?? {}).map(([name, value]) => [name, { value }])
            ),
        };

        this._lastPriceInfo = syntheticPriceInfo;
        this._refreshPriceDisplay(syntheticPriceInfo);
    }

    /**
     * Only the "My saved designs" edit flow (`this.savedDesignSortKey` set - see
     * `PrintessSavedDesignController::renderSavedDesignEditor()`) loaded an existing saved design that
     * could now be stale relative to what's being added to the basket. For that flow, the editor is
     * hidden and the visitor is asked whether to update their saved design too before continuing;
     * everything else (the anonymous-save-then-login hand-off) keeps the previous behavior of adding
     * to the basket immediately.
     */
    _onAddToBasketRequested(saveToken, thumbnailUrl) {
        if (!this.savedDesignSortKey) {
            this._onAddToBasket(saveToken, thumbnailUrl);
            return;
        }

        if (this.printessApi) {
            this.printessApi.ui.hide();
        }

        this._showUpdateSavedDesignModal(saveToken, thumbnailUrl);
    }

    _showUpdateSavedDesignModal(saveToken, thumbnailUrl) {
        const modalEl = document.getElementById('printess-update-saved-design-modal');

        // No modal markup / Bootstrap available for some reason - fall back to the plain add-to-basket
        // behavior rather than leaving the visitor stuck with a hidden editor and no way to continue.
        if (!modalEl || !window.bootstrap) {
            this._onAddToBasket(saveToken, thumbnailUrl);
            return;
        }

        if (!this._updateSavedDesignModal) {
            this._updateSavedDesignModal = new window.bootstrap.Modal(modalEl);

            modalEl.querySelector('[data-printess-update-saved-design-confirm]').addEventListener('click', () => {
                this._onUpdateSavedDesignChoice(true);
            });

            modalEl.querySelector('[data-printess-update-saved-design-decline]').addEventListener('click', () => {
                this._onUpdateSavedDesignChoice(false);
            });
        }

        this._pendingBasketSaveToken = saveToken;
        this._pendingBasketThumbnailUrl = thumbnailUrl;
        this._updateSavedDesignModal.show();
    }

    /**
     * Printess has no API to overwrite an existing saved design's own save token in place, so
     * "updating" it means: add the basket item first (under the *new* save token/thumbnail Printess
     * just handed back), then create a brand new saved design entry with that same save
     * token/thumbnail (same display name as before), then delete the old saved design entry - only
     * once all of that is done does the visitor get sent to the cart.
     */
    async _onUpdateSavedDesignChoice(shouldUpdate) {
        this._updateSavedDesignModal.hide();

        const saveToken = this._pendingBasketSaveToken;
        const thumbnailUrl = this._pendingBasketThumbnailUrl;

        const redirectUrl = await this._addLineItem(saveToken, thumbnailUrl);

        if (shouldUpdate) {
            await this._replaceSavedDesign(saveToken, thumbnailUrl);
        }

        window.location.href = redirectUrl;
    }

    async _replaceSavedDesign(newSaveToken, newThumbnailUrl) {
        try {
            await this.printessApi.api.saveTemplateToShop(this._buildSavedShopData(newSaveToken, newThumbnailUrl));
            await this._deleteOldSavedDesign();
        } catch (error) {
            console.log('[Printess] replaceSavedDesign: failed', error);
        }
    }

    async _deleteOldSavedDesign() {
        try {
            await fetch(this.deleteSavedDesignUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: new URLSearchParams({
                    productId: this.parentProductId,
                    sortKey: this.savedDesignSortKey,
                }).toString(),
            });
        } catch (error) {
            console.log('[Printess] deleteOldSavedDesign: failed', error);
        }
    }

    async _onAddToBasket(saveToken, thumbnailUrl) {
        window.location.href = await this._addLineItem(saveToken, thumbnailUrl);
    }

    async _addLineItem(saveToken, thumbnailUrl) {
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

            return (response.ok && data && data.redirectUrl) ? data.redirectUrl : this.cartUrl;
        } catch (error) {
            return this.cartUrl;
        }
    }

    /**
     * Matches the changed editor form field against the parent product's configurator options, so
     * the currently-selected variant (per group) is tracked for both price resolution and the
     * `data.options` reported back via `getShopDataCallback` - mirrors
     * `printess-cart-item-editor.plugin.js`'s own `_onFormFieldChanged`.
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
