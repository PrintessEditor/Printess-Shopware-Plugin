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

const { Criteria } = Shopware.Data;

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

const OPTION_VARIABLE_PREFIX = 'product.option.';

/**
 * Every variable the shop fills in when production starts. Mirrors
 * `PdfMetadataResolver::VARIABLE_KEYS` (keep both in sync); the option group variables
 * (`product.option.<name>`) are added per group at runtime.
 */
const PDF_METADATA_VARIABLE_GROUPS = [
    { key: 'order', variables: ['order.number', 'order.id', 'order.date', 'order.customerComment', 'order.salesChannel'] },
    { key: 'lineItem', variables: ['lineItem.id', 'lineItem.position', 'lineItem.quantity'] },
    { key: 'product', variables: ['product.name', 'product.number', 'product.variant', 'product.options'] },
    { key: 'customer', variables: ['customer.name', 'customer.firstName', 'customer.lastName', 'customer.company', 'customer.email', 'customer.number'] },
    { key: 'shipping', variables: ['shipping.name', 'shipping.company', 'shipping.zip', 'shipping.city', 'shipping.country', 'shipping.method'] },
    { key: 'production', variables: ['production.date'] },
];

const KNOWN_VARIABLES = new Set(PDF_METADATA_VARIABLE_GROUPS.flatMap((group) => group.variables));

const PLACEHOLDER_EXAMPLES = {
    title: `Order ${token('order.number')} - ${token('product.name')}`,
    subject: `${token('product.name')} (${token('product.variant')})`,
    author: token('order.salesChannel'),
};

const PLACEHOLDER_PATTERN = /\{\{\s*([^{}]*?)\s*\}\}/g;

function token(variable) {
    return `{{ ${variable} }}`;
}

