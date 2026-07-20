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
import ElementReplaceHelper from 'src/helper/element-replace.helper';

const BASKET_ID_STORAGE_KEY = 'printess-basket-id';
const EDITOR_NODE_SELECTOR = '.printess-loader-container, printess-component';

/**
 * Matches ONLY `buy-widget.html.twig`'s own root div (rendered as
 * `product-detail-buy-{elementId} js-magnifier-zoom-image-container`), not the CMS layout's outer
 * column wrapper (`cms-block-gallery-buybox.html.twig` renders that as the exact, unsuffixed class
 * `product-detail-buy`). Both match a plain `[class*="product-detail-buy"]` substring selector,
 * and `ElementReplaceHelper`'s index-based fallback (used when source/target NodeList lengths are
 * equal) pairs them up by document order, not identity - patching the OUTER wrapper's innerHTML
 * first discards its entire subtree, silently detaching what was supposed to be the INNER target
 * for the very next iteration, which then harmlessly mutates an already-orphaned node. Requiring
 * the trailing hyphen excludes the exact, unsuffixed outer wrapper class.
 */
const BUY_WIDGET_SELECTOR = '[class*="product-detail-buy-"]';
const CONFIGURATOR_RADIO_SELECTOR = '.product-detail-configurator-option-input';
const CONFIGURATOR_SELECT_SELECTOR = '.product-detail-configurator-select-input';
const PRICE_CHANGE_DEBOUNCE_MS = 300;

export default class PrintessDesignNowPlugin extends Plugin {
    init() {
        this.loaderUrl = this.el.dataset.printessLoaderUrl;
        this.shopToken = this.el.dataset.printessShopToken;
        this.templateName = this.el.dataset.printessTemplateName;
        this.theme = this.el.dataset.printessTheme;
        this.editorLanguage = this.el.dataset.printessEditorLanguage;
        this.basketThumbnailMaxWidth = this.el.dataset.printessBasketThumbnailMaxWidth;
        this.basketThumbnailMaxHeight = this.el.dataset.printessBasketThumbnailMaxHeight;
        this.formFields = this._parseJsonAttribute(this.el.dataset.printessFormFields) ?? [];
        this.mergeTemplates = this._parseMergeTemplatesAttribute(this.el.dataset.printessMergeTemplates);
        this.bookSettings = this._parseJsonAttribute(this.el.dataset.printessBookSettings);
        this.insidePageCount = this.el.dataset.printessInsidePageCount;
        this.priceUrl = this.el.dataset.printessPriceUrl;
        this.debugMode = this.el.dataset.printessDebug === 'true';
        this.basketId = this._getOrCreateBasketId();
        this.buyWidgetContainer = this.el.closest(BUY_WIDGET_SELECTOR);

        /**
         * Only present when the "Allow customers to save designs to their account" plugin setting is
         * on - see `PrintessConfigService::isDesignSavingEnabled()`. Everything below drives the
         * shop-login/save callbacks wired further down in `_createEditor()`.
         */
        this.designSavingEnabled = this.el.dataset.printessDesignSavingEnabled === 'true';
        this.shopId = this.el.dataset.printessShopId;
        this.shopUserId = this.el.dataset.printessShopUserId || null;
        this.shopUserDisplay = this.el.dataset.printessShopUserDisplay || null;
        this.parentProductId = this.el.dataset.printessParentProductId;
        this.variantProductId = this.el.dataset.printessVariantProductId;
        this.productDisplayName = this.el.dataset.printessProductDisplayName;
        this.productThumbnailUrl = this.el.dataset.printessProductThumbnailUrl || null;
        this.productShopUrl = this.el.dataset.printessProductShopUrl;
        this.pendingUrl = this.el.dataset.printessPendingUrl;
        this.loginUrl = this.el.dataset.printessLoginUrl;
        this.registerUrl = this.el.dataset.printessRegisterUrl;

        this.form = this.el.closest('form');
        this.addToBasketButton = this._getAddToBasketButton();
        this.saveTokenInput = this._getFormField('[data-printess-save-token-input]');
        this.thumbnailUrlInput = this._getFormField('[data-printess-thumbnail-url-input]');
        this.pageCountInput = this._getFormField('[data-printess-page-count-input]');
        this.priceRelevantFormFieldsInput = this._getFormField('[data-printess-price-relevant-form-fields-input]');
        this.printessApi = null;
        this._priceChangeDebounceTimer = null;
        this._lastPriceSelectionKey = null;
        this._lastPrice = null;

        /**
         * The most recent `iExternalProductPriceInfo` reported via `priceChangeCallback`, kept
         * around (updated immediately, not debounced) so `addToBasketCallback` can stamp the line
         * item with the page count/price-relevant form field values the custom pricing surcharge
         * (`PrintessPriceCalculatorService`) needs, even if the debounced display refresh for that
         * same callback hasn't run yet. Never trusted for the price itself - see `_resolvePrice`.
         */
        this._lastPriceInfo = null;

        /**
         * Authoritative record of every group/option change made through the editor so far this
         * session, on top of whatever the storefront's own configurator form shows. Needed because
         * `_switchVariant`/`_resolvePrice` are async (2 sequential fetches for a switch): if the
         * visitor changes a second form field before the first switch's fetch+DOM-replace has
         * finished, reading the DOM alone would use a baseline that doesn't reflect the first,
         * still in-flight change yet, silently dropping it from the combination sent for the second
         * change. Updated synchronously (before any await) so it's always current regardless of
         * how many switches are still in flight. See `_switchRequestId`/`_priceRequestId` for the
         * matching guard against an older, slower response overwriting a newer one.
         */
        this._selection = {};
        this._switchRequestId = 0;
        this._priceRequestId = 0;

        this._debugLog('init: plugin attached, click "Design now" to load the editor', {
            templateName: this.templateName,
            formFields: this.formFields,
            mergeTemplates: this.mergeTemplates,
            hasConfiguratorForm: !!this._getConfiguratorForm(),
        });

        this._registerEvents();
    }

