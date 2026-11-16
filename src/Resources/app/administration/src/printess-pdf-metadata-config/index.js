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

import template from './printess-pdf-metadata-config.html.twig';
import './printess-pdf-metadata-config.scss';
import {
    OPTION_VARIABLE_PREFIX,
    buildSampleValues,
    loadPropertyGroupNames,
    token,
    uniqueGroupNames,
} from '../service/printess-placeholder-variables';

const CONFIG_DOMAIN = 'PrintessShopwareIntegration.config';
const CONFIG_KEY = `${CONFIG_DOMAIN}.pdfMetadata`;

/**
 * Setting key => form field name sent to Printess. Mirrors `PdfMetadataResolver::FORM_FIELDS`.
 */
const FIELDS = [
    { key: 'title', formField: 'PDF_Title' },
    { key: 'subject', formField: 'PDF_Subject' },
    { key: 'author', formField: 'PDF_Author' },
];

const PLACEHOLDER_EXAMPLES = {
    title: `Order ${token('order.number')} - ${token('product.name')}`,
    subject: `${token('product.name')} (${token('product.variant')})`,
    author: token('order.salesChannel'),
};

/**
 * Accepts the stored object or its JSON string (a custom field can be written through the API).
 */
function normalizeValue(value) {
    let parsed = value;

    if (typeof parsed === 'string' && parsed !== '') {
        try {
            parsed = JSON.parse(parsed);
        } catch (error) {
            parsed = null;
        }
    }

    const result = {};

    FIELDS.forEach(({ key }) => {
        result[key] = parsed && typeof parsed[key] === 'string' ? parsed[key] : '';
    });

    return result;
}

/**
 * Editor for the PDF document properties (`PDF_Title`, `PDF_Subject`, `PDF_Author`) Printess
 * writes into a produced PDF: free text with `{{ variable }}` placeholders that the shop fills in
 * when production starts (see `PdfMetadataResolver`/`OrderPlaceholderResolver` on the PHP side).
 * Each field is a `printess-placeholder-input`.
 *
 * Used both as the plugin-wide setting (config.xml `<component>`, `pdfMetadata`) and as the product
 * override in `sw-product-detail-printess` (`PrintessPdfMetadata` custom field). With
 * `showPluginDefaults`, the plugin values are shown as placeholders of the empty fields, since those
 * are what applies there.
 */
export default Shopware.Component.wrapComponentConfig({
    template,

    inject: ['repositoryFactory', 'systemConfigApiService'],

    emits: ['update:value'],

    props: {
        value: {
            type: [Object, String],
            required: false,
            default: null,
        },

        disabled: {
            type: Boolean,
            required: false,
            default: false,
        },

        /**
         * The option group names to offer as `product.option.<name>` variables. `null` loads every
         * property group of the shop (plugin setting, which applies to all products).
         */
        optionGroupNames: {
            type: Array,
            required: false,
            default: null,
        },

        /**
         * Product tab: explains the override and shows the plugin values in the empty fields.
         */
        showPluginDefaults: {
            type: Boolean,
            required: false,
            default: false,
        },

        /**
         * Product tab: the product's own name/number for the preview, instead of sample values.
         */
        previewProduct: {
            type: Object,
            required: false,
            default: null,
        },
    },

    data() {
        return {
            values: normalizeValue(this.value),
            pluginValues: normalizeValue(null),
            loadedOptionGroupNames: [],
            showHelp: false,
        };
    },

    computed: {
        fields() {
            return FIELDS;
        },

        groupNames() {
            return uniqueGroupNames(this.optionGroupNames ?? this.loadedOptionGroupNames);
        },

        helpParams() {
            const samples = buildSampleValues(this.$t, this.groupNames, this.previewProduct);

            return {
                example: token('order.number'),
                exampleInput: `Order ${token('order.number')} - ${token('product.name')}`,
                exampleOutput: `Order 10042 - ${samples['product.name']}`,
                optionExample: token(`${OPTION_VARIABLE_PREFIX}${this.groupNames[0] ?? 'Color'}`),
            };
        },
    },

    watch: {
        value(newValue) {
            const normalized = normalizeValue(newValue);

            // Don't overwrite what is being typed with the (debounced or echoed) saved value.
            if (FIELDS.some(({ key }) => normalized[key] !== this.values[key])) {
                this.values = normalized;
            }
        },
    },

    created() {
        this.showHelp = FIELDS.every(({ key }) => this.values[key].trim() === '');

        if (this.optionGroupNames === null) {
            loadPropertyGroupNames(this.repositoryFactory).then((names) => {
                this.loadedOptionGroupNames = names;
            });
        }

        if (this.showPluginDefaults) {
            this.loadPluginValues();
        }
    },

    methods: {
        loadPluginValues() {
            return this.systemConfigApiService.getValues(CONFIG_DOMAIN)
                .then((values) => {
                    this.pluginValues = normalizeValue(values?.[CONFIG_KEY] ?? null);
                })
                .catch(() => {
                    this.pluginValues = normalizeValue(null);
                });
        },

        fieldLabel(field) {
            return this.$t(`printess-shopware-integration.pdfMetadataConfig.fields.${field.key}`);
        },

        fieldPlaceholder(field) {
            const pluginValue = this.pluginValues[field.key];

            if (this.showPluginDefaults && pluginValue.trim() !== '') {
                return this.$t('printess-shopware-integration.pdfMetadataConfig.pluginDefaultPlaceholder', { value: pluginValue });
            }

            return this.$t('printess-shopware-integration.pdfMetadataConfig.placeholders.example', {
                example: PLACEHOLDER_EXAMPLES[field.key],
            });
        },

        /**
         * The text that applies to this field: its own, or (product tab) the plugin's.
         */
        effectiveText(field) {
            const own = this.values[field.key];

            if (own.trim() !== '') {
                return own;
            }

            return this.showPluginDefaults ? this.pluginValues[field.key] : '';
        },

        usesPluginDefault(field) {
            return this.showPluginDefaults
                && this.values[field.key].trim() === ''
                && this.pluginValues[field.key].trim() !== '';
        },

        previewLabel(field) {
            return this.usesPluginDefault(field)
                ? this.$t('printess-shopware-integration.pdfMetadataConfig.previewPluginDefaultLabel')
                : this.$t('printess-shopware-integration.pdfMetadataConfig.previewLabel');
        },

        onInput(field, value) {
            this.values = { ...this.values, [field.key]: value ?? '' };
            this.emitValue();
        },

        emitValue() {
            const result = {};

            FIELDS.forEach(({ key }) => {
                if (this.values[key].trim() !== '') {
                    result[key] = this.values[key];
                }
            });

            this.$emit('update:value', Object.keys(result).length ? result : null);
        },
    },
});