function today() {
    return new Date().toISOString().slice(0, 10);
}

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
 * when production starts (see `PdfMetadataResolver` on the PHP side).
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
            pickerFieldKey: null,
            pickerSearch: '',
            showHelp: false,
            cursorPositions: {},
        };
    },

    computed: {
        fields() {
            return FIELDS;
        },

        groupNames() {
            const names = this.optionGroupNames ?? this.loadedOptionGroupNames;

            return [...new Set(names.filter((name) => typeof name === 'string' && name.trim() !== ''))];
        },

        variableGroups() {
            const groups = PDF_METADATA_VARIABLE_GROUPS.map((group) => ({
                key: group.key,
                label: this.$t(`printess-shopware-integration.pdfMetadataConfig.groups.${group.key}`),
                variables: group.variables.map((variable) => ({
                    variable,
                    token: token(variable),
                    label: this.$t(`printess-shopware-integration.pdfMetadataConfig.variables.${variable.replace('.', '_')}`),
                })),
            }));

            if (this.groupNames.length) {
                groups.splice(3, 0, {
                    key: 'options',
                    label: this.$t('printess-shopware-integration.pdfMetadataConfig.groups.options'),
                    variables: this.groupNames.map((name) => ({
                        variable: `${OPTION_VARIABLE_PREFIX}${name}`,
                        token: token(`${OPTION_VARIABLE_PREFIX}${name}`),
                        label: this.$t('printess-shopware-integration.pdfMetadataConfig.optionVariableLabel', { name }),
                    })),
                });
            }

            return groups;
        },

        filteredVariableGroups() {
            const search = this.pickerSearch.trim().toLowerCase();

            if (search === '') {
                return this.variableGroups;
            }

            return this.variableGroups
                .map((group) => ({
                    ...group,
                    variables: group.variables.filter((entry) => entry.label.toLowerCase().includes(search)
                        || entry.variable.toLowerCase().includes(search)),
                }))
                .filter((group) => group.variables.length);
        },

        sampleValues() {
            const productName = this.previewProduct?.name
                || this.$t('printess-shopware-integration.pdfMetadataConfig.sample.productName');
            const optionSamples = this.groupNames.slice(0, 2).map((name, index) => ({
                group: name,
                option: this.$t(`printess-shopware-integration.pdfMetadataConfig.sample.option${index + 1}`),
            }));

            return {
                'order.number': '10042',
                'order.id': '0190f3c4a6d27b8e9c1f2a3b4c5d6e7f',
                'order.date': today(),
                'order.customerComment': this.$t('printess-shopware-integration.pdfMetadataConfig.sample.customerComment'),
                'order.salesChannel': 'Storefront',
                'lineItem.id': '0190f3c4a6d27b8e9c1f2a3b4c5d6e80',
                'lineItem.position': '1',
                'lineItem.quantity': '2',
                'product.name': productName,
                'product.number': this.previewProduct?.number || 'SW10001',
                'product.variant': optionSamples.map((option) => option.option).join(' / '),
                'product.options': optionSamples.map((option) => `${option.group}: ${option.option}`).join(', '),
                'customer.name': 'Jane Doe',
                'customer.firstName': 'Jane',
                'customer.lastName': 'Doe',
                'customer.company': 'Example Inc.',
                'customer.email': 'jane.doe@example.com',
                'customer.number': 'C-10001',
                'shipping.name': 'Jane Doe',
                'shipping.company': 'Example Inc.',
                'shipping.zip': '48151',
                'shipping.city': 'Münster',
                'shipping.country': this.$t('printess-shopware-integration.pdfMetadataConfig.sample.country'),
                'shipping.method': 'Standard',
                'production.date': today(),
                options: optionSamples,
            };
        },

        helpParams() {
            return {
                example: token('order.number'),
                exampleInput: `Order ${token('order.number')} - ${token('product.name')}`,
                exampleOutput: `Order 10042 - ${this.sampleValues['product.name']}`,
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
            this.loadOptionGroupNames();
        }

        if (this.showPluginDefaults) {
            this.loadPluginValues();
        }
    },

    methods: {
        loadOptionGroupNames() {
            const criteria = new Criteria(1, 500);
            criteria.addSorting(Criteria.sort('name', 'ASC', false));

            return this.repositoryFactory.create('property_group')
                .search(criteria, Shopware.Context.api)
                .then((result) => {
                    this.loadedOptionGroupNames = result.map((group) => group.translated?.name ?? group.name);
                })
                .catch(() => {
                    this.loadedOptionGroupNames = [];
                });
        },

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

        /**
         * Mirrors the PHP side: known variables get their (sample) value, everything else becomes empty.
         */
        preview(field) {
            return this.effectiveText(field)
                .replace(PLACEHOLDER_PATTERN, (match, variable) => this.sampleValue(variable))
                .trim();
        },

        sampleValue(variable) {
            if (variable.startsWith(OPTION_VARIABLE_PREFIX)) {
                const groupName = variable.slice(OPTION_VARIABLE_PREFIX.length).trim().toLowerCase();
                const sample = this.sampleValues.options.find((option) => option.group.toLowerCase() === groupName);

                return sample ? sample.option : this.$t('printess-shopware-integration.pdfMetadataConfig.sample.option1');
            }

            return this.sampleValues[variable] ?? '';
        },

        unknownVariables(field) {
            const unknown = new Set();

            for (const match of this.values[field.key].matchAll(PLACEHOLDER_PATTERN)) {
                const variable = match[1];

                if (!KNOWN_VARIABLES.has(variable) && !(variable.startsWith(OPTION_VARIABLE_PREFIX) && variable.length > OPTION_VARIABLE_PREFIX.length)) {
                    unknown.add(match[0]);
                }
            }

            return [...unknown];
        },

        hasUnclosedPlaceholder(field) {
            const text = this.values[field.key];

            return text.replace(PLACEHOLDER_PATTERN, '').includes('{{');
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

        inputElement(field) {
            return this.$el?.querySelector(`[data-pdf-metadata-field="${field.key}"] input`) ?? null;
        },

        /**
         * Remembers where the cursor is, since clicking "Insert variable" moves the focus away.
         */
        rememberCursor(field, event) {
            const target = event?.target;

            if (target && typeof target.selectionStart === 'number') {
                this.cursorPositions = { ...this.cursorPositions, [field.key]: [target.selectionStart, target.selectionEnd] };
            }
        },

        togglePicker(field) {
            this.pickerSearch = '';
            this.pickerFieldKey = this.pickerFieldKey === field.key ? null : field.key;
        },

        insertVariable(field, entry) {
            const text = this.values[field.key];
            const [start, end] = this.cursorPositions[field.key] ?? [text.length, text.length];
            const before = text.slice(0, start);
            const after = text.slice(end);
            // Keep words apart: "Order{{ x }}" is valid, but rarely what is meant.
            const separator = before !== '' && !/\s$/.test(before) ? ' ' : '';
            const inserted = `${separator}${entry.token}`;
            const cursor = before.length + inserted.length;

            this.onInput(field, `${before}${inserted}${after}`);
            this.cursorPositions = { ...this.cursorPositions, [field.key]: [cursor, cursor] };
            this.pickerFieldKey = null;

            this.$nextTick(() => {
                const input = this.inputElement(field);

                if (input) {
                    input.focus();
                    input.setSelectionRange(cursor, cursor);
                }
            });
        },
    },
});