    _registerEvents() {
        this.el.addEventListener('click', this._onClick.bind(this));
    }

    async _onClick() {
        if (this.printessApi) {
            await this._reuseEditor();
            return;
        }

        await this._createEditor();
    }

    /**
     * The editor's loader instance and DOM nodes are kept alive across "add to basket" and
     * "close editor" (see the callbacks below, and `_reuseEditor`), so a second click on the
     * "Design now" button must reuse that instance instead of creating a new one.
     */
    async _createEditor() {
        this._removeExistingEditorNodes();

        const printessLoader = await import(/* webpackIgnore: true */ this.loaderUrl);

        const loadParams = {
            token: this.shopToken,
            templateName: this.templateName,
            templateVersion: 'published',
            basketId: this.basketId,
            usePublishedVersion: true,
            addToBasketCallback: (saveToken, thumbnailUrl) => {
                this._setFieldValue(this.saveTokenInput, saveToken);
                this._setFieldValue(this.thumbnailUrlInput, thumbnailUrl);
                this._setFieldValue(this.pageCountInput, String(this._lastPriceInfo?.pageCount ?? 0));
                this._setFieldValue(this.priceRelevantFormFieldsInput, JSON.stringify(this._extractFormFieldValues(this._lastPriceInfo)));

                if (this.printessApi) {
                    this.printessApi.ui.hide();
                }

                if (this.addToBasketButton) {
                    this.addToBasketButton.click();
                }
            },
            backButtonCallback: () => {
                this._setFieldValue(this.saveTokenInput, '');
                this._setFieldValue(this.thumbnailUrlInput, '');
                this._setFieldValue(this.pageCountInput, '');
                this._setFieldValue(this.priceRelevantFormFieldsInput, '');

                if (this.printessApi) {
                    this.printessApi.ui.hide();
                }
            },
            formFieldChangedCallback: (name, value, tag, label, ffLabel) => {
                this._onEditorFormFieldChanged(name, value, label, ffLabel);
            },
            priceChangeCallback: (priceInfo) => {
                this._onPriceChanged(priceInfo);
            },
        };

        if (this.theme) {
            loadParams.theme = this.theme;
        }

        // "auto" (the config default) means "let the editor pick a language itself" - not passing
        // `translationKey` at all, rather than passing the literal string "auto" through to it.
        if (this.editorLanguage && this.editorLanguage !== 'auto') {
            loadParams.translationKey = this.editorLanguage;
        }

        if (this.basketThumbnailMaxWidth) {
            loadParams.basketThumbnailMaxWidth = this.basketThumbnailMaxWidth;
        }

        if (this.basketThumbnailMaxHeight) {
            loadParams.basketThumbnailMaxHeight = this.basketThumbnailMaxHeight;
        }

        if (this.formFields.length) {
            loadParams.formFields = this.formFields;
        }

        if (this.mergeTemplates.length) {
            loadParams.mergeTemplates = this.mergeTemplates;
        }

        if (this.designSavingEnabled) {
            this._applyShopLoginParams(loadParams);
        }

        this._debugLog('createEditor: loading template', {
            templateName: this.templateName,
            formFields: this.formFields,
            mergeTemplates: this.mergeTemplates,
        });

        this.printessApi = await printessLoader.load(loadParams);
        this._scheduleAfterEditorReadyActions();
    }

