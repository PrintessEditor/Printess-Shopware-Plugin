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

/**
 * Matches ONLY `buy-widget.html.twig`'s own root div (rendered as
 * `product-detail-buy-{elementId} js-magnifier-zoom-image-container`), not the CMS layout's outer
 * column wrapper (`cms-block-gallery-buybox.html.twig` renders that as the exact, unsuffixed class
 * `product-detail-buy`) - see the matching constant/comment in printess-design-now.plugin.js for the
 * full reasoning. Both the variant configurator and the `.product-detail-price` element this plugin
 * updates directly live inside this same subtree.
 */
const BUY_WIDGET_SELECTOR = '[class*="product-detail-buy-"]';
const CONFIGURATOR_RADIO_SELECTOR = '.product-detail-configurator-option-input';
const CONFIGURATOR_SELECT_SELECTOR = '.product-detail-configurator-select-input';
const HIDDEN_CONFIGURATOR_CLASS = 'printess-slim-ui-hidden-configurator';
const PRICE_REFRESH_DEBOUNCE_MS = 300;

/**
 * The real product photo(s) SlimUi's live design preview is rendered onto (see
 * `_onRenderPreviewImageCallback`), scoped to four distinct spots the core storefront can render the
 * exact same underlying image into - main gallery (single-image or multi-image slider), the gallery's
 * thumbnail strip, and the fullscreen zoom modal's own separate copies of both. Disambiguated by the
 * extra classes core's `cms-element-image-gallery.html.twig` happens to add to each variant
 * (`js-magnifier-image` for the main display copy, `js-image-zoom-element`/`js-load-img` for the zoom
 * modal's), rather than by DOM ancestry, since the zoom modal is rendered as a sibling subtree
 * inside the same `.product-detail-media` column, not moved elsewhere in the document.
 */
const MAIN_IMAGE_SELECTOR = '.gallery-slider-image.js-magnifier-image';
const THUMBNAIL_IMAGE_SELECTOR = '.gallery-slider-thumbnails-image:not(.js-load-img)';
const ZOOM_MODAL_IMAGE_SELECTOR = '.gallery-slider-image.js-image-zoom-element';
const ZOOM_MODAL_THUMBNAIL_IMAGE_SELECTOR = '.gallery-slider-thumbnails-image.js-load-img';
const PRODUCT_IMAGE_SELECTOR_GROUPS = [
    MAIN_IMAGE_SELECTOR,
    THUMBNAIL_IMAGE_SELECTOR,
    ZOOM_MODAL_IMAGE_SELECTOR,
    ZOOM_MODAL_THUMBNAIL_IMAGE_SELECTOR,
];

/**
 * Shared by both the single-image (`gallery-slider-single-image`) and multi-image
 * (`gallery-slider-item`) main-slider markup - core's own `_gallery-slider.scss` already gives both
 * `position: relative`, which is exactly what's needed to anchor a loading overlay as an absolutely
 * positioned child without having to touch/override core's twig. Deliberately not matched against the
 * zoom modal (its slides never carry this class) - that overlay would go unseen behind the closed
 * modal anyway.
 */
const MAIN_IMAGE_CONTAINER_SELECTOR = '.js-magnifier-container';

/**
 * Embeds the Printess SlimUi editor directly into the product page in place of the (hidden, not
 * removed) variant configurator, for products with `PrintessSlimUiEnabled` active - see
 * `sw-product-detail-printess` (admin) and `buy-widget-form.html.twig` (rendering the
 * `[data-printess-slim-ui]` marker element this plugin attaches to).
 *
 * Unlike `printess-design-now.plugin.js`'s full editor (an overlay opened on a button click, its DOM
 * living outside the buy widget entirely), SlimUi loads immediately on page load and is mounted
 * INSIDE the buy widget subtree, at the configurator's own location. That difference rules out
 * reusing the full editor's variant-switch mechanism as-is: `_performVariantSwitch()` there replaces
 * the buy widget subtree's entire `innerHTML` with a freshly rendered page fragment, which would tear
 * down the very SlimUi instance mounted inside it. Since nothing in THIS integration (yet) depends on
 * a real page navigation - add-to-cart wiring for SlimUi is a later step - variant changes are instead
 * tracked purely in JS (`this._selection`) and mirrored onto the (hidden) configurator's own
 * radio/select inputs without dispatching a `change` event, so the storefront's own
 * `VariantSwitchPlugin` never fires a full navigation. The resulting price is fetched directly from
 * `frontend.printess.product.price` and written straight into `.product-detail-price`, since there's
 * no separate "editor UI" to hand a price string to the way the full editor's `priceChangeCallback`
 * does.
 */
