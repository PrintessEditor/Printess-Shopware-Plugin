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

const MODAL_SELECTOR = '[data-printess-edit-save-token-modal]';

/**
 * Support/debug tool only rendered on the "My saved designs" page while an administrator is
 * impersonating the customer ("Log in as customer" - see `SalesChannelContext::getImitatingUserId()`
 * in `PrintessSavedDesignController::savedDesignsList()`). Lets the admin repoint one saved design to
 * a different save token/thumbnail URL.
 *
 * There is no Printess API to overwrite a saved design's save token in place, and the only way to
 * create a new saved-design entry at all is the editor SDK's client-side `saveTemplateToShop()` (see
 * `printess-saved-design-resume.plugin.js`'s own use of it) - there is no raw REST endpoint for it.
 * So "editing the token" here means: load the design's current content into a Printess editor instance
 * kept off-screen (never shown to the admin), call `saveTemplateToShop()` with the *same* shop
 * data/display name but the new save token/thumbnail to create a fresh entry, then delete the original
 * entry via the existing `saved-designs/delete` route - only once the new entry is confirmed created,
 * to never lose the design if something fails partway through.
 */
export default class PrintessSavedDesignAdminEditorPlugin extends Plugin {
    init() {
        this.loaderUrl = this.el.dataset.printessLoaderUrl;
        this.shopToken = this.el.dataset.printessShopToken;
        this.deleteSavedDesignUrl = this.el.dataset.printessDeleteSavedDesignUrl;

        this.textSaveTokenRequired = this.el.dataset.printessTextSaveTokenRequired || 'A save token is required.';
        this.textSaving = this.el.dataset.printessTextSaving || 'Saving…';
        this.textError = this.el.dataset.printessTextError || 'Something went wrong.';

        this._registerModals();
    }

    _registerModals() {
        this.el.querySelectorAll(MODAL_SELECTOR).forEach((modalEl) => {
            const confirmButton = modalEl.querySelector('[data-printess-edit-save-token-confirm]');

            if (!confirmButton) {
                return;
            }

            confirmButton.addEventListener('click', () => {
                this._onConfirm(modalEl);
            });
        });
    }

    async _onConfirm(modalEl) {
        const saveTokenInput = modalEl.querySelector('[data-printess-save-token-input]');
        const thumbnailUrlInput = modalEl.querySelector('[data-printess-thumbnail-url-input]');
        const statusEl = modalEl.querySelector('[data-printess-edit-save-token-status]');

        const newSaveToken = (saveTokenInput?.value || '').trim();
        const newThumbnailUrl = (thumbnailUrlInput?.value || '').trim();

        if (!newSaveToken) {
            this._setStatus(statusEl, this.textSaveTokenRequired, true);
            return;
        }

        const originalSaveToken = modalEl.dataset.printessOriginalSaveToken;
        const productId = modalEl.dataset.printessProductId;
        const oldSortKey = modalEl.dataset.printessSortKey;
        const displayName = modalEl.dataset.printessDisplayName || '';
        const shopData = this._parseJsonAttribute(modalEl.dataset.printessShopData) ?? {};

        this._setBusy(modalEl, true);
        this._setStatus(statusEl, this.textSaving, false);

        try {
            await this._createReplacementDesign(originalSaveToken, shopData, newSaveToken, newThumbnailUrl, displayName);
            await this._deleteOldDesign(productId, oldSortKey);

            window.location.reload();
        } catch (error) {
            console.log('[Printess] admin edit-save-token: failed', error);
            this._setStatus(statusEl, this.textError, true);
            this._setBusy(modalEl, false);
        }
    }

    /**
     * Loads the design's current content off-screen (never shown - immediately hidden once the API is
     * ready) purely to get a live `printessApi.api` handle, then calls `saveTemplateToShop()` with the
     * unchanged `shopData`/display name but the new save token/thumbnail.
     */
    async _createReplacementDesign(originalSaveToken, shopData, newSaveToken, newThumbnailUrl, displayName) {
        const printessLoader = await import(/* webpackIgnore: true */ this.loaderUrl);

        const printessApi = await printessLoader.load({
            token: this.shopToken,
            templateName: originalSaveToken,
            templateVersion: 'published',
            usePublishedVersion: true,
        });

        printessApi.ui.hide();

        await printessApi.api.saveTemplateToShop({
            saveToken: newSaveToken,
            displayName,
            thumbnailUrl: newThumbnailUrl,
            shopData,
        });
    }

    async _deleteOldDesign(productId, sortKey) {
        await fetch(this.deleteSavedDesignUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: new URLSearchParams({ productId, sortKey }).toString(),
        });
    }

    _setBusy(modalEl, busy) {
        modalEl.querySelectorAll('button').forEach((button) => {
            button.disabled = busy;
        });
    }

    _setStatus(statusEl, message, isError) {
        if (!statusEl) {
            return;
        }

        statusEl.textContent = message;
        statusEl.classList.remove('d-none');
        statusEl.classList.toggle('text-danger', !!isError);
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