    /**
     * Reloads the currently configured template/form fields into the already-attached editor
     * instance instead of tearing it down and creating a new loader (which would also lose the
     * exchange/undo state Printess tracks on the existing instance).
     */
    async _reuseEditor() {
        this._debugLog('reuseEditor: loading template', {
            templateName: this.templateName,
            formFields: this.formFields,
            mergeTemplates: this.mergeTemplates,
        });

        await this.printessApi.api.loadTemplateAndFormFields(
            this.templateName,
            this.mergeTemplates.length ? this.mergeTemplates : null,
            this.formFields.length ? this.formFields : null,
            null,
            null,
            true
        );
        this._scheduleAfterEditorReadyActions();

        this.printessApi.ui.show();
    }

    /**
     * Wires the five shop-login/save callbacks (see `printess-editor.d.ts`) plus the `shopUserId`/
     * `shopUserDisplay` load params, letting the editor's own "Save" dialog prompt an anonymous
     * visitor to log in/register, or save straight to their account if already logged in. Mutates
     * `loadParams` in place - only called when `this.designSavingEnabled` is true.
     */
    _applyShopLoginParams(loadParams) {
        if (this.shopUserId) {
            loadParams.shopUserId = this.shopUserId;
        }

        if (this.shopUserDisplay) {
            loadParams.shopUserDisplay = this.shopUserDisplay;
        }

        loadParams.isShopUserLoggedInCallback = async () => {
            const isLoggedIn = !!this.shopUserId;
            this._debugLog('[Printess] isShopUserLoggedInCallback: returning', isLoggedIn);
            return isLoggedIn;
        };

        loadParams.getShopProjectDisplayNameCallback = async () => {
            this._debugLog('[Printess] getShopProjectDisplayNameCallback: returning', this.productDisplayName);
            return this.productDisplayName;
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
            this._debugLog('[Printess] getShopDataCallback: returning', shopData);
            return shopData;
        };

        loadParams.shopLoginCallback = (type, saveToken, thumbnailUrl, displayName) => {
            this._debugLog('[Printess] shopLoginCallback: received', { type, saveToken, thumbnailUrl, displayName });
            this._onShopLoginRequired(type, saveToken, thumbnailUrl, displayName);
        };

        loadParams.getShopSavedDataCallback = (shopData) => {
            //is called in case the customer clicked on save
            this._debugLog('[Printess] getShopSavedDataCallback: received', shopData);
        };

        loadParams.shopDataLoadedCallback = (shopData) => {
          this._debugLog('[Printess] shopDataLoadedCallback: received', shopData);
          this._onShopSavedDataLoaded(shopData);
        }
    }