export default class PrintessSlimUiPlugin extends Plugin {
    init() {
        this.slimUiLoaderUrl = this.el.dataset.printessSlimUiLoaderUrl;
        this.shopToken = this.el.dataset.printessShopToken;
        this.templateName = this.el.dataset.printessTemplateName;
        this.theme = this.el.dataset.printessTheme;
        this.editorLanguage = this.el.dataset.printessEditorLanguage;
        this.formFields = this._parseJsonAttribute(this.el.dataset.printessFormFields) ?? [];
        this.priceUrl = this.el.dataset.printessPriceUrl;
        this.debugMode = this.el.dataset.printessDebug === 'true';
        this.buyWidgetContainer = this.el.closest(BUY_WIDGET_SELECTOR);

        this.slimApi = null;

        /** Same reconciliation purpose as printess-design-now.plugin.js's own `_selection`. */
        this._selection = {};

        /**
         * Every price-relevant form field's current value, keyed by name - unlike the full editor
         * (which gets this handed to it wholesale via `priceChangeCallback`'s `iExternalProductPriceInfo`),
         * SlimUi only exposes `formFieldChangedCallback`, firing once per price-relevant field change
         * (see its own doc comment in slim-ui.d.ts), so this accumulates them itself.
         */
        this._priceRelevantFormFields = {};
        this._priceChangeDebounceTimer = null;
        this._lastPriceSelectionKey = null;
        this._lastPrice = null;
        this._priceRequestId = 0;

        this._debugLog('init: mounting SlimUi', { templateName: this.templateName, formFields: this.formFields });

        this._mountUi();
        this._registerAddToBasketEvents();
        this._loadSlimUi();
    }

    /**
     * Hides the storefront's own variant configurator (kept in the DOM, not removed - a future
     * add-to-cart flow may still need to read its inputs) and inserts the containers SlimUi renders
     * into at that same location. `previewContainer`/`previewImage`/`loader` are mandatory for
     * `createSlimUi()` but never actually shown - see `.printess-slim-ui-hidden-preview` and
     * `_onRenderPreviewImageCallback`.
     *
     * Also builds the two loading overlays this plugin shows on its own (independent of SlimUi's
     * mandatory-but-hidden `loader` element, which is never visible): one over the SlimUi mount point
     * itself (`uiContainer` is otherwise blank/zero-height until SlimUi finishes loading and injects
     * its own UI into it), one over the real product photo. Both are shown immediately here, before
     * `_loadSlimUi()` even starts fetching the editor, since the initial load has no other progress
     * indication at all - `_onProgressStateChanged` hides them once `progressStateChangedCallback`
     * first reports `false`.
     */
    _mountUi() {
        this.configuratorForm = this._getConfiguratorForm();
        const mountAnchor = this.configuratorForm || this.el;

        if (this.configuratorForm) {
            this.configuratorForm.classList.add(HIDDEN_CONFIGURATOR_CLASS);
        }

        const previewWrapper = document.createElement('div');
        previewWrapper.className = 'printess-slim-ui-hidden-preview';
        previewWrapper.innerHTML = '<div class="printess-preview"><img class="printess-preview-image"><div class="printess-image-loader"></div></div>';
        mountAnchor.insertAdjacentElement('afterend', previewWrapper);

        this.previewContainer = previewWrapper.querySelector('.printess-preview');
        this.previewImage = previewWrapper.querySelector('.printess-preview-image');
        this.loaderEl = previewWrapper.querySelector('.printess-image-loader');

        const uiWrapper = document.createElement('div');
        uiWrapper.className = 'printess-slim-ui-container-wrapper';

        this.uiContainer = document.createElement('div');
        this.uiContainer.className = 'printess-slim-ui-container';
        uiWrapper.appendChild(this.uiContainer);

        this.uiLoadingOverlay = this._buildLoadingOverlay();
        uiWrapper.appendChild(this.uiLoadingOverlay);

        previewWrapper.insertAdjacentElement('afterend', uiWrapper);

        this._mediaLoadingOverlays = this._attachMediaLoadingOverlays();

        this._setLoadingState(true);
    }

