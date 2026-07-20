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

import template from './printess-imposition-select.html.twig';
import './printess-imposition-select.scss';

const NO_IMPOSITION_VALUE = '';

/**
 * Book/cover imposition picker for `sw-product-detail-printess`'s Magic Photobook settings (used for
 * both `bookImposition` and `coverImposition` - same underlying list). The available impositions are
 * managed inside the Printess account itself (`/user/settings/read`, key `impositions`), so they're
 * fetched rather than hand-typed - mirrors `printess-photobook-theme-select` in structure, including
 * the thumbnail-with-name rendering and the "Do not use" default option.
 */
export default Shopware.Component.wrapComponentConfig({
    template,

    inject: ['printessTemplateService'],

    emits: ['update:value'],

    props: {
        value: {
            type: String,
            required: false,
            default: null,
        },

        disabled: {
            type: Boolean,
            required: false,
            default: false,
        },
    },

    data() {
        return {
            isLoading: true,
            loadError: null,
            impositions: [],
        };
    },

    computed: {
        selectOptions() {
            return [
                {
                    value: NO_IMPOSITION_VALUE,
                    label: this.$t('printess-shopware-integration.impositionSelect.noImpositionOption'),
                    thumbnailUrl: null,
                },
                ...this.impositions.map((imposition) => ({
                    value: imposition.name,
                    label: imposition.name,
                    thumbnailUrl: imposition.thumbnailUrl,
                })),
            ];
        },
    },

    created() {
        this.loadImpositions();
    },

    methods: {
        loadImpositions() {
            this.isLoading = true;
            this.loadError = null;

            return this.printessTemplateService.getImpositions()
                .then((response) => {
                    this.impositions = response?.impositions ?? [];
                })
                .catch((error) => {
                    this.impositions = [];
                    this.loadError = error?.response?.data?.error
                        ?? error?.message
                        ?? this.$t('printess-shopware-integration.impositionSelect.loadError');
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },

        onChange(value) {
            this.$emit('update:value', value || null);
        },
    },
});