    /**
     * Everything needed to recreate a basket item for the current design without the product page
     * loaded: which product/variant, the configurator selection, and the price-relevant state the
     * custom pricing surcharge needs. Form field values and merge templates are already part of the
     * saved design itself, so they don't need to be repeated here. Used both as `getShopDataCallback`'s
     * companion `data` payload and as the body of the pending-design POST below.
     */
    _buildShopDataPayload() {
        const configuratorForm = this._getConfiguratorForm();

        // `this._selection` alone only reflects changes made *inside* the editor (a form field
        // change or a loaded saved design) - it starts empty, so a visitor who opens the editor and
        // saves without touching anything in there would otherwise report no options at all, even
        // though the storefront configurator already has a variant selected. Same
        // DOM-plus-tracked-overrides reconciliation as `_resolvePrice`.
        const options = configuratorForm ? this._getSelection(configuratorForm) : { ...this._selection };

        return {
            parentProductId: this.parentProductId,
            variantId: this.variantProductId,
            options,
            pageCount: this._lastPriceInfo?.pageCount ?? 0,
            priceRelevantFormFields: this._extractFormFieldValues(this._lastPriceInfo),
        };
    }

    /**
     * Fires when the visitor clicks Save while `isShopUserLoggedInCallback` resolved false: the
     * editor has already auto-saved the design and hands back its save token/thumbnail/chosen display
     * name here. Since login/registration is a full page navigation, the page state gathered above is
     * stashed server-side first (`PrintessSavedDesignController::pending()`) so it can be picked back
     * up on `frontend.printess.saved_design.resume` once the visitor is actually logged in.
     */
    async _onShopLoginRequired(type, saveToken, thumbnailUrl, displayName) {
        this._debugLog('shopLoginCallback', { type, saveToken, thumbnailUrl, displayName });

        try {
            await fetch(this.pendingUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({
                    ...this._buildShopDataPayload(),
                    saveToken,
                    thumbnailUrl,
                    displayName,
                    type,
                }),
            });
        } catch (error) {
            this._debugLog('shopLoginCallback: failed to store pending design', error);
        }

