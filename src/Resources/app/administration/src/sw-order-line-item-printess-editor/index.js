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

import template from './sw-order-line-item-printess-editor.html.twig';
import './sw-order-line-item-printess-editor.scss';

const SAVE_TOKEN_PAYLOAD_KEY = '_printessSaveToken';
const THUMBNAIL_URL_PAYLOAD_KEY = '_printessThumbnailUrl';

/**
 * Full-page (not nested in `sw-order-detail`'s own tabs) view for editing the personalized design
 * of a single order line item, reached by clicking the save token in the order's line item grid
 * (see `printess-order-line-item-production`). A deliberately minimal editor integration compared
 * to the storefront's `printess-design-now` plugin: it only ever loads a single, already-existing
 * save token (never a product's configured template name, since there's no "product" here, just an
 * already-personalized line item) and only implements the two callbacks this page actually needs.
 *
 * Mirrors the legacy WooCommerce integration's `printess_edit_order_line_item` admin page in
 * spirit: edit an order's save token directly, and go back to the order once done (or cancelled).
 */
export default Shopware.Component.wrapComponentConfig({
    template,

    inject: ['repositoryFactory', 'printessTemplateService', 'printessProductionService'],

    data() {
        return {
            isLoading: true,
            loadError: null,
            lineItem: null,
            printessApi: null,
            showStartNowConfirm: false,
            isStartingNow: false,
        };
    },

    computed: {
        orderId() {
            return this.$route.params.orderId;
        },

        lineItemId() {
            return this.$route.params.lineItemId;
        },

        orderLineItemRepository() {
            return this.repositoryFactory.create('order_line_item');
        },

        currentSaveToken() {
            return this.lineItem?.payload?.[SAVE_TOKEN_PAYLOAD_KEY] ?? null;
        },
    },

    created() {
        this.createdComponent();
    },

    beforeUnmount() {
        // The loader keeps its editor UI/instance in the DOM (see the storefront plugin's own
        // `printessApi` re-use pattern) - torn down explicitly here since this page's own DOM goes
        // away on navigation, unlike the storefront's persistent single-page design.
        this.printessApi?.ui?.hide();
    },

    methods: {
        async createdComponent() {
            try {
                // Sequential, not parallel: `getEditorConfig()` needs the line item's `productId` to
                // resolve its `photobookTheme` (see `editorConfig()` on the PHP side).
                this.lineItem = await this.orderLineItemRepository.get(this.lineItemId, Shopware.Context.api);
                const editorConfig = await this.printessTemplateService.getEditorConfig(undefined, this.lineItem.productId);

                if (!this.currentSaveToken) {
                    this.loadError = this.$t('printess-shopware-integration.orderLineItemEditor.noSaveTokenError');
                    return;
                }

                if (!editorConfig.shopToken) {
                    this.loadError = this.$t('printess-shopware-integration.orderLineItemEditor.notConfiguredError');
                    return;
                }

                await this.createEditor(editorConfig);
            } catch (error) {
                this.loadError = error?.response?.data?.error
                    ?? error?.message
                    ?? this.$t('printess-shopware-integration.orderLineItemEditor.loadError');
            } finally {
                this.isLoading = false;
            }
        },

        async createEditor(editorConfig) {
            const printessLoader = await import(/* webpackIgnore: true */ editorConfig.loaderScriptUrl);

            const loadParams = {
                token: editorConfig.shopToken,
                templateName: this.currentSaveToken,
                templateVersion: 'published',
                usePublishedVersion: true,
                addToBasketCallback: (saveToken, thumbnailUrl) => {
                    this.onAddToBasket(saveToken, thumbnailUrl);
                },
                backButtonCallback: () => {
                    this.goBackToOrder();
                },
            };

            if (editorConfig.defaultTheme) {
                loadParams.theme = editorConfig.defaultTheme;
            }

            if (editorConfig.editorLanguage && editorConfig.editorLanguage !== 'auto') {
                loadParams.translationKey = editorConfig.editorLanguage;
            }

            if (editorConfig.basketThumbnailMaxWidth) {
                loadParams.basketThumbnailMaxWidth = editorConfig.basketThumbnailMaxWidth;
            }

            if (editorConfig.basketThumbnailMaxHeight) {
                loadParams.basketThumbnailMaxHeight = editorConfig.basketThumbnailMaxHeight;
            }

            if (editorConfig.photobookTheme) {
                loadParams.formFields = [{ name: 'PHOTOBOOK_THEME', value: editorConfig.photobookTheme }];
            }

            this.printessApi = await printessLoader.load(loadParams);
        },

        async onAddToBasket(saveToken, thumbnailUrl) {
            const payload = {
                ...this.lineItem.payload,
                [SAVE_TOKEN_PAYLOAD_KEY]: saveToken,
                [THUMBNAIL_URL_PAYLOAD_KEY]: thumbnailUrl,
            };

            // The previous production run (if any) was for the design being replaced - it no
            // longer applies, and re-submitting the corrected design always needs an explicit
            // human approval click again, regardless of the plugin-wide auto/manual setting.
            const customFields = {
                ...this.lineItem.customFields,
                PrintessProductionStatus: 'awaiting_approval',
                PrintessProductionJobId: null,
                PrintessProductionResult: null,
                PrintessProductionError: null,
            };

            await this.saveLineItemPersonalization(payload, customFields);

            // The editor's own UI otherwise stays on top of the page (it isn't a normal DOM
            // element the modal's overlay would stack above), hiding the confirm dialog behind it.
            this.printessApi?.ui?.hide();

            // The line item is now sitting at "awaiting approval" with the corrected design - ask
            // whether to send it straight to production instead of always leaving that for a
            // separate manual step back on the order.
            this.showStartNowConfirm = true;
        },

        async confirmStartNow() {
            this.isStartingNow = true;

            try {
                await this.printessProductionService.approveLineItem(this.orderId, this.lineItemId);
            } catch (error) {
                // The line item stays "awaiting approval" either way - it can still be approved
                // manually from the order if starting production here failed.
            } finally {
                this.isStartingNow = false;
                this.goBackToOrder();
            }
        },

        declineStartNow() {
            this.goBackToOrder();
        },

        /**
         * A plain `orderLineItemRepository.save()` would only ever send the fields Vue's reactivity
         * actually detected as changed - here, just `payload`/`customFields`. But Shopware's own
         * product-line-item consistency validator rejects a `payload` change unless `productId` and
         * `referencedId` are included in that very same write, even when neither is actually
         * changing (verified empirically: the API 400s with `CONTENT__PRODUCT_LINE_ITEM_INCONSISTENT`
         * otherwise). So this writes via the repository's own `httpClient`/`route`/headers directly,
         * bypassing the changeset-diff-based `.save()`, to guarantee both are always included
         * alongside the payload change.
         */
        async saveLineItemPersonalization(payload, customFields) {
            const repository = this.orderLineItemRepository;
            const headers = repository.buildHeaders(Shopware.Context.api);

            await repository.httpClient.patch(`${repository.route}/${this.lineItem.id}`, {
                productId: this.lineItem.productId,
                referencedId: this.lineItem.referencedId,
                payload,
                customFields,
            }, { headers });
        },

        goBackToOrder() {
            this.$router.push({ name: 'sw.order.detail', params: { id: this.orderId } });
        },
    },
});
