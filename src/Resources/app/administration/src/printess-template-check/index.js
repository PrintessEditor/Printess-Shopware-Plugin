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

import template from './printess-template-check.html.twig';
import './printess-template-check.scss';

const SNIPPET_PREFIX = 'printess-shopware-integration.productDetail.templateCheck';

/**
 * "Template check" card of the product's Printess tab: compares the product's variant property
 * groups and options with the template's form fields, and offers to build them from the template
 * (`printess-variant-sync-modal`).
 *
 * The comparison runs on the server, against what is *saved*: the merchant typically has just fixed
 * a property or the template, so neither the page's copy of the product nor a cached template would
 * do. The server returns issue codes with their data, rendered here in the admin's language.
 */
export default Shopware.Component.wrapComponentConfig({
    template,

    inject: {
        printessTemplateService: 'printessTemplateService',
        // Provided by `sw-product-detail`; reloads the product after the sync changed its variants.
        swProductDetailLoadAll: { from: 'swProductDetailLoadAll', default: null },
    },

    props: {
        productId: {
            type: String,
            required: true,
        },

        templateName: {
            type: String,
            required: false,
            default: '',
        },

        product: {
            type: Object,
            required: false,
            default: null,
        },

        canEdit: {
            type: Boolean,
            required: false,
            default: false,
        },
    },

    data() {
        return {
            isChecking: false,
            checkError: null,
            groups: [],
            isVariant: false,
            checkedAt: null,
            requestId: 0,
            showSyncModal: false,
            appliedMessage: null,
        };
    },

    computed: {
        trimmedTemplateName() {
            return (this.templateName || '').trim();
        },

        checkedAtLabel() {
            if (!this.checkedAt) {
                return '';
            }

            const time = new Date(this.checkedAt).toLocaleTimeString(Shopware.Store.get('session').currentLocale || undefined);

            return this.$t(`${SNIPPET_PREFIX}.checkedAt`, { time });
        },
    },

    watch: {
        trimmedTemplateName: {
            immediate: true,
            handler() {
                this.runCheck();
            },
        },

        productId() {
            this.runCheck();
        },
    },

    methods: {
        async runCheck() {
            const templateName = this.trimmedTemplateName;
            const requestId = ++this.requestId;

            this.checkError = null;

            if (!templateName || !this.productId) {
                this.groups = [];
                this.checkedAt = null;

                return;
            }

            this.isChecking = true;

            try {
                const result = await this.printessTemplateService.checkTemplate(this.productId, templateName);

                // A newer check (another template picked meanwhile) wins.
                if (requestId !== this.requestId) {
                    return;
                }

                this.groups = Array.isArray(result?.groups) ? result.groups : [];
                this.isVariant = !!result?.isVariant;
                this.checkedAt = result?.checkedAt ?? null;
            } catch (error) {
                if (requestId !== this.requestId) {
                    return;
                }

                this.checkError = error?.response?.data?.error ?? error?.message ?? this.$t(`${SNIPPET_PREFIX}.checkFailed`);
            } finally {
                if (requestId === this.requestId) {
                    this.isChecking = false;
                }
            }
        },

        headlineText(headline) {
            return this.$t(`${SNIPPET_PREFIX}.${headline.code}`, {
                option: headline.option,
                spelled: headline.spelled,
                field: headline.field,
            });
        },

        detailText(detail) {
            switch (detail.code) {
                case 'valueCase':
                case 'valueTranslation':
                    return this.$t(`${SNIPPET_PREFIX}.${detail.code}`, {
                        pairs: detail.pairs.map(([product, templateSpelling]) => `“${product}” / “${templateSpelling}”`).join(', '),
                    });
                case 'countDiffers':
                    return this.$t(`${SNIPPET_PREFIX}.countDiffers`, {
                        option: detail.option,
                        productCount: detail.productCount,
                        templateCount: detail.templateCount,
                    });
                case 'onlyInProduct':
                case 'onlyInTemplate':
                    return this.$t(`${SNIPPET_PREFIX}.${detail.code}`, { values: this.quoted(detail.values) });
                default:
                    return this.$t(`${SNIPPET_PREFIX}.${detail.code}`, {
                        option: detail.option,
                        field: detail.field,
                    });
            }
        },

        quoted(values) {
            return values.map((value) => `“${value}”`).join(', ');
        },

        /** Every detail is a warning, except the neutral note that a free field isn't compared. */
        isWarningDetail(detail) {
            return detail.code !== 'freeField';
        },

        openSyncModal() {
            this.appliedMessage = null;
            this.showSyncModal = true;
        },

        closeSyncModal() {
            this.showSyncModal = false;
        },

        async onApplied(applied) {
            this.showSyncModal = false;
            this.appliedMessage = this.$t(`${SNIPPET_PREFIX}.applied`, {
                created: applied?.created ?? 0,
                updated: applied?.updated ?? 0,
                deleted: applied?.deleted ?? 0,
            });

            if (typeof this.swProductDetailLoadAll === 'function') {
                await this.swProductDetailLoadAll();
            }

            await this.runCheck();
        },
    },
});