        const targetUrl = type === 'register' ? this.registerUrl : this.loginUrl;
        window.location.href = `${targetUrl}?redirectTo=${encodeURIComponent('frontend.printess.saved_design.resume')}`;
    }

    /**
     * Fires after the editor has loaded a design the visitor picked from their saved-projects list -
     * re-syncs the tracked configurator selection from the `data` we ourselves attached back when it
     * was saved (see `_buildShopDataPayload`), so price/variant display stay correct without the
     * product page having generated this particular design.
     */
    _onShopSavedDataLoaded(savedData) {
        this._debugLog('getShopSavedDataCallback', savedData);

        const data = savedData?.shopData?.data;

        if (!data) {
            return;
        }

        // Authoritative - stamped by whichever page originally saved this design (see
        // `_buildShopDataPayload`/`printess-saved-design-resume.plugin.js`). Set directly rather than
        // relying solely on the async, indirect `_performVariantSwitch` combinatorial lookup below:
        // that lookup can fail, be superseded, or lag behind an immediate "add to basket"/"save" click,
        // which previously left `this.variantProductId` pointing at whatever variant was on the page
        // before this saved design was loaded.
        if (typeof data.variantId === 'string' && data.variantId) {
            this.variantProductId = data.variantId;
        }

        if (data.options && typeof data.options === 'object') {
            const configuratorForm = this._getConfiguratorForm();

            if (configuratorForm) {
                const targetSelection = { ...this._getSelection(configuratorForm), ...data.options };

                this._performVariantSwitch(configuratorForm, targetSelection, null).catch((error) => {
                    this._debugLog('getShopSavedDataCallback: variant switch failed', error);
                });
            } else {
                this._selection = { ...this._selection, ...data.options };
            }
        }

        // `data.priceRelevantFormFields` is already the flat `{ name: value }` shape (see
        // `_buildShopDataPayload`/`_extractFormFieldValues`) - wrapped back into
        // `iExternalProductPriceInfo`'s nested shape so `_refreshPriceDisplay`/`_resolvePrice` can be
        // reused unchanged, same as for a live `priceChangeCallback`.
        const syntheticPriceInfo = {
            pageCount: data.pageCount ?? 0,
            priceRelevantFormFields: Object.fromEntries(
                Object.entries(data.priceRelevantFormFields ?? {}).map(([name, value]) => [name, { value }])
            ),
        };

        this._lastPriceInfo = syntheticPriceInfo;
        this._refreshPriceDisplay(syntheticPriceInfo).catch((error) => {
            this._debugLog('getShopSavedDataCallback: price refresh failed', error);
        });
    }

    /**
     * Runs whatever `_buildAfterEditorReadyActions()` currently returns once the editor instance is
     * ready to receive calls - whether just freshly created (`_createEditor`) or reused with a
     * newly (re)loaded template (`_reuseEditor`) - pulled out of the render loop via a
     * `setTimeout(..., 0)` per Printess's own recommendation for calls made right after load.
     */
    _scheduleAfterEditorReadyActions() {
        setTimeout(() => {
            this._runAfterEditorReadyActions().catch((error) => {
                this._debugLog('afterEditorReadyActions: failed', error);
            });
        }, 0);
    }

    async _runAfterEditorReadyActions() {
        const actions = this._buildAfterEditorReadyActions();

        for (const action of actions) {
            // Actions may depend on effects of earlier ones, so they run in order, not concurrently.
            // eslint-disable-next-line no-await-in-loop
            await action();
        }
    }

    /**
     * Extensibility point: one-time Printess API calls that must run right after the editor becomes
     * ready, in order - add further entries here for future post-load calls.
     */
    _buildAfterEditorReadyActions() {
        const actions = [];

        if (this.bookSettings && Object.keys(this.bookSettings).length > 0) {
            actions.push(() => {
                this._debugLog('afterEditorReadyActions: adjustBook', this.bookSettings);

                return this.printessApi.api.adjustBook(this.bookSettings);
            });
        }

        // A regular (non-Magic-Photobook) book's fixed inside page count - mutually exclusive with
        // a Magic Photobook's "initialFreestylePhotobookPages" (part of `this.bookSettings` above),
        // which sets its own initial page count instead. Must run after `adjustBook`.
        const insidePageCount = this.insidePageCount ? Number(this.insidePageCount) : null;
        const isMagicPhotobook = !!(this.bookSettings && Object.prototype.hasOwnProperty.call(this.bookSettings, 'initialFreestylePhotobookPages'));

        if (insidePageCount && !isMagicPhotobook) {
            actions.push(() => {
                this._debugLog('afterEditorReadyActions: setBookInsidePages', insidePageCount);

                return this.printessApi.api.setBookInsidePages(insidePageCount);
            });
        }

        return actions;
    }

    _removeExistingEditorNodes() {
        document.querySelectorAll(EDITOR_NODE_SELECTOR).forEach((node) => node.remove());
    }

    /**
     * `ElementReplaceHelper.replaceFromMarkup` (used by `_performVariantSwitch`) patches the buy widget
     * container's `innerHTML` - the container itself (`this.buyWidgetContainer`) stays attached, but
     * every descendant, including the `<form>`, the "add to basket" button, and the hidden save
     * token/thumbnail inputs, is destroyed and recreated. Without this, `addToBasketCallback`/
     * `backButtonCallback` would keep writing into and clicking detached nodes after any variant
     * switch, which is silently a no-op for the hidden inputs and throws "Form submission canceled
     * because the form is not connected" when clicking the detached button.
     */
    _rebindFormReferences() {
        // The buy widget container also holds the (unrelated) size/color configurator's own
        // `<form data-variant-switch>`, which comes BEFORE the actual buy-widget-form in document
        // order - `querySelector('form')` would silently grab that one instead. Re-locating the
        // fresh "Design now" button first and walking up from it, exactly like `init()` originally
        // did via `this.el.closest('form')`, finds the correct form regardless of what else is
        // nested in the container.
        const freshButton = this.buyWidgetContainer
            ? this.buyWidgetContainer.querySelector('[data-printess-design-now]')
            : null;

        // A variant switch can change which specific variant/product info a subsequent Save should
        // report - re-read it from the freshly rendered button rather than keeping the stale values
        // captured when the editor was first opened.
        if (freshButton) {
            this.variantProductId = freshButton.dataset.printessVariantProductId;
            this.productDisplayName = freshButton.dataset.printessProductDisplayName;
            this.productThumbnailUrl = freshButton.dataset.printessProductThumbnailUrl || null;
            this.productShopUrl = freshButton.dataset.printessProductShopUrl;
        }

        this.form = freshButton ? freshButton.closest('form') : null;
        this.addToBasketButton = this._getAddToBasketButton();
        this.saveTokenInput = this._getFormField('[data-printess-save-token-input]');
        this.thumbnailUrlInput = this._getFormField('[data-printess-thumbnail-url-input]');
        this.pageCountInput = this._getFormField('[data-printess-page-count-input]');
        this.priceRelevantFormFieldsInput = this._getFormField('[data-printess-price-relevant-form-fields-input]');

        this._debugLog('rebindFormReferences', {
            hasForm: !!this.form,
            hasAddToBasketButton: !!this.addToBasketButton,
            hasSaveTokenInput: !!this.saveTokenInput,
            hasThumbnailUrlInput: !!this.thumbnailUrlInput,
            hasPageCountInput: !!this.pageCountInput,
            hasPriceRelevantFormFieldsInput: !!this.priceRelevantFormFieldsInput,
        });
    }

    _getAddToBasketButton() {
        if (!this.form) {
            return null;
        }

        return this.form.querySelector('button.btn-buy:not([data-printess-design-now])');
    }

    _getFormField(selector) {
        if (!this.form) {
            return null;
        }

        return this.form.querySelector(selector);
    }

    _setFieldValue(field, value) {
        if (field) {
            field.value = value ?? '';
        }
    }

    /**
     * Called whenever the visitor changes a form field inside the editor. If the field matches one of
     * the untranslated product option group names/values, the storefront's displayed variant is
     * switched to match, without reloading the page (which would tear down the open editor).
     */
    _onEditorFormFieldChanged(name, value, label, ffLabel) {
        this._debugLog('formFieldChangedCallback', { name, value, label, ffLabel });

        const configuratorForm = this._getConfiguratorForm();

        if (!configuratorForm) {
            this._debugLog('formFieldChangedCallback: no configurator form found, ignoring');
            return;
        }

        const optionsMap = this._parseJsonAttribute(configuratorForm.dataset.printessConfiguratorOptions) ?? [];

        const match = optionsMap.find((entry) => (entry.name === name || entry.name === ffLabel)
            && (entry.value === value || entry.value === label));

        if (!match) {
            this._debugLog('formFieldChangedCallback: no matching configurator option, not switching variant', {
                name,
                value,
                label,
                ffLabel,
                optionsMap,
            });
            return;
        }

        this._debugLog('formFieldChangedCallback: matched configurator option', match);

        this._switchVariant(configuratorForm, match);
    }

    /**
     * Called whenever Printess reports that price-relevant information has changed. Debounced
     * since this can fire on every keystroke (e.g. for per-letter priced form fields).
     */
    _onPriceChanged(priceInfo) {
        this._debugLog('priceChangeCallback', priceInfo);

        // Updated immediately (not debounced) so `addToBasketCallback` always has the latest page
        // count/price-relevant form field values, even if a click happens before the debounced
        // display refresh below has run.
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
     * Resolves the raw, unformatted price of the variant Printess currently has selected, via our
     * own `frontend.printess.product.price` route (Shopware's own price calculation, respecting
     * the current customer/currency/tax context). A raw number is used rather than reading the
     * currency string already rendered on the page, since further Printess-side price additions
     * (e.g. a per-page surcharge for books) will need to be added on top of it before formatting.
     *
     * `priceInfo` on its own isn't trusted directly for which variant to resolve (see
     * printessEditor.ts:862 in the Shopify integration, which does the same reconciliation via its
     * own `getPriceRelevantData`): it can lag behind a variant switch still in flight, or list
     * fields that are price-relevant but aren't configurator options at all. So the storefront's
     * current configurator selection is used as the baseline and only the price-relevant fields
     * that actually match a configurator option are overlaid on top, to work out which variant's
     * price should really be shown. The resolved combination is cached so repeated callbacks for
     * the same selection (e.g. unrelated price-relevant fields) don't refetch needlessly.
     *
     * The page count and price-relevant form field values are sent along as-is, on top of the
     * resolved variant selection - the server (`PrintessPriceController`, via the same
     * `PrintessPriceCalculatorService` used to enforce the real checkout price) computes any custom
     * per-page/form-field-combination surcharge from them, so this never duplicates that pricing
     * logic here.
     */
    async _resolvePrice(priceInfo) {
        if (!this.priceUrl) {
            return null;
        }

        const configuratorForm = this._getConfiguratorForm();
        const optionsMap = configuratorForm
            ? this._parseJsonAttribute(configuratorForm.dataset.printessConfiguratorOptions) ?? []
            : [];
        const resolvedSelection = configuratorForm ? this._getSelection(configuratorForm) : { ...this._selection };

        Object.entries(priceInfo?.priceRelevantFormFields ?? {}).forEach(([name, field]) => {
            const match = optionsMap.find((entry) => entry.name === name && entry.value === field.value);

            if (match) {
                resolvedSelection[match.groupId] = match.optionId;
            }
        });

        const pageCount = priceInfo?.pageCount ?? 0;
        const formFieldValues = this._extractFormFieldValues(priceInfo);
        const selectionKey = JSON.stringify(resolvedSelection);
        const cacheKey = JSON.stringify({ selectionKey, pageCount, formFieldValues });

        if (cacheKey === this._lastPriceSelectionKey && this._lastPrice) {
            this._debugLog('resolvePrice: unchanged selection, using cached price', {
                resolvedSelection,
                pageCount,
                formFieldValues,
                cachedPrice: this._lastPrice,
            });
            return this._lastPrice;
        }

        const requestId = ++this._priceRequestId;

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

            this._debugLog('resolvePrice: fetched price for resolved selection', {
                resolvedSelection,
                pageCount,
                formFieldValues,
                data,
                requestId,
            });

            if (typeof data?.price !== 'number') {
                return null;
            }

            // A newer price lookup (from a subsequent form field/price change) may have started and
            // already resolved while this one was still in flight - an older, slower response must
            // not overwrite the newer price that's already being displayed.
            if (requestId !== this._priceRequestId) {
                this._debugLog('resolvePrice: superseded by a newer lookup, discarding stale response', { requestId, latest: this._priceRequestId });
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

    /**
     * `iExternalProductPriceInfo.priceRelevantFormFields` is `{ [name]: { value, tag } }` - flattened
     * here to plain `{ [name]: value }`, which is all `PrintessPriceCalculatorService` needs to match
     * a `formFieldPrices` rule's conditions.
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

    _getConfiguratorForm() {
        if (!this.buyWidgetContainer) {
            return null;
        }

        return this.buyWidgetContainer.querySelector('[data-variant-switch]');
    }

    async _switchVariant(configuratorForm, match) {
        const selectedOptions = this._getSelection(configuratorForm);
        selectedOptions[match.groupId] = match.optionId;

        await this._performVariantSwitch(configuratorForm, selectedOptions, match.groupId);
    }

    /**
     * Fetches the variant matching `targetSelection` via the storefront's own combinatorial variant
     * lookup, replaces the buy-widget markup with the resolved variant's rendered page, and rebinds
     * this plugin's DOM references. Shared by an in-editor form field change (`_switchVariant`, one
     * group changed at a time, `switchedGroupId` set) and loading a saved design with a full target
     * selection at once (`_onShopSavedDataLoaded`, `switchedGroupId` null - the whole combination is
     * already known, nothing to prioritize).
     */
    async _performVariantSwitch(configuratorForm, targetSelection, switchedGroupId) {
        const switchOptions = this._parseJsonAttribute(configuratorForm.dataset.variantSwitchOptions);

        if (!switchOptions || !switchOptions.url) {
            this._debugLog('performVariantSwitch: no variant switch endpoint configured, ignoring', { targetSelection, switchOptions });
            return;
        }

        // Recorded synchronously, before the first await, so a second form field change made while
        // this switch is still in flight already builds on top of this one instead of reading a DOM
        // that hasn't been updated yet.
        this._selection = targetSelection;

        const requestId = ++this._switchRequestId;

        this._debugLog('performVariantSwitch: switching to', {
            switchedGroupId,
            allSelectedOptions: targetSelection,
            requestId,
        });

        const queryParams = { options: JSON.stringify(targetSelection) };

        if (switchedGroupId) {
            queryParams.switched = switchedGroupId;
        }

        const query = new URLSearchParams(queryParams);

        try {
            const switchResponse = await fetch(`${switchOptions.url}?${query.toString()}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const switchData = await switchResponse.json();

            this._debugLog('performVariantSwitch: switch endpoint response', switchData);

            if (!switchData || !switchData.url) {
                this._debugLog('performVariantSwitch: switch endpoint returned no url, not navigating');
                return;
            }

            // Unlike the switch endpoint above, the resolved product detail page rejects
            // XMLHttpRequest-flagged requests outright (Shopware's PageController guard), so this
            // fetch must look like a plain navigation.
            const pageResponse = await fetch(switchData.url);
            const html = await pageResponse.text();

            // A newer switch (from a subsequent form field change) may have started and already
            // resolved while this one was still fetching - applying this now-stale response would
            // revert the storefront to an older combination.
            if (requestId !== this._switchRequestId) {
                this._debugLog('performVariantSwitch: superseded by a newer switch, discarding stale response', { requestId, latest: this._switchRequestId });
                return;
            }

            ElementReplaceHelper.replaceFromMarkup(html, [BUY_WIDGET_SELECTOR]);
            window.history.replaceState(window.history.state, '', switchData.url);
            window.PluginManager.initializePlugins();
            this._rebindFormReferences();
        } catch (error) {
            this._debugLog('performVariantSwitch: failed, keeping editor open', error);
            // Keep the editor open even if the storefront variant sync fails; the user can still
            // finish and add the currently displayed variant to the basket.
        }
    }

    /**
     * The authoritative current combination: whatever the live storefront configurator form shows,
     * overlaid with every change made through the editor so far (see `this._selection`) that may not
     * be reflected in the DOM yet because its variant switch is still in flight.
     */
    _getSelection(configuratorForm) {
        return { ...this._getCurrentSelection(configuratorForm), ...this._selection };
    }

    _getCurrentSelection(configuratorForm) {
        const selection = {};

        configuratorForm.querySelectorAll(CONFIGURATOR_RADIO_SELECTOR).forEach((field) => {
            if (field.name && field.checked) {
                selection[field.name] = field.value;
            }
        });

        configuratorForm.querySelectorAll(CONFIGURATOR_SELECT_SELECTOR).forEach((field) => {
            if (field.name) {
                selection[field.name] = field.value;
            }
        });

        return selection;
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
     * The `PrintessMergeTemplates` product custom field (and therefore this data attribute) is a
     * JSON-serialized array of merge template configs, or, for backwards compatibility with the
     * legacy `printess-editor` plugin, a plain template name string.
     */
    _parseMergeTemplatesAttribute(raw) {
        if (!raw) {
            return [];
        }

        let parsed;

        try {
            parsed = JSON.parse(raw);
        } catch (error) {
            return [{ templateName: raw }];
        }

        if (Array.isArray(parsed)) {
            return parsed;
        }

        if (parsed && typeof parsed === 'object') {
            return [parsed];
        }

        if (typeof parsed === 'string') {
            return parsed ? [{ templateName: parsed }] : [];
        }

        return [];
    }

    /**
     * Gated behind the plugin's "Enable storefront debug logging" setting - see
     * `PrintessConfigService::isDebugModeEnabled()`. Only intended for troubleshooting form field/
     * variant-switch/price behaviour on the product page, not left on in production.
     */
    _debugLog(message, data) {
        if (!this.debugMode) {
            return;
        }

        if (typeof data === 'undefined') {
            console.log(`[Printess] ${message}`);
        } else {
            console.log(`[Printess] ${message}`, data);
        }
    }

    _getOrCreateBasketId() {
        let basketId = window.localStorage.getItem(BASKET_ID_STORAGE_KEY);

        if (!basketId) {
            basketId = (window.crypto && window.crypto.randomUUID)
                ? window.crypto.randomUUID()
                : `printess-${Date.now()}-${Math.random().toString(36).slice(2)}`;

            window.localStorage.setItem(BASKET_ID_STORAGE_KEY, basketId);
        }

        return basketId;
    }
}
