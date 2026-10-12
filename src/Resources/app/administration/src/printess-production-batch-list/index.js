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

import template from './printess-production-batch-list.html.twig';
import './printess-production-batch-list.scss';

/**
 * The line items an approve or restart sends to production together (see the
 * `production-batch` endpoint), each with its personalization thumbnail, so the merchant sees
 * exactly which designs go out before confirming. A thumbnail opens full size on click; with
 * `editable`, each line item also gets a button that emits `edit` with its id.
 */
export default Shopware.Component.wrapComponentConfig({
    template,

    emits: ['edit', 'update:selectedIds'],

    props: {
        items: {
            type: Array,
            required: true,
        },

        editable: {
            type: Boolean,
            required: false,
            default: false,
        },

        /** Shows a checkbox per line item; the checked ids are `selectedIds` (v-model). */
        selectable: {
            type: Boolean,
            required: false,
            default: false,
        },

        selectedIds: {
            type: Array,
            required: false,
            default: () => [],
        },
    },

    data() {
        return {
            fullSizeThumbnailUrl: null,
        };
    },

    methods: {
        openThumbnail(item) {
            this.fullSizeThumbnailUrl = item.thumbnailUrl;
        },

        closeThumbnail() {
            this.fullSizeThumbnailUrl = null;
        },

        toggleSelected(id, checked) {
            const selected = this.selectedIds.filter((selectedId) => selectedId !== id);

            this.$emit('update:selectedIds', checked ? [...selected, id] : selected);
        },
    },
});