    /**
     * A centered Bootstrap `spinner-border` over a translucent white background - the background
     * alone is what makes the product photo underneath read as "lighter" while loading, the spinner on
     * top makes it unambiguous that something is actively happening rather than the image just being
     * washed out.
     */
    _buildLoadingOverlay() {
        const overlay = document.createElement('div');
        overlay.className = 'printess-loading-overlay';
        overlay.innerHTML = '<div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div>';
        return overlay;
    }

    /**
     * One overlay per main-slider slide (see `MAIN_IMAGE_CONTAINER_SELECTOR`) - in a multi-image
     * gallery every slide gets one since only the active one is ever visible at a time anyway, so there
     * is no need to track/move a single overlay across slide changes.
     */
    _attachMediaLoadingOverlays() {
        const root = document.querySelector('.product-detail-media') || document;

        return Array.from(root.querySelectorAll(MAIN_IMAGE_CONTAINER_SELECTOR)).map((container) => {
            const overlay = this._buildLoadingOverlay();
            container.appendChild(overlay);
            return overlay;
        });
    }

    /**
     * Single source of truth for "is SlimUi currently busy" - used both for the initial state (shown
     * from the very start of `_mountUi()`) and every subsequent `progressStateChangedCallback`
     * invocation. Toggles the two dedicated overlays plus the existing dimming applied to every real
     * product image (thumbnail strip/zoom modal included, which don't get a dedicated overlay of their
     * own).
     */
    _setLoadingState(show) {
        this.uiLoadingOverlay.classList.toggle('printess-loading-overlay--visible', show);
        this._mediaLoadingOverlays.forEach((overlay) => {
            overlay.classList.toggle('printess-loading-overlay--visible', show);
        });

        const root = document.querySelector('.product-detail-media') || document;

        PRODUCT_IMAGE_SELECTOR_GROUPS.forEach((selector) => {
            root.querySelectorAll(selector).forEach((node) => {
                node.classList.toggle('printess-preview-loading', show);
            });
        });
    }

    /**
     * SlimUi has no "add to basket" chrome of its own (unlike the full editor's own
     * `addToBasketCallback`), so `buy-widget-form.html.twig` renders a plain button for it
     * (`[data-printess-slim-ui-add-to-basket]`) - kept disabled until `this.slimApi` actually finishes
     * loading (see `_loadSlimUi`), since `createSaveToken()` isn't callable before then anyway and the
     * initial load itself can be slow.
     *
     * The hidden fields (`_printessSaveToken`/`_printessThumbnailUrl`/`_printessPageCount`/
     * `_printessPriceRelevantFormFields`) and the real submit button are the SAME ones the full editor's
     * `addToBasketCallback` uses - both editors share that markup (see
     * `buy_widget_buy_button_printess_hidden_fields`), only whichever one actually loaded for this
     * product fills them in and clicks the button.
     */
    _registerAddToBasketEvents() {
        this.form = this.el.closest('form');
        this.addToBasketTriggerButton = this.buyWidgetContainer
            ? this.buyWidgetContainer.querySelector('[data-printess-slim-ui-add-to-basket]')
            : null;
        this.statusEl = this.buyWidgetContainer
            ? this.buyWidgetContainer.querySelector('[data-printess-slim-ui-status]')
            : null;

        // The real Shopware submit button - `type="submit"` uniquely identifies it among the buy
        // widget's `.btn-buy` buttons, since both this trigger and the full editor's "Design now"
        // button are plain `type="button"`.
        this.addToBasketButton = this.form ? this.form.querySelector('button.btn-buy[type="submit"]') : null;
        this.saveTokenInput = this._getFormField('[data-printess-save-token-input]');
        this.thumbnailUrlInput = this._getFormField('[data-printess-thumbnail-url-input]');
        this.pageCountInput = this._getFormField('[data-printess-page-count-input]');
        this.priceRelevantFormFieldsInput = this._getFormField('[data-printess-price-relevant-form-fields-input]');

        this._debugLog('registerAddToBasketEvents', {
            hasAddToBasketTriggerButton: !!this.addToBasketTriggerButton,
            hasAddToBasketButton: !!this.addToBasketButton,
            hasSaveTokenInput: !!this.saveTokenInput,
            hasThumbnailUrlInput: !!this.thumbnailUrlInput,
        });

        this.addToBasketTriggerButton?.addEventListener('click', () => this._onAddToBasketClick());
    }

