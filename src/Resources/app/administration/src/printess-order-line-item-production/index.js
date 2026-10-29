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
            showFormFields: false,
            isLoadingFormFields: false,
            formFieldsError: null,
            // The line items an approve/restart sends along, shown for confirmation first.
            batch: null,
            batchAction: null,
            // Restart of a dropship group, when the settings allow duplicating the order instead:
            // 'resend' (again under the order number with a suffix) or 'copy'.
            reproductionChoice: 'resend',
            copyLineItemIds: [],
            copyMessage: '',
            copyReduceStock: false,
            // The order created by the last "duplicate and send the copy", for the link.
            createdCopy: null,
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

        /** Whether the restart dialog offers duplicating the order. */
        canReproduceAsCopy() {
            return this.batchAction === 'restart' && this.batch?.dropship && this.batch?.reproductionMode === 'ask';
        },

        isCopyChoice() {
            return this.canReproduceAsCopy && this.reproductionChoice === 'copy';
        },

        canConfirmBatch() {
            return !this.isCopyChoice || (this.copyLineItemIds.length > 0 && this.copyMessage.trim() !== '');
        },

        /** `{id, orderNumber}` of the order this one is a reproduction of, if any. */
        reproductionOf() {
            return this.parseCustomField(this.order.customFields?.PrintessReproductionOf, null);
        },

        /** The copies of this order that contain this line item. */
        reproducedAs() {
            const copies = this.parseCustomField(this.order.customFields?.PrintessReproducedAs, []);

            return (Array.isArray(copies) ? copies : [])
                .filter((copy) => Array.isArray(copy?.lineItemIds) && copy.lineItemIds.includes(this.lineItem.id));
        },

        /** The order number the dropshipper got for this item's last send, e.g. `10015-R1`. */
        dropshipShopOrderId() {
            return this.lineItem.customFields?.PrintessDropshipShopOrderId ?? null;
        },

        /**
         * Shipments the dropshipper reported via Printess's dropship callback, newest last:
         * `[{trackingId, trackingUrl, shippedAt}]`.
         */
        dropshipTracking() {
            try {
                const parsed = JSON.parse(this.lineItem.customFields?.PrintessDropshipTracking ?? '[]');

                // Only web links - the URL comes from the dropshipper and ends up in an href.
                return (Array.isArray(parsed) ? parsed : []).map((shipment) => ({
                    trackingId: shipment?.trackingId ?? '',
                    trackingUrl: /^https?:\/\//i.test(shipment?.trackingUrl ?? '') ? shipment.trackingUrl : '',
                })).filter((shipment) => shipment.trackingId || shipment.trackingUrl);
            } catch (error) {
                return [];
            }
        },

        /** No status yet: production (or approval) starts once the order is paid. */
        isAwaitingPayment() {
            return this.status === null;
        },

        /** Restart only redoes production that already happened - the server refuses it otherwise. */
        canRestart() {
            return [STATUS_IN_PRODUCTION, STATUS_COMPLETED, STATUS_ERROR].includes(this.status);
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

        /**
         * The values the design was produced with, from the job's form fields file (stored by
         * `PrintessProductionService::downloadFormFields()`): `{fields: [{name, caption, value,
         * rawValue}], images: [{exchangeId, name, url}]}`, or null when none are stored.
         */
        formFields() {
            const data = this.parseCustomField(this.lineItem.customFields?.PrintessProductionFormFields, null);

            if (!data || typeof data.environment !== 'object') {
                return null;
            }

            const detailed = data.environmentDetailed ?? {};

            const fields = Object.entries(data.environment).map(([name, value]) => {
                const details = detailed[name] ?? null;
                // A text field's details carry its caption as `label`; a select list's are the
                // chosen entry, whose `label` is what the customer picked.
                const isTextField = details !== null && 'maxCharacters' in details;
                const rawValue = String(value ?? '');

                return {
                    name,
                    caption: isTextField && details.label ? details.label : name,
                    value: !isTextField && details?.label ? details.label : rawValue,
                    rawValue,
                };
            });

            const images = Object.entries(data.exchangeIds ?? {}).map(([exchangeId, exchange]) => {
                const scales = Array.isArray(exchange?.images) ? exchange.images : [];
                // Sorted from small to big - link the biggest. Only web links, it ends up in an href.
                const url = scales[scales.length - 1]?.url ?? '';

                return {
                    exchangeId,
                    name: exchange?.name ?? exchangeId,
                    url: /^https?:\/\//i.test(url) ? url : '',
                };
            });

            return { fields, images };
        },
    },

    methods: {
        editSaveToken() {
            this.openEditor(this.lineItem.id);
        },

        /** Also used by the approve/restart dialog, for any line item of the batch. */
        openEditor(lineItemId) {
            // A router.push() never actually reloads the page - the admin is a single-page app,
            // so any Printess editor instance already sitting in the document (loaded by this same
            // page on a previous visit) would stay mounted underneath a newly created one instead
            // of being torn down. The Printess loader isn't built to have two instances of itself
            // coexist, so force a real full-page navigation here to guarantee the editor page
            // always starts from a completely clean document.
            const href = this.$router.resolve({
                name: 'sw.order.printess.lineItemEditor',
                params: { orderId: this.order.id, lineItemId },
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

        /**
         * Items produced before the values were stored get them from Printess on first open, via
         * the status check - as long as Printess still has the file (30 days).
         */
        openFormFields() {
            this.showFormFields = true;
            this.formFieldsError = null;

            if (this.formFields) {
                return;
            }

            this.isLoadingFormFields = true;

            this.printessProductionService.checkLineItemStatus(this.order.id, this.lineItem.id)
                .then((state) => this.applyState(state))
                .catch((error) => {
                    this.formFieldsError = error?.response?.data?.error ?? error?.message ?? null;
                })
                .finally(() => {
                    this.isLoadingFormFields = false;
                });
        },

        closeFormFields() {
            this.showFormFields = false;
        },

        approve() {
            this.prepareBatch('approve');
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
            this.prepareBatch('restart');
        },

        /**
         * Loads what approving or restarting sends to production. A dropshipped line item takes all
         * line items going to the same address along, so the merchant confirms that list - with
         * the designs - first. Anything else runs right away, as before.
         */
        prepareBatch(action) {
            this.isLoading = true;
            this.actionError = null;

            this.printessProductionService.getProductionBatch(this.order.id, this.lineItem.id)
                .then((batch) => {
                    if (batch?.dropship) {
                        this.batch = batch;
                        this.batchAction = action;
                        this.reproductionChoice = 'resend';
                        this.copyLineItemIds = (batch.lineItems ?? []).map((item) => item.id);
                        this.copyMessage = this.$t('printess-shopware-integration.productionBatch.copyMessageDefault');
                        this.copyReduceStock = false;
                        this.isLoading = false;

                        return null;
                    }

                    return this.runBatchAction(action);
                })
                .catch((error) => {
                    this.actionError = error?.response?.data?.error ?? error?.message ?? null;
                    this.isLoading = false;
                });
        },

        confirmBatch() {
            const action = this.isCopyChoice ? 'copy' : this.batchAction;

            this.closeBatch();
            this.isLoading = true;
            this.runBatchAction(action).catch((error) => {
                this.actionError = error?.response?.data?.error ?? error?.message ?? null;
                this.isLoading = false;
            });
        },

        closeBatch() {
            this.batch = null;
            this.batchAction = null;
        },

        runBatchAction(action) {
            let request;

            if (action === 'approve') {
                request = this.printessProductionService.approveLineItem(this.order.id, this.lineItem.id);
            } else if (action === 'copy') {
                request = this.printessProductionService.restartLineItem(this.order.id, this.lineItem.id, {
                    mode: 'copy',
                    lineItemIds: this.copyLineItemIds,
                    message: this.copyMessage,
                    reduceStock: this.copyReduceStock,
                });
            } else {
                request = this.printessProductionService.restartLineItem(this.order.id, this.lineItem.id);
            }

            return request
                .then((response) => {
                    this.applyStates(response?.lineItems ?? {});
                    this.createdCopy = response?.copy ?? null;
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },

        /**
         * Updates every line item the action sent - the other rows of the grid show the same
         * `order.lineItems` objects, so they update too.
         */
        applyStates(states) {
            Object.entries(states).forEach(([id, state]) => {
                const lineItem = id === this.lineItem.id
                    ? this.lineItem
                    : this.order.lineItems?.find((item) => item.id === id);

                if (lineItem) {
                    this.applyState(state, lineItem);
                }
            });
        },

        parseCustomField(value, fallback) {
            try {
                return value ? JSON.parse(value) : fallback;
            } catch (error) {
                return fallback;
            }
        },

        orderRoute(orderId) {
            return { name: 'sw.order.detail', params: { id: orderId } };
        },

        applyState(state, lineItem = this.lineItem) {
            lineItem.customFields = {
                ...lineItem.customFields,
                PrintessProductionStatus: state.status,
                PrintessProductionJobId: state.jobId,
                PrintessProductionResult: state.result ? JSON.stringify(state.result) : null,
                PrintessProductionError: state.error,
                PrintessProductionFormFields: state.formFields ? JSON.stringify(state.formFields) : null,
            };
        },
    },
});
