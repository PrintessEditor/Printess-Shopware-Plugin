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

import template from './printess-order-line-item-production.html.twig';
import './printess-order-line-item-production.scss';

const STATUS_AWAITING_APPROVAL = 'awaiting_approval';
const STATUS_IN_PRODUCTION = 'in_production';
const STATUS_COMPLETED = 'completed';
const STATUS_ERROR = 'error';

/**
 * Shown per order line item in the order detail's line item grid (see the `sw-order-line-items-grid`
 * override), for any line item that carries a Printess save token (`payload._printessSaveToken`,
 * set at add-to-cart time by the storefront - see `LineItemAddedSubscriber`). Renders whatever the
 * `order_line_item` custom fields written by `PrintessProductionService` currently say: nothing yet
 * (order not paid/production not started), awaiting manual approval, still in production, the
 * finished result's file links, or an error. Also always shows the save token and personalization
 * thumbnail (`payload._printessThumbnailUrl`, set alongside the save token) when present, since
 * seeing the actual design is what the "approve before production" workflow is for.
 */
export default Shopware.Component.wrapComponentConfig({
    template,

    inject: ['printessProductionService'],

    props: {
        order: {
            type: Object,
            required: true,
        },

        lineItem: {
            type: Object,
            required: true,
        },
    },

    data() {
        return {
            isLoading: false,
            actionError: null,
            showThumbnailOverlay: false,
        };
    },

    computed: {
        saveToken() {
            return this.lineItem.payload?._printessSaveToken ?? null;
        },

        thumbnailUrl() {
            return this.lineItem.payload?._printessThumbnailUrl ?? null;
        },

        status() {
            return this.lineItem.customFields?.PrintessProductionStatus ?? null;
        },

        isAwaitingApproval() {
            return this.status === STATUS_AWAITING_APPROVAL;
        },

        isInProduction() {
            return this.status === STATUS_IN_PRODUCTION;
        },

        isCompleted() {
            return this.status === STATUS_COMPLETED;
        },

        isError() {
            return this.status === STATUS_ERROR;
        },

        errorMessage() {
            return this.lineItem.customFields?.PrintessProductionError ?? null;
        },

        result() {
            const raw = this.lineItem.customFields?.PrintessProductionResult;

            if (!raw) {
                return { documents: [], pages: [] };
            }

            try {
                const parsed = typeof raw === 'string' ? JSON.parse(raw) : raw;

                return {
                    documents: Object.entries(parsed.documents ?? {}).map(([name, url]) => ({ name, url })),
                    pages: Array.isArray(parsed.pages) ? parsed.pages : [],
                };
            } catch (error) {
                return { documents: [], pages: [] };
            }
        },
    },

    methods: {
        editSaveToken() {
            // A router.push() never actually reloads the page - the admin is a single-page app,
            // so any Printess editor instance already sitting in the document (loaded by this same
            // page on a previous visit) would stay mounted underneath a newly created one instead
            // of being torn down. The Printess loader isn't built to have two instances of itself
            // coexist, so force a real full-page navigation here to guarantee the editor page
            // always starts from a completely clean document.
            const href = this.$router.resolve({
                name: 'sw.order.printess.lineItemEditor',
                params: { orderId: this.order.id, lineItemId: this.lineItem.id },
            }).href;

            window.location.href = href;
            window.location.reload();
        },

        openThumbnailOverlay() {
            if (this.thumbnailUrl) {
                this.showThumbnailOverlay = true;
            }
        },

        closeThumbnailOverlay() {
            this.showThumbnailOverlay = false;
        },

        approve() {
            this.isLoading = true;
            this.actionError = null;

            this.printessProductionService.approveLineItem(this.order.id, this.lineItem.id)
                .then((state) => this.applyState(state))
                .catch((error) => {
                    this.actionError = error?.response?.data?.error ?? error?.message ?? null;
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },

        checkStatus() {
            this.isLoading = true;
            this.actionError = null;

            this.printessProductionService.checkLineItemStatus(this.order.id, this.lineItem.id)
                .then((state) => this.applyState(state))
                .catch((error) => {
                    this.actionError = error?.response?.data?.error ?? error?.message ?? null;
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },

        restartProduction() {
            this.isLoading = true;
            this.actionError = null;

            this.printessProductionService.restartLineItem(this.order.id, this.lineItem.id)
                .then((state) => this.applyState(state))
                .catch((error) => {
                    this.actionError = error?.response?.data?.error ?? error?.message ?? null;
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },

        applyState(state) {
            this.lineItem.customFields = {
                ...this.lineItem.customFields,
                PrintessProductionStatus: state.status,
                PrintessProductionJobId: state.jobId,
                PrintessProductionResult: state.result ? JSON.stringify(state.result) : null,
                PrintessProductionError: state.error,
            };
        },
    },
});