    /**
     * Mirrors printess-slim-ui-cart-item-editor.plugin.js's `_onSubmit()` almost verbatim:
     * `createSaveToken()` (rejecting with `iSlimValidationErrors` rather than throwing a plain `Error`
     * on e.g. missing text/image), then hand the result to the SAME hidden fields/submit button the
     * full editor's `addToBasketCallback` uses - `pageCount` is always 0, SlimUi never has a billable
     * page count (see `_resolvePrice`'s identical reasoning).
     */
    async _onAddToBasketClick() {
        if (!this.slimApi || !this.addToBasketTriggerButton || this.addToBasketTriggerButton.disabled) {
            return;
        }

        this._setStatus('', false);
        this.addToBasketTriggerButton.disabled = true;

        try {
            const { saveToken, thumbnailUrl } = await this.slimApi.createSaveToken();

            this._setFieldValue(this.saveTokenInput, saveToken);
            this._setFieldValue(this.thumbnailUrlInput, thumbnailUrl);
            this._setFieldValue(this.pageCountInput, '0');
            this._setFieldValue(this.priceRelevantFormFieldsInput, JSON.stringify(this._priceRelevantFormFields));

            this._debugLog('onAddToBasketClick: created save token, submitting', { saveToken, thumbnailUrl });

            if (this.addToBasketButton) {
                this.addToBasketButton.click();
            }
        } catch (error) {
            this._debugLog('onAddToBasketClick: failed', error);
            this._setStatus(this._describeError(error), true);
        } finally {
            this.addToBasketTriggerButton.disabled = false;
        }
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
     * `error` here is either a plain `Error` (unexpected failure) or an `iSlimValidationErrors` array
     * of `{ errorMessage }` (SlimUi's own validation, e.g. missing required text/image).
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

    async _loadSlimUi() {
        const slimUiLoader = await import(/* webpackIgnore: true */ this.slimUiLoaderUrl);

        const loadParams = {
            previewContainer: this.previewContainer,
            uiContainer: this.uiContainer,
            previewImage: this.previewImage,
            loader: this.loaderEl,
            templateName: this.templateName,
            shopToken: this.shopToken,
            published: true,
            formFieldChangedCallback: (name, value, tag, label, ffLabel) => {
                this._onFormFieldChanged(name, value, label, ffLabel);
            },
            renderPreviewImageCallback: (previewImageUrl) => {
                this._onRenderPreviewImageCallback(previewImageUrl);
            },
            progressStateChangedCallback: (show) => {
                this._onProgressStateChanged(show);
            },
        };

        if (this.theme) {
            loadParams.theme = this.theme;
        }

        // "auto" (the config default) means "let the editor pick a language itself" - mirrors
        // printess-design-now.plugin.js's identical handling of the same setting.
        if (this.editorLanguage && this.editorLanguage !== 'auto') {
            loadParams.lang = this.editorLanguage;
        }

        if (this.formFields.length) {
            loadParams.formFields = this.formFields;
        }

        this._debugLog('loadSlimUi: creating SlimUi instance', loadParams);

        this.slimApi = await slimUiLoader.createSlimUi(loadParams);

        if (this.addToBasketTriggerButton) {
            this.addToBasketTriggerButton.disabled = false;
        }
    }

    /**
     * Fires for every price-relevant ("Impact-Price") form field change. Reflects a variant-relevant
     * change onto the (hidden) configurator's own inputs - satisfying "keep the variant selector in
     * sync" without a page navigation - and always refreshes the storefront's own displayed price,
     * since every invocation of this callback is itself price-relevant (unlike the full editor, which
     * has a dedicated `priceChangeCallback` for that).
     */
    _onFormFieldChanged(name, value, label, ffLabel) {
        this._debugLog('formFieldChangedCallback', { name, value, label, ffLabel });

        this._priceRelevantFormFields[name] = value;

        const configuratorForm = this.configuratorForm;

        if (configuratorForm) {
            const optionsMap = this._parseJsonAttribute(configuratorForm.dataset.printessConfiguratorOptions) ?? [];
            const match = optionsMap.find((entry) => (entry.name === name || entry.name === ffLabel)
                && (entry.value === value || entry.value === label));

            if (match) {
                this._debugLog('formFieldChangedCallback: matched configurator option, updating variant selector', match);

                this._selection = { ...this._getSelection(configuratorForm), [match.groupId]: match.optionId };
                this._applySelectionToConfiguratorDom(configuratorForm, this._selection);
            } else {
                this._debugLog('formFieldChangedCallback: no matching configurator option, not touching variant selector');
            }
        }

        this._schedulePriceRefresh();
    }

    /**
     * Writes a resolved selection onto the configurator form's own radio/select inputs WITHOUT
     * dispatching a `change` event - a native `change` event would bubble to core's own
     * `VariantSwitchPlugin` (also listening on this same form) and trigger a full page navigation,
     * which is exactly what mounting SlimUi in-page is meant to avoid.
     */
    _applySelectionToConfiguratorDom(configuratorForm, selection) {
        configuratorForm.querySelectorAll(CONFIGURATOR_RADIO_SELECTOR).forEach((field) => {
            if (field.name && Object.prototype.hasOwnProperty.call(selection, field.name)) {
                field.checked = field.value === selection[field.name];
            }
        });

        configuratorForm.querySelectorAll(CONFIGURATOR_SELECT_SELECTOR).forEach((field) => {
            if (field.name && Object.prototype.hasOwnProperty.call(selection, field.name)) {
                field.value = selection[field.name];
            }
        });
    }

    _schedulePriceRefresh() {
        clearTimeout(this._priceChangeDebounceTimer);
        this._priceChangeDebounceTimer = setTimeout(() => {
            this._refreshPriceDisplay();
        }, PRICE_REFRESH_DEBOUNCE_MS);
    }

    async _refreshPriceDisplay() {
        const resolved = await this._resolvePrice();

        if (!resolved) {
            return;
        }

        const priceEl = this.buyWidgetContainer ? this.buyWidgetContainer.querySelector('.product-detail-price') : null;

        if (!priceEl) {
            this._debugLog('refreshPriceDisplay: no .product-detail-price element found, cannot update the displayed price');
            return;
        }

        priceEl.textContent = this._formatPrice(resolved.price, resolved.currencyIsoCode);
    }

    /**
     * Same resolution strategy as printess-design-now.plugin.js's own `_resolvePrice()` - the
     * storefront's current configurator selection is the baseline, cached by a selection+form-field
     * key so repeated callbacks for the same combination don't refetch needlessly. `pageCount` is
     * always 0 here: SlimUi doesn't support books/multi-page templates at all, so there is never a
     * billable page count to send (see `PrintessSlimUiEnabled` gating the "page relevant" custom
     * pricing fields in the admin UI).
     */
    async _resolvePrice() {
        if (!this.priceUrl) {
            return null;
        }

        const resolvedSelection = this.configuratorForm ? this._getSelection(this.configuratorForm) : { ...this._selection };
        const selectionKey = JSON.stringify(resolvedSelection);
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

            this._debugLog('resolvePrice: fetched price', { resolvedSelection, formFieldValues: this._priceRelevantFormFields, data });

            if (typeof data?.price !== 'number') {
                return null;
            }

            // A newer lookup may have started and already resolved while this one was still in
            // flight - an older, slower response must not overwrite the newer price being displayed.
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
     * Renders SlimUi's live design preview directly onto the real product photo(s) instead of the
     * (hidden, off-screen) `previewImage` `createSlimUi()` required - see `PRODUCT_IMAGE_SELECTOR_GROUPS`.
     * `previewImageUrl` is a single url, or one per "preview" (e.g. front/back of a business card) for
     * a multi-image template - each is applied, in order, to the Nth matching element of every group,
     * so a shop with a multi-image gallery gets every relevant photo replaced, not just the first.
     *
     * Deliberately scoped: if a template has more previews than the product has real gallery images,
     * the extra preview urls have nowhere on the page to go and are dropped (logged in debug mode) -
     * this integration doesn't grow the gallery with new slides.
     */
    _onRenderPreviewImageCallback(previewImageUrl) {
        const urls = Array.isArray(previewImageUrl) ? previewImageUrl : [previewImageUrl];
        const root = document.querySelector('.product-detail-media') || document;

        this._debugLog('renderPreviewImageCallback', urls);

        PRODUCT_IMAGE_SELECTOR_GROUPS.forEach((selector) => {
            const nodes = root.querySelectorAll(selector);

            urls.forEach((url, index) => {
                const node = nodes[index];

                if (!node) {
                    if (index === 0) {
                        return;
                    }

                    this._debugLog(`renderPreviewImageCallback: no product image at index ${index} for selector "${selector}", dropping preview url`, url);
                    return;
                }

                this._applyPreviewUrlToImage(node, url);
            });
        });
    }

    /**
     * A dynamically generated design preview has no precomputed responsive thumbnail variants, so any
     * `srcset`/`<source>` markup core's `sw_thumbnails` normally renders (which would otherwise take
     * priority over a plain `src` change inside a `<picture>`) is stripped before applying the url.
     *
     * The main gallery image also carries a separate `data-full-image` attribute - core's own
     * `MagnifierPlugin` (the hover-zoom lens) reads that attribute, not `src`/`srcset`, and re-reads it
     * live on every `mousemove` (see its `_getImageUrl()`), so updating it here is enough to make the
     * lens zoom into the personalized design too, without having to reinitialize that plugin.
     */
    _applyPreviewUrlToImage(img, url) {
        const picture = img.closest('picture');

        if (picture) {
            picture.querySelectorAll('source').forEach((source) => source.remove());
        }

        img.removeAttribute('srcset');
        img.removeAttribute('sizes');
        img.src = url;

        if (img.hasAttribute('data-full-image')) {
            img.setAttribute('data-full-image', url);
        }
    }

    /**
     * Fires whenever SlimUi starts/finishes doing work (initial load, regenerating the preview, ...).
     * See `_setLoadingState` for what actually gets toggled.
     */
    _onProgressStateChanged(show) {
        this._debugLog('progressStateChangedCallback', show);

        this._setLoadingState(show);
    }

    _getConfiguratorForm() {
        if (!this.buyWidgetContainer) {
            return null;
        }

        return this.buyWidgetContainer.querySelector('[data-variant-switch]');
    }

    /**
     * The authoritative current combination: whatever the (hidden) configurator form shows, overlaid
     * with every change made through SlimUi so far - same reconciliation as
     * printess-design-now.plugin.js's own `_getSelection()`.
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
     * Gated behind the plugin's "Enable storefront debug logging" setting, same as
     * printess-design-now.plugin.js - not left on in production.
     */
    _debugLog(message, data) {
        if (!this.debugMode) {
            return;
        }

        if (typeof data === 'undefined') {
            console.log(`[Printess SlimUi] ${message}`);
        } else {
            console.log(`[Printess SlimUi] ${message}`, data);
        }
    }
}
