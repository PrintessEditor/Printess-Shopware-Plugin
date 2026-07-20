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

import template from './printess-dropshipping-select.html.twig';
import './printess-dropshipping-select.scss';

const MODE_NONE = 'none';
const MODE_DROPSHIPPING = 'dropshipping';
const MODE_TEMPLATE = 'template';

/**
 * Dropshipping configuration control, used both as the plugin-wide default (embedded into the
 * extension config page via config.xml's `<component>` field) and as the per-product override in
 * `sw-product-detail-printess`. Stored as a single JSON-encoded string (mirroring how
 * `PrintessMergeTemplates` already stores structured data in one text field), since a single
 * config/custom-field value can only carry one plain value: `null`/empty for "no dropshipping",
 * or `{"mode": "dropshipping"|"template", "productDefinitionId": number|null}`.
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
            mode: MODE_NONE,
            productDefinitionId: null,
            productDefinitions: [],
            productDefinitionsLoaded: false,
            isLoading: false,
            loadError: null,
            isProductDefinitionDropdownOpen: false,
            highlightedDefinitionId: null,
            productDefinitionSearchTerm: '',
        };
    },

    computed: {
        modeOptions() {
            return [
                { value: MODE_NONE, label: this.$t('printess-shopware-integration.dropshippingSelect.modeNone') },
                { value: MODE_DROPSHIPPING, label: this.$t('printess-shopware-integration.dropshippingSelect.modeDropshipping') },
                { value: MODE_TEMPLATE, label: this.$t('printess-shopware-integration.dropshippingSelect.modeTemplate') },
            ];
        },

        /**
         * `mt-select` has no grouping concept at all - it just renders a flat `options` array - so
         * this is a fully custom combobox instead, grouping the product definition list by
         * dropshipper. It also has to be custom (rather than a native `<select>`/`<optgroup>`,
         * which was tried first) because a native select's popup is rendered by the OS/browser and
         * does not live-update while open: typing in a search box above/inside it only ever affects
         * what's shown the *next* time it's reopened, not while a user is actively searching -
         * unusable for a searchable list. This combobox renders its own dropdown panel, so filtering
         * while it's open works like any other autocomplete.
         */
        groupedProductDefinitions() {
            const groups = new Map();

            this.productDefinitions.forEach((definition) => {
                const groupName = definition.dropshipperName
                    || this.$t('printess-shopware-integration.dropshippingSelect.otherDropshipperGroup');

                if (!groups.has(groupName)) {
                    groups.set(groupName, []);
                }

                groups.get(groupName).push(definition);
            });

            return [...groups.entries()]
                .sort(([a], [b]) => a.localeCompare(b))
                .map(([name, definitions]) => ({ name, definitions }));
        },

        /**
         * Filters `groupedProductDefinitions` by the search box term (matching either the
         * definition's own label or its dropshipper group name), dropping groups left empty by the
         * filter. The currently selected definition is always kept, even if it no longer matches
         * the search term, so narrowing the list can never make the current selection appear to
         * silently disappear out from under the select.
         */
        filteredGroupedProductDefinitions() {
            const term = this.productDefinitionSearchTerm.trim().toLowerCase();

            if (term === '') {
                return this.groupedProductDefinitions;
            }

            return this.groupedProductDefinitions
                .map((group) => ({
                    name: group.name,
                    definitions: group.definitions.filter((definition) => (
                        definition.id === this.productDefinitionId
                        || definition.label.toLowerCase().includes(term)
                        || group.name.toLowerCase().includes(term)
                    )),
                }))
                .filter((group) => group.definitions.length > 0);
        },

        selectedProductDefinition() {
            return this.productDefinitions.find((definition) => definition.id === this.productDefinitionId) ?? null;
        },

        /**
         * What the combobox's text input displays: the live search term while open (so the user
         * sees what they're typing), otherwise the selected definition's label - or nothing, if
         * none is selected yet.
         */
        productDefinitionInputValue() {
            if (this.isProductDefinitionDropdownOpen) {
                return this.productDefinitionSearchTerm;
            }

            return this.selectedProductDefinition?.label ?? '';
        },

        /**
         * Flat (non-grouped) list of the currently visible definitions, used purely to drive
         * ArrowUp/ArrowDown keyboard navigation across group boundaries.
         */
        flatFilteredProductDefinitions() {
            return this.filteredGroupedProductDefinitions.flatMap((group) => group.definitions);
        },
    },

    watch: {
        value: {
            immediate: true,
            handler(newValue) {
                const parsed = this.parseValue(newValue);
                this.mode = parsed.mode;
                this.productDefinitionId = parsed.productDefinitionId;

                if (this.mode === MODE_DROPSHIPPING) {
                    this.loadProductDefinitions();
                }
            },
        },
    },

    beforeUnmount() {
        document.removeEventListener('click', this.onDocumentClickOutside, true);
    },

    methods: {
        parseValue(raw) {
            if (!raw) {
                return { mode: MODE_NONE, productDefinitionId: null };
            }

            try {
                const parsed = JSON.parse(raw);
                const mode = [MODE_DROPSHIPPING, MODE_TEMPLATE].includes(parsed?.mode) ? parsed.mode : MODE_NONE;

                return {
                    mode,
                    productDefinitionId: mode === MODE_DROPSHIPPING && typeof parsed?.productDefinitionId === 'number'
                        ? parsed.productDefinitionId
                        : null,
                };
            } catch (error) {
                return { mode: MODE_NONE, productDefinitionId: null };
            }
        },

        loadProductDefinitions() {
            if (this.productDefinitionsLoaded || this.isLoading) {
                return;
            }

            this.isLoading = true;
            this.loadError = null;

            return this.printessTemplateService.getDropshipProductDefinitions()
                .then((response) => {
                    this.productDefinitions = response?.productDefinitions ?? [];
                    this.productDefinitionsLoaded = true;
                })
                .catch((error) => {
                    this.productDefinitions = [];
                    this.loadError = error?.response?.data?.error
                        ?? error?.message
                        ?? this.$t('printess-shopware-integration.dropshippingSelect.loadError');
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },

        onModeChange(newMode) {
            this.mode = newMode;

            if (newMode === MODE_DROPSHIPPING) {
                this.loadProductDefinitions();
            } else {
                this.productDefinitionId = null;
            }

            this.emitValue();
        },

        openProductDefinitionDropdown() {
            if (this.disabled || this.isLoading) {
                return;
            }

            this.isProductDefinitionDropdownOpen = true;
            this.productDefinitionSearchTerm = '';
            this.highlightedDefinitionId = this.productDefinitionId;

            document.addEventListener('click', this.onDocumentClickOutside, true);
        },

        closeProductDefinitionDropdown() {
            this.isProductDefinitionDropdownOpen = false;

            document.removeEventListener('click', this.onDocumentClickOutside, true);
        },

        onDocumentClickOutside(event) {
            if (!this.$el.contains(event.target)) {
                this.closeProductDefinitionDropdown();
            }
        },

        onProductDefinitionSearchInput(event) {
            if (!this.isProductDefinitionDropdownOpen) {
                this.isProductDefinitionDropdownOpen = true;
                document.addEventListener('click', this.onDocumentClickOutside, true);
            }

            this.productDefinitionSearchTerm = event.target.value;

            const stillVisible = this.flatFilteredProductDefinitions.some(
                (definition) => definition.id === this.highlightedDefinitionId,
            );

            if (!stillVisible) {
                this.highlightedDefinitionId = this.flatFilteredProductDefinitions[0]?.id ?? null;
            }
        },

        onProductDefinitionSearchKeydown(event) {
            if (event.key === 'Escape') {
                this.closeProductDefinitionDropdown();
                return;
            }

            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();

                if (!this.isProductDefinitionDropdownOpen) {
                    this.openProductDefinitionDropdown();
                    return;
                }

                const definitions = this.flatFilteredProductDefinitions;

                if (definitions.length === 0) {
                    return;
                }

                const currentIndex = definitions.findIndex((definition) => definition.id === this.highlightedDefinitionId);
                const isArrowDown = event.key === 'ArrowDown';

                const nextIndex = currentIndex === -1
                    ? (isArrowDown ? 0 : definitions.length - 1)
                    : (currentIndex + (isArrowDown ? 1 : -1) + definitions.length) % definitions.length;

                this.highlightedDefinitionId = definitions[nextIndex].id;
                return;
            }

            if (event.key === 'Enter') {
                event.preventDefault();

                const highlighted = this.flatFilteredProductDefinitions.find(
                    (definition) => definition.id === this.highlightedDefinitionId,
                );

                if (highlighted) {
                    this.selectProductDefinition(highlighted);
                }
            }
        },

        selectProductDefinition(definition) {
            this.productDefinitionId = definition.id;
            this.closeProductDefinitionDropdown();
            this.emitValue();
        },

        emitValue() {
            if (this.mode === MODE_NONE) {
                this.$emit('update:value', null);
                return;
            }

            this.$emit('update:value', JSON.stringify({
                mode: this.mode,
                productDefinitionId: this.mode === MODE_DROPSHIPPING ? this.productDefinitionId : null,
            }));
        },
    },
});
