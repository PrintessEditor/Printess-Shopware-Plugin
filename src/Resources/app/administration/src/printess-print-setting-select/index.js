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

import template from './printess-print-setting-select.html.twig';
import './printess-print-setting-select.scss';

const NO_SETTING_VALUE = '';

/**
 * Print/output setting picker (format, resolution, ...), used both as the plugin-wide default
 * (embedded into the extension config page via config.xml's `<component>` field) and as the
 * per-product override in `sw-product-detail-printess`. The available settings are user-defined
 * inside Printess itself, so they're fetched from the Printess account (`/user/settings/read`,
 * key `print-settings-list`) rather than hardcoded.
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
            printSettings: [],
        };
    },

    computed: {
        selectOptions() {
            return [
                {
                    value: NO_SETTING_VALUE,
                    label: this.$t('printess-shopware-integration.printSettingSelect.noSettingOption'),
                },
                ...this.printSettings.map((name) => ({ value: name, label: name })),
            ];
        },
    },

    created() {
        this.loadPrintSettings();
    },

    methods: {
        loadPrintSettings() {
            this.isLoading = true;
            this.loadError = null;

            return this.printessTemplateService.getPrintSettings()
                .then((response) => {
                    this.printSettings = response?.printSettings ?? [];
                })
                .catch((error) => {
                    this.printSettings = [];
                    this.loadError = error?.response?.data?.error
                        ?? error?.message
                        ?? this.$t('printess-shopware-integration.printSettingSelect.loadError');
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
