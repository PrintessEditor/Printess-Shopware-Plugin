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
 * `_indexProductImages`), scoped to four distinct spots the core storefront can render the
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
 * The main gallery slider is a tiny-slider in carousel mode with `loop` left at its default `true`
 * (core's `GallerySliderPlugin` only ever disables it for the thumbnail slider), so on init tiny-slider
 * CLONES slides - one copy of the last slide prepended and one of the first appended for a
 * single-item viewport - and the zoom modal's slider does the same on first open. Those clones are
 * real, separately queried `img` elements showing the same media, which rules out addressing product
 * photos by their position in a `querySelectorAll` result: with 6 product images `nodes[0]` is a clone
 * of image 6, not image 1. Every image is therefore stamped with the index of the media it shows
 * (`_indexProductImages`) and looked up by that stamp instead, which also means a clone tiny-slider
 * makes later inherits its source's stamp for free, `cloneNode(true)` copying data attributes.
 */
const CLONED_SLIDE_SELECTOR = '.tns-slide-cloned';

/**
 * `PrintessSlimUiFeatures` opt-in features (see `ProductCustomFieldsInstaller::SLIM_UI_FEATURE_KEYS`).
 *
 * `pageNavigation` hands SlimUi a container of its own, above the gallery, which it fills with a strip
 * of small page previews for switching between the pages of a design (front/back of a postcard, the
 * pages of a calendar). That makes it the counterpart to `_refreshPreviewImages()` rather than an
 * addition to it: either the design's pages are spread across the product's own gallery images, or
 * SlimUi owns page switching itself and the main product image simply follows the selected page.
 * `PAGE_NAVIGATION_ROOT_CLASS` is what `base.scss` hangs the "hide the gallery's own carousel chrome"
 * rules off, so the storefront doesn't offer two competing ways to change what the big image shows.
 */
const PAGE_NAVIGATION_FEATURE = 'pageNavigation';

/**
 * The opposite arrangement to `pageNavigation`: the gallery keeps its own chrome and that chrome
 * drives SlimUi's current page instead - see `_registerGallerySlideSync`. Opt-in, to stay configured
 * the same way as the Shopify integration's identically-named feature (where it has to be, since live
 * shops there rely on the theme thumbnails switching nothing but the theme's own image).
 */
const THUMBNAIL_NAVIGATION_FEATURE = 'thumbnailNavigation';
const PAGE_NAVIGATION_ROOT_CLASS = 'printess-slim-ui-page-navigation';
const PAGE_NAVIGATION_CONTAINER_CLASS = 'printess-page-navigation';

/**
 * Core registers `GallerySliderPlugin` on this attribute, and only renders it for a multi-image
 * gallery - see `_registerGallerySlideSync`, which follows the shopper's slide changes so SlimUi's own
 * idea of the current preview page stays in step with the product image on screen.
 */
const GALLERY_SLIDER_SELECTOR = '[data-gallery-slider]';
const MEDIA_INDEX_ATTRIBUTE = 'data-printess-media-index';

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
        this.mergeTemplate = this.el.dataset.printessSlimUiMergeTemplate;
        this.features = this._parseJsonAttribute(this.el.dataset.printessSlimUiFeatures) ?? [];
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

        /** Number of distinct product images in the gallery - see `_indexProductImages`. */
        this._mediaCount = 0;

        /**
         * Guards against an out-of-order preview render: `renderPreviewImageCallback` fires again for
         * every design change, and `_refreshPreviewImages()` awaits one `getPreviewImageInfo()` request
         * per additional preview page, so a slower earlier render must not overwrite a newer one.
         * Same pattern as `_priceRequestId`.
         */
        this._previewRequestId = 0;

        /**
         * The url of the most recent `renderPreviewImageCallback`, kept so `_loadSlimUi()` can redo the
         * mapping for the multi-preview case once `this.slimApi` exists (see `_refreshPreviewImages`).
         */
        this._lastPreviewUrl = null;

        /**
         * Which product image the shopper is currently looking at, and the preview page mapped onto
         * each of them (`_previewPages[mediaIndex]`, filled in by `_refreshPreviewImages`). SlimUi
         * renders one page at a time and reports it through `renderPreviewImageCallback`, so knowing
         * which image that page belongs on is what keeps a gallery of several pages coherent - see
         * `_syncPreviewToGallerySlide`. Starts at 0: the gallery opens on its first slide.
         */
        this._currentMediaIndex = 0;
        this._previewPages = [];

        /**
         * Set while a `setPreview()` this plugin triggered itself is still in flight, so the render it
         * causes doesn't refetch every other page - see `_onRenderPreviewImageCallback`.
         */
        this._previewSwitchPending = false;

        /**
         * Latches as soon as SlimUi's own UI is actually inside `uiContainer` - whichever comes
         * first of the initial `renderPreviewImageCallback` and `createSlimUi()` resolving. From then
         * on `_setLoadingState` leaves the SlimUi mount point uncovered: `progressStateChangedCallback`
         * fires on every keystroke in a text field, and an overlay over the editor's own inputs turns
         * typing into a fight with a spinner. The product-photo overlays keep toggling with it - that
         * photo really is stale until the regenerated preview arrives.
         */
        this._slimUiMounted = false;

        /** Set by `_mountPageNavigation()`, and only when the feature is on - see `_hasFeature`. */
        this.pageNavigationContainer = null;

        this._debugLog('init: mounting SlimUi', { templateName: this.templateName, formFields: this.formFields, features: this.features });

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
     * first reports `false`. The one over the mount point is for that initial load only and never
     * comes back afterwards, see `_slimUiMounted`.
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

        this._indexProductImages();

        if (this._hasFeature(PAGE_NAVIGATION_FEATURE)) {
            this.pageNavigationContainer = this._mountPageNavigation();
        }

        this._attachMediaLoadingOverlays();

        this._setLoadingState(true);
    }

    _hasFeature(feature) {
        return Array.isArray(this.features) && this.features.includes(feature);
    }

    /**
     * Inserts the container SlimUi renders its page-preview strip into, as the media column's first
     * child so it sits above the gallery - the same placement the Shopify integration uses - and marks
     * that column so `base.scss` can hide the carousel chrome this replaces.
     *
     * Returns null when the page has no product media column to put it in (a CMS layout that renders
     * the gallery elsewhere): `_loadSlimUi()` then simply doesn't pass `pageNavigation`, and SlimUi
     * falls back to its normal behaviour of not rendering one at all, rather than this plugin
     * scattering a stray container across the page or throwing during mount.
     */
    _mountPageNavigation() {
        const mediaColumn = document.querySelector('.product-detail-media');

        if (!mediaColumn) {
            this._debugLog('mountPageNavigation: no .product-detail-media column on this page, page navigation not mounted');
            return null;
        }

        mediaColumn.classList.add(PAGE_NAVIGATION_ROOT_CLASS);

        const existing = mediaColumn.querySelector(`.${PAGE_NAVIGATION_CONTAINER_CLASS}`);

        if (existing) {
            return existing;
        }

        const container = document.createElement('div');
        container.className = PAGE_NAVIGATION_CONTAINER_CLASS;
        mediaColumn.insertBefore(container, mediaColumn.firstChild);

        return container;
    }

    /**
     * Everything this plugin touches outside the buy widget lives in the product page's own media
     * column; `document` is the fallback for a CMS layout that renders the gallery somewhere else.
     */
    _getMediaRoot() {
        return document.querySelector('.product-detail-media') || document;
    }

    /**
     * Stamps every product photo in all four `PRODUCT_IMAGE_SELECTOR_GROUPS` with the index of the
     * media it shows, so previews can be mapped onto "product image #n" rather than onto the nth node
     * of a `querySelectorAll` result - see `CLONED_SLIDE_SELECTOR` for why those two are not the same
     * thing.
     *
     * The media order is taken from the main slider's own non-cloned images, which is the order the
     * product's images are rendered in. Identity is the url core's `sw_thumbnails` puts in `src` (or,
     * for the zoom modal's deferred copies, `data-src`): the plain original media url, byte-identical
     * across all four groups for the same image, and inherited unchanged by tiny-slider's clones -
     * which is what lets a single pass stamp main slide, thumbnail, both zoom modal copies and every
     * clone of any of them consistently.
     *
     * Runs before SlimUi is even loaded, i.e. while every image still carries its original url. Both
     * possible orderings are handled: whether or not the gallery slider has already cloned its slides
     * by this point, cloned nodes are skipped when establishing the order and then stamped by url like
     * any other.
     */
    _indexProductImages() {
        const root = this._getMediaRoot();
        const mediaOrder = [];

        root.querySelectorAll(MAIN_IMAGE_SELECTOR).forEach((img) => {
            const url = this._getOriginalImageUrl(img);

            if (url && !img.closest(CLONED_SLIDE_SELECTOR) && !mediaOrder.includes(url)) {
                mediaOrder.push(url);
            }
        });

        PRODUCT_IMAGE_SELECTOR_GROUPS.forEach((selector) => {
            root.querySelectorAll(selector).forEach((img) => {
                const index = mediaOrder.indexOf(this._getOriginalImageUrl(img));

                if (index >= 0) {
                    img.setAttribute(MEDIA_INDEX_ATTRIBUTE, String(index));
                }
            });
        });

        this._mediaCount = mediaOrder.length;

        this._debugLog('indexProductImages', { mediaCount: this._mediaCount, mediaOrder });
    }

    /**
     * Read as raw attributes rather than through `img.src`/`img.currentSrc`, so that a main image
     * (`src`) and the zoom modal's deferred copy of the same photo (`data-src`) yield the identical
     * string, and a responsive `srcset` pick can't leak a thumbnail url in instead of the original.
     */
    _getOriginalImageUrl(img) {
        return img.getAttribute('data-src') || img.getAttribute('src') || '';
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
     * is no need to track/move a single overlay across slide changes. Cloned slides included: they are
     * their own separate slides as far as the viewer is concerned, and `_setLoadingState` re-queries
     * these from the DOM rather than caching them, so an overlay on a clone tiny-slider only creates
     * later (`rebuild()` on a viewport change re-clones from the live DOM) is still toggled with the
     * rest instead of being stuck at whatever state it was cloned in.
     */
    _attachMediaLoadingOverlays() {
        this._getMediaRoot().querySelectorAll(MAIN_IMAGE_CONTAINER_SELECTOR).forEach((container) => {
            container.appendChild(this._buildLoadingOverlay());
        });
    }

    /**
     * Single source of truth for "is SlimUi currently busy" - used both for the initial state (shown
     * from the very start of `_mountUi()`) and every subsequent `progressStateChangedCallback`
     * invocation. Toggles the product-photo overlay plus the existing dimming applied to every real
     * product image (thumbnail strip/zoom modal included, which don't get a dedicated overlay of their
     * own), and - during the initial load only - the one over the SlimUi mount point.
     */
    _setLoadingState(show) {
        const root = this._getMediaRoot();

        // Deliberately not after `_slimUiMounted` latches: see there.
        if (!this._slimUiMounted) {
            this.uiLoadingOverlay.classList.toggle('printess-loading-overlay--visible', show);
        }

        root.querySelectorAll('.printess-loading-overlay').forEach((overlay) => {
            overlay.classList.toggle('printess-loading-overlay--visible', show);
        });

        PRODUCT_IMAGE_SELECTOR_GROUPS.forEach((selector) => {
            root.querySelectorAll(selector).forEach((node) => {
                node.classList.toggle('printess-preview-loading', show);
            });
        });
    }

    /**
     * Retires the mount-point overlay for good (see `_slimUiMounted`). Idempotent, because both of
     * its callers can be the one that gets there first: a first `renderPreviewImageCallback` can beat
     * `createSlimUi()`'s own promise (the same race `_lastPreviewUrl` exists for), and a template that
     * never reports a preview at all still has to get the overlay off its editor.
     */
    _markSlimUiMounted() {
        if (this._slimUiMounted) {
            return;
        }

        this._debugLog('slimUiMounted: releasing the mount point overlay');

        this._slimUiMounted = true;
        this.uiLoadingOverlay.classList.remove('printess-loading-overlay--visible');
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

    /**
     * Printess-wide convention for "this is a save token, not a template name" - see
     * `printessEditor.ts`/`printess-shopify.ts`, which branch on the same prefix.
     */
    _isSaveToken(templateName) {
        return (templateName || '').startsWith('st:');
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

        // The product's `PrintessSlimUiMergeTemplate` setting - one extra template SlimUi merges on
        // top of `templateName` once loaded, its properties/form fields replacing the main template's
        // layout-origin ones. Passing the parameter at all changes SlimUi's own load path (a merge
        // rules out picking a layout snippet, see `createSlimUi()`), so it is only set when the
        // setting actually has a value, never as an empty string.
        //
        // Never merged onto a save token (`st:` prefix - the established marker across the Printess
        // integrations): a save token is a finished, already-personalized design, and merging would
        // replace exactly the layout-origin properties the customer filled in with the merge
        // template's defaults, discarding their work. `PrintessTemplateName` is a template name in
        // normal operation, so this is a safety net rather than a case that is expected to occur -
        // the editor page that really does load save tokens (cart-item-editor.html.twig) is wired to
        // never pass a merge template in the first place.
        if (this.pageNavigationContainer) {
            loadParams.pageNavigation = this.pageNavigationContainer;
        }

        if (this.mergeTemplate && !this._isSaveToken(this.templateName)) {
            loadParams.merge1 = this.mergeTemplate;
        } else if (this.mergeTemplate) {
            this._debugLog('loadSlimUi: template name is a save token, not applying the merge template', this.templateName);
        }

        this._debugLog('loadSlimUi: creating SlimUi instance', loadParams);

        this.slimApi = await slimUiLoader.createSlimUi(loadParams);

        this._markSlimUiMounted();

        if (this.addToBasketTriggerButton) {
            this.addToBasketTriggerButton.disabled = false;
        }

        // `previews` is only readable off the api object, so a first render that got in before this
        // assignment has updated the first product image but not the rest - see `_refreshPreviewImages`.
        if (this._lastPreviewUrl) {
            void this._refreshPreviewImages(this._lastPreviewUrl);
        }

        this._registerGallerySlideSync();
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
     *
     * The callback only ever carries ONE url, for whichever preview page SlimUi just rendered (its
     * `_renderPreviewImageDirect()` passes a single `getPreviewImageInfo()` result, even though the
     * bundled `.d.ts` types the parameter as `string | []`), so the first product image is updated
     * from it right here and any further ones are filled in by `_refreshPreviewImages()`, which has to
     * go and fetch them.
     */
    _onRenderPreviewImageCallback(previewImageUrl) {
        const url = Array.isArray(previewImageUrl) ? previewImageUrl[0] : previewImageUrl;

        this._debugLog('renderPreviewImageCallback', { url, mediaIndex: this._currentMediaIndex });

        this._markSlimUiMounted();

        this._lastPreviewUrl = url;

        // Onto whichever product image the shopper is looking at, which is the page SlimUi just
        // rendered - not necessarily the first one, once `_syncPreviewToGallerySlide` has moved it.
        this._applyPreviewUrlToMediaIndex(this._currentMediaIndex, url);

        // A page switch this plugin asked for changes only WHICH page is on screen, not the design, so
        // every other product image still holds a correct url and re-rendering them would be waste.
        // Consumed once: any later callback is a real design change and does refresh the rest.
        if (this._previewSwitchPending) {
            this._previewSwitchPending = false;
            return;
        }

        void this._refreshPreviewImages(url);
    }

    /**
     * Fills in the product images after the first one for a template with several previews (front/back
     * of a business card, the pages of a calendar, ...).
     *
     * `renderPreviewImageCallback` hands over the rendered page only, so the remaining ones are
     * requested explicitly through `getPreviewImageInfo(previewIndex, pageIndex)` over the flattened
     * `previews` x `pageCount` list - the same walk the Shopify integration's `initThumbnails()` does.
     * Each of those is a `slimui/create` render on Printess' side, so only as many as the gallery can
     * actually show are requested, and they go out in parallel rather than one after the other: the
     * count is bounded by the product's image count, and the shopper is looking at a spinner
     * (`progressStateChangedCallback` is still "busy") until they arrive.
     *
     * The page SlimUi has just rendered is never refetched - `currentPreviewUrl` already is it. Which
     * product image that is depends on where the shopper has navigated the gallery to
     * (`_currentMediaIndex`), not on it always being the first one.
     *
     * Deliberately scoped in both directions:
     * - more preview pages than product images: the surplus has nowhere on the page to go and is
     *   dropped (logged in debug mode) - this integration doesn't grow the gallery with new slides.
     * - more product images than preview pages: the surplus keeps the merchant's own product photos.
     *   A 2-page design on a 6-image product personalizes images 1-2 and leaves the frame detail shot
     *   and the room scene alone, rather than repeating a preview over them.
     */
    async _refreshPreviewImages(currentPreviewUrl) {
        if (this.pageNavigationContainer) {
            // SlimUi renders (and keeps up to date) a thumbnail per page itself, and the gallery's own
            // slides are unreachable with its carousel chrome hidden - so rendering every page a
            // second time onto them would cost a `slimui/create` round trip each for something nobody
            // can see. The one image that IS visible is the current page, already applied by
            // `_onRenderPreviewImageCallback()`, which fires again on every page switch.
            return;
        }

        if (!this.slimApi) {
            // The initial render is started by `loadTemplate()` without being awaited, so its callback
            // can in principle beat `createSlimUi()`'s own promise. `_loadSlimUi()` retries from
            // `_lastPreviewUrl` once the api object exists.
            this._debugLog('refreshPreviewImages: SlimUi api not ready yet, deferring');
            return;
        }

        const pages = this._getPreviewPages();

        if (pages.length > this._mediaCount) {
            this._debugLog(`refreshPreviewImages: template has ${pages.length} preview page(s) but the product only has ${this._mediaCount} image(s), dropping the surplus`);
        }

        // Kept even when there is nothing to fetch: it is also what tells
        // `_syncPreviewToGallerySlide` which product images have a preview page behind them at all.
        this._previewPages = pages.slice(0, this._mediaCount);

        const wanted = this._previewPages;

        if (wanted.length < 2) {
            return;
        }

        const requestId = ++this._previewRequestId;
        const currentMediaIndex = this._currentMediaIndex;

        try {
            const urls = await Promise.all(wanted.map((page, index) => (index === currentMediaIndex
                ? Promise.resolve(currentPreviewUrl)
                : this.slimApi.getPreviewImageInfo(page.previewIndex, page.pageIndex).then((info) => info.url))));

            if (requestId !== this._previewRequestId) {
                this._debugLog('refreshPreviewImages: superseded by a newer render, discarding');
                return;
            }

            this._debugLog('refreshPreviewImages: applying preview urls', urls);

            this._applyPreviewUrlsToMedia(urls);
        } catch (error) {
            // A failed preview render leaves the product images as they are - the design is still
            // intact and add-to-basket still works, so this is not worth interrupting the shopper for.
            this._debugLog('refreshPreviewImages: failed', error);
        }
    }

    /**
     * Every renderable preview page as a flat `{ previewIndex, pageIndex }` list, in the order the
     * previews and their pages are defined in the template. A template with no `previews` at all still
     * has its one implicit primary page, which is what `getPreviewImageInfo(0, 0)` renders.
     */
    _getPreviewPages() {
        const previews = this.slimApi.previews?.length ? this.slimApi.previews : [{ pageCount: 1 }];
        const pages = [];

        previews.forEach((preview, previewIndex) => {
            const pageCount = preview.pageCount > 0 ? preview.pageCount : 1;

            for (let pageIndex = 0; pageIndex < pageCount; pageIndex++) {
                pages.push({ previewIndex, pageIndex });
            }
        });

        return pages;
    }

    /**
     * Applies preview url N to product image N - every copy of it: main slide, thumbnail, both of the
     * zoom modal's own copies, and any clone tiny-slider has made of any of those, all of which carry
     * the same `MEDIA_INDEX_ATTRIBUTE` stamp (see `_indexProductImages`).
     */
    _applyPreviewUrlsToMedia(urls) {
        urls.forEach((url, index) => this._applyPreviewUrlToMediaIndex(index, url));
    }

    _applyPreviewUrlToMediaIndex(index, url) {
        if (!url) {
            return;
        }

        this._getMediaRoot()
            .querySelectorAll(`[${MEDIA_INDEX_ATTRIBUTE}="${index}"]`)
            .forEach((img) => this._applyPreviewUrlToImage(img, url));
    }

    /**
     * Tells SlimUi which preview page the shopper has navigated the product gallery to, so its own
     * current page follows the image on screen: a template saved with "Properties Per Preview
     * Document" then shows that page's properties, and every later re-render lands on the image the
     * shopper is actually looking at instead of on the first one.
     *
     * Bound to core's own `indexChanged` slider event rather than to clicks on the thumbnails, so it
     * covers every way of changing slides at once - thumbnail, arrows, dots, swipe, keyboard - and
     * re-bound on `afterInitSlider`, which core publishes again after a `rebuild()` (a viewport change
     * crossing a breakpoint destroys and recreates the tiny-slider instance, losing listeners with it).
     *
     * Gated behind the `thumbnailNavigation` feature, and inactive when `pageNavigation` is on: SlimUi
     * renders its own page strip there and the gallery's slide-switching chrome is hidden, so there is
     * nothing to follow.
     * `setPreview()` is also feature-detected - it is absent from the `.d.ts` this plugin vendors, so
     * an older editor build may not have it, and page switching being ignored is a far better outcome
     * than a `TypeError` taking the rest of the integration down with it.
     */
    _registerGallerySlideSync() {
        if (!this._hasFeature(THUMBNAIL_NAVIGATION_FEATURE) || this.pageNavigationContainer) {
            return;
        }

        if (typeof this.slimApi?.setPreview !== 'function') {
            this._debugLog('registerGallerySlideSync: this SlimUi build has no setPreview(), gallery slide changes will not be forwarded');
            return;
        }

        // Absent for a single-image gallery: core only renders the attribute when there is more than
        // one image, and with one image there is no slide to change.
        const sliderEl = this._getMediaRoot().querySelector(GALLERY_SLIDER_SELECTOR);

        if (!sliderEl) {
            return;
        }

        const sliderPlugin = window.PluginManager.getPluginInstanceFromElement(sliderEl, 'GallerySlider');

        if (!sliderPlugin) {
            this._debugLog('registerGallerySlideSync: no GallerySlider plugin instance on the gallery, slide changes will not be forwarded');
            return;
        }

        this._boundGallerySlideChanged = () => this._syncPreviewToGallerySlide(sliderPlugin);

        const attach = () => {
            if (!sliderPlugin._slider) {
                return;
            }

            sliderPlugin._slider.events.off('indexChanged', this._boundGallerySlideChanged);
            sliderPlugin._slider.events.on('indexChanged', this._boundGallerySlideChanged);
        };

        attach();
        sliderPlugin.$emitter.subscribe('afterInitSlider', attach);
    }

    /**
     * The active slide is identified by the `MEDIA_INDEX_ATTRIBUTE` stamp on its own image rather than
     * by the slider's index, which counts tiny-slider's clones as slides of their own - a clone
     * carries its source's stamp, so reading it works whichever copy happens to be the active one.
     */
    _syncPreviewToGallerySlide(sliderPlugin) {
        if (!sliderPlugin._slider) {
            return;
        }

        const activeSlide = sliderPlugin.getActiveSlideElement();
        const img = activeSlide ? activeSlide.querySelector(`[${MEDIA_INDEX_ATTRIBUTE}]`) : null;
        const mediaIndex = img ? Number(img.getAttribute(MEDIA_INDEX_ATTRIBUTE)) : NaN;

        if (!Number.isInteger(mediaIndex) || mediaIndex === this._currentMediaIndex) {
            return;
        }

        const page = this._previewPages[mediaIndex];

        if (!page) {
            // One of the merchant's own product photos, with no preview page behind it. SlimUi stays
            // on the page it is on - moving it would repaint that page onto this photo's slot.
            this._debugLog(`syncPreviewToGallerySlide: product image ${mediaIndex} has no preview page, leaving SlimUi on ${this._currentMediaIndex}`);
            return;
        }

        this._debugLog('syncPreviewToGallerySlide: setPreview', { mediaIndex, ...page });

        this._currentMediaIndex = mediaIndex;
        this._previewSwitchPending = true;

        this.slimApi.setPreview(page.previewIndex, page.pageIndex);
    }

    /**
     * A dynamically generated design preview has no precomputed responsive thumbnail variants, so any
     * `srcset`/`<source>` markup core's `sw_thumbnails` normally renders (which would otherwise take
     * priority over a plain `src` change inside a `<picture>`) is stripped before applying the url.
     *
     * The zoom modal's own image copies are rendered by `sw_thumbnails` with `load: false`, i.e. as
     * `data-src`/`data-srcset` rather than `src`/`srcset` (see `thumbnail.html.twig`), because core's
     * `ZoomModalPlugin` defers loading the fullscreen-sized images until the modal is actually opened:
     * its `_loadImages()` then copies `data-src` -> `src` and `data-srcset` -> `srcset` on every
     * `.js-load-img` still carrying a `data-src`. Setting `src` alone would therefore be undone the
     * moment the overlay opens, the original product photo reappearing in it - so the deferred
     * attributes are dropped here, which is precisely the state core itself leaves an image in once it
     * has loaded it, and puts the img into the `_loadImages()` "already loaded" path (it selects on
     * `data-src`) instead of the deferred one. Deliberately dropped rather than repointed at the
     * preview url: an `img` whose `src` is reassigned to the value it already has is not guaranteed to
     * fire `load` again, and `_loadImages()` only shows the modal from its images' `load`/`error`
     * handlers - so repointing risks an overlay that never opens at all. Nothing is loaded any earlier
     * by this: the url set below is the same one the visible gallery image already fetched.
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
        img.removeAttribute('data-src');
        img.removeAttribute('data-srcset');
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
