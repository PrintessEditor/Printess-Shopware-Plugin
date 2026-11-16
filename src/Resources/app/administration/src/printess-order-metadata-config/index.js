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

import template from './printess-order-metadata-config.html.twig';
import './printess-order-metadata-config.scss';
import {
    loadPropertyGroupNames,
    token,
    uniqueGroupNames,
} from '../service/printess-placeholder-variables';

const CONFIG_DOMAIN = 'PrintessShopwareIntegration.config';
const CONFIG_KEY = `${CONFIG_DOMAIN}.orderMetaData`;

/**
 * Mirrors `OrderMetaDataResolver::KEY_PATTERN`: Printess resolves `{meta.<key>}` by splitting on `.`,
 * ends a placeholder at `}` and uses `~` for modifiers, so only these characters work there.
 */
const KEY_PATTERN = /^[A-Za-z0-9_-]+$/;

/**
 * Keys the other Printess integrations already send as order metadata (Shopify, WooCommerce, Printess
 * shop orders), so dropship product definitions written for them may already use `{meta.<key>}` with
 * these - offered as suggestions, any other valid key works too.
 */
const KNOWN_KEYS = [
    'orderId',
    'orderNumber',
    'orderDate',
    'lineItemId',
    'productName',
    'itemSku',
    'itemQuantity',
    'shippingCode',
    'shippingTitle',
    'shopOrderId',
    'shopOrderItemId',
    'reference',
    'shopDomain',
    'shopName',
];

/**
 * Accepts the stored list or its JSON string (a custom field can be written through the API).
 */
function normalizeRows(value) {
    let parsed = value;

    if (typeof parsed === 'string' && parsed !== '') {
        try {
            parsed = JSON.parse(parsed);
        } catch (error) {
            parsed = null;
        }
    }

    if (!Array.isArray(parsed)) {
        return [];
    }

    return parsed
        .filter((row) => row && typeof row === 'object')
        .map((row) => ({
            key: typeof row.key === 'string' ? row.key : '',
            value: typeof row.value === 'string' ? row.value : '',
        }));
}

/** Row ids for `v-for` keys - module-wide, so they never repeat between instances or reloads. */
let rowIdSeed = 0;

function createRows(rows) {
    return rows.map((row) => {
        rowIdSeed += 1;

        return { id: rowIdSeed, key: row.key, value: row.value };
    });
}

function serialize(rows) {
    return rows
        .map((row) => ({ key: row.key.trim(), value: row.value }))
        .filter((row) => row.key !== '' && row.value.trim() !== '');
}

/**
 * Editor for the Printess order metadata (`orderMetaData` of the produce call): key/value rows whose
 * values may contain `{{ variable }}` placeholders, filled in when production starts (see
 * `OrderMetaDataResolver` on the PHP side). Printess stores them with its order; dropship product
 * definitions can use them as `{meta.<key>}`.
 *
 * Used both as the plugin-wide setting (config.xml `<component>`, `orderMetaData`) and as the product
 * override in `sw-product-detail-printess` (`PrintessOrderMetaData` custom field). With
 * `showPluginDefaults`, the plugin rows are listed read-only, since they apply unless the product
 * has a row with the same key.
 */
export default Shopware.Component.wrapComponentConfig({
    template,

    inject: ['repositoryFactory', 'systemConfigApiService'],

    emits: ['update:value'],

    props: {
        value: {
            type: [Array, String],
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

        /** Product tab: lists the plugin rows, which apply unless overridden here. */
        showPluginDefaults: {
            type: Boolean,
            required: false,
            default: false,
        },

        /** Product tab: the product's own name/number for the preview, instead of sample values. */
        previewProduct: {
            type: Object,
            required: false,
            default: null,
        },
    },

    data() {
        return {
            rows: createRows(normalizeRows(this.value)),
            pluginRows: [],
            loadedOptionGroupNames: [],
            showHelp: false,
        };
    },

    computed: {
        groupNames() {
            return uniqueGroupNames(this.optionGroupNames ?? this.loadedOptionGroupNames);
        },

        knownKeys() {
            return KNOWN_KEYS;
        },

        /** Datalist id - unique per instance, since the plugin and product editors can coexist. */
        keyListId() {
            return `printess-order-metadata-keys-${this.$.uid}`;
        },

        valuePlaceholder() {
            return this.$t('printess-shopware-integration.orderMetaDataConfig.valuePlaceholder', { example: token('order.number') });
        },

        ownKeys() {
            return new Set(this.rows.map((row) => row.key.trim()).filter((key) => key !== ''));
        },

        helpParams() {
            return {
                variable: token('order.number'),
                key: 'orderNumber',
                metaPlaceholder: '{meta.orderNumber}',
            };
        },
    },

    watch: {
        value(newValue) {
            const incoming = JSON.stringify(serialize(normalizeRows(newValue)));

            // Don't overwrite what is being edited (incl. incomplete rows) with the echoed saved value.
            if (incoming !== JSON.stringify(serialize(this.rows))) {
                this.rows = createRows(normalizeRows(newValue));
            }
        },
    },

    created() {
        this.showHelp = this.rows.length === 0;

        if (this.optionGroupNames === null) {
            loadPropertyGroupNames(this.repositoryFactory).then((names) => {
                this.loadedOptionGroupNames = names;
            });
        }

        if (this.showPluginDefaults) {
            this.loadPluginRows();
        }
    },

    methods: {
        loadPluginRows() {
            return this.systemConfigApiService.getValues(CONFIG_DOMAIN)
                .then((values) => {
                    this.pluginRows = serialize(normalizeRows(values?.[CONFIG_KEY] ?? null));
                })
                .catch(() => {
                    this.pluginRows = [];
                });
        },

        addRow() {
            this.rows = [...this.rows, ...createRows([{ key: '', value: '' }])];
        },

        removeRow(id) {
            this.rows = this.rows.filter((row) => row.id !== id);
            this.emitValue();
        },

        updateRow(id, field, value) {
            this.rows = this.rows.map((row) => (row.id === id ? { ...row, [field]: value ?? '' } : row));
            this.emitValue();
        },

        keyError(row) {
            const key = row.key.trim();

            if (key === '') {
                return row.value.trim() !== ''
                    ? this.$t('printess-shopware-integration.orderMetaDataConfig.keyMissing')
                    : null;
            }

            if (!KEY_PATTERN.test(key)) {
                return this.$t('printess-shopware-integration.orderMetaDataConfig.keyInvalid', { metaKey: '{meta.key}' });
            }

            if (this.rows.filter((other) => other.key.trim() === key).length > 1) {
                return this.$t('printess-shopware-integration.orderMetaDataConfig.keyDuplicate', { key });
            }

            return null;
        },

        isOverridden(pluginRow) {
            return this.ownKeys.has(pluginRow.key);
        },

        emitValue() {
            const rows = serialize(this.rows);

            this.$emit('update:value', rows.length ? rows : null);
        },
    },
});
