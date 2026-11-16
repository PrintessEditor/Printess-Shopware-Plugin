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

import template from './printess-placeholder-input.html.twig';
import './printess-placeholder-input.scss';
import {
    buildSampleValues,
    buildVariableGroups,
    hasUnclosedPlaceholder,
    previewText,
    uniqueGroupNames,
    unknownVariables,
} from '../service/printess-placeholder-variables';

/**
 * A text field for a value with `{{ variable }}` placeholders: an "Insert variable" picker with
 * search, warnings for unknown variables and unclosed placeholders, and a preview with sample
 * values. Used by `printess-pdf-metadata-config` and `printess-order-metadata-config`.
 */
export default Shopware.Component.wrapComponentConfig({
    template,

    emits: ['update:value'],

    props: {
        value: {
            type: String,
            required: false,
            default: '',
        },

        name: {
            type: String,
            required: true,
        },

        label: {
            type: String,
            required: false,
            default: null,
        },

        placeholder: {
            type: String,
            required: false,
            default: '',
        },

        disabled: {
            type: Boolean,
            required: false,
            default: false,
        },

        /** Option group names offered as `product.option.<name>` variables. */
        groupNames: {
            type: Array,
            required: false,
            default: () => [],
        },

        /** The product's own name/number for the preview, instead of sample values. */
        previewProduct: {
            type: Object,
            required: false,
            default: null,
        },

        /**
         * The text the preview shows - defaults to `value`; the PDF properties pass the plugin value
         * here while the product's own field is empty.
         */
        previewSource: {
            type: String,
            required: false,
            default: null,
        },

        previewLabel: {
            type: String,
            required: false,
            default: null,
        },
    },

    data() {
        return {
            showPicker: false,
            pickerSearch: '',
            cursor: null,
        };
    },

    computed: {
        text() {
            return this.value ?? '';
        },

        normalizedGroupNames() {
            return uniqueGroupNames(this.groupNames);
        },

        filteredVariableGroups() {
            const groups = buildVariableGroups(this.$t, this.normalizedGroupNames);
            const search = this.pickerSearch.trim().toLowerCase();

            if (search === '') {
                return groups;
            }

            return groups
                .map((group) => ({
                    ...group,
                    variables: group.variables.filter((entry) => entry.label.toLowerCase().includes(search)
                        || entry.variable.toLowerCase().includes(search)),
                }))
                .filter((group) => group.variables.length);
        },

        preview() {
            const source = this.previewSource ?? this.text;

            return previewText(source, buildSampleValues(this.$t, this.normalizedGroupNames, this.previewProduct));
        },

        unknownVariables() {
            return unknownVariables(this.text);
        },

        hasUnclosedPlaceholder() {
            return hasUnclosedPlaceholder(this.text);
        },
    },

    methods: {
        onInput(value) {
            this.$emit('update:value', value ?? '');
        },

        /** Remembers where the cursor is, since clicking "Insert variable" moves the focus away. */
        rememberCursor(event) {
            const target = event?.target;

            if (target && typeof target.selectionStart === 'number') {
                this.cursor = [target.selectionStart, target.selectionEnd];
            }
        },

        togglePicker() {
            this.pickerSearch = '';
            this.showPicker = !this.showPicker;
        },

        /** Closes the picker without inserting anything and puts the cursor back into the field. */
        closePicker() {
            this.showPicker = false;
            this.pickerSearch = '';

            this.$nextTick(() => {
                const input = this.$el?.querySelector('.printess-placeholder-input__input input');

                if (input) {
                    input.focus();

                    if (this.cursor) {
                        input.setSelectionRange(this.cursor[0], this.cursor[1]);
                    }
                }
            });
        },

        insertVariable(entry) {
            const text = this.text;
            const [start, end] = this.cursor ?? [text.length, text.length];
            const before = text.slice(0, start);
            const after = text.slice(end);
            // Keep words apart: "Order{{ x }}" is valid, but rarely what is meant.
            const separator = before !== '' && !/\s$/.test(before) ? ' ' : '';
            const inserted = `${separator}${entry.token}`;
            const cursor = before.length + inserted.length;

            this.onInput(`${before}${inserted}${after}`);
            this.cursor = [cursor, cursor];
            this.showPicker = false;

            this.$nextTick(() => {
                const input = this.$el?.querySelector('.printess-placeholder-input__input input');

                if (input) {
                    input.focus();
                    input.setSelectionRange(cursor, cursor);
                }
            });
        },
    },
});
