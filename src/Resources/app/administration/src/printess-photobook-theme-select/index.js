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

import template from './printess-photobook-theme-select.html.twig';
import './printess-photobook-theme-select.scss';

const NO_THEME_VALUE = '';

/**
 * Magic Photobook theme picker for `sw-product-detail-printess`. The available themes are managed
 * inside the Printess account itself (`/user/settings/read`, key `magicPhotobookThemes`), so they're
 * fetched rather than hardcoded - mirrors `printess-print-setting-select` in structure/error handling,
 * but also renders each theme's thumbnail next to its name. Defaults to "Do not use", since most
 * products aren't Magic Photobooks at all.
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
            themes: [],
        };
    },

    computed: {
        selectOptions() {
            return [
                {
                    value: NO_THEME_VALUE,
                    label: this.$t('printess-shopware-integration.photobookThemeSelect.noThemeOption'),
                    thumbnailUrl: null,
                },
                ...this.themes.map((theme) => ({
                    value: theme.name,
                    label: theme.name,
                    thumbnailUrl: theme.thumbnailUrl,
                })),
            ];
        },
    },

    created() {
        this.loadThemes();
    },

    methods: {
        loadThemes() {
            this.isLoading = true;
            this.loadError = null;

            return this.printessTemplateService.getPhotobookThemes()
                .then((response) => {
                    this.themes = response?.themes ?? [];
                })
                .catch((error) => {
                    this.themes = [];
                    this.loadError = error?.response?.data?.error
                        ?? error?.message
                        ?? this.$t('printess-shopware-integration.photobookThemeSelect.loadError');
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
