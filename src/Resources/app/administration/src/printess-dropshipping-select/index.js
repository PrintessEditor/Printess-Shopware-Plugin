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

const MODE_PLUGIN_DEFAULT = 'pluginDefault';
const MODE_NONE = 'none';
const MODE_DROPSHIPPING = 'dropshipping';
const MODE_TEMPLATE = 'template';

const PRODUCT_DEFINITION_ID_PLUGIN_DEFAULT = -2;
const PRODUCT_DEFINITION_ID_NONE = -1;
const PRODUCT_DEFINITION_ID_TEMPLATE = 0;

/** Matches the popover's `max-height` in the stylesheet. */
const POPOVER_MAX_HEIGHT = 260;
const POPOVER_OFFSET = 4;
const POPOVER_VIEWPORT_MARGIN = 8;

/**
 * Dropshipping configuration control, used both as the plugin-wide default (embedded into the
 * extension config page via config.xml's `<component>` field) and as the per-product setting in
 * `sw-product-detail-printess`. Stored as one number in a string, as in the Shopify integration
 * and read by `ProductionDropshippingResolver`:
 *
 * - `-2`: use the plugin default (product only, see `allowPluginDefault`);
 * - `-1`: no dropshipping - also what an empty value means;
 * - `0`: dropship with the template's settings;
 * - `> 0`: dropship with that product definition.
 *
 * Values saved by earlier versions as JSON (`{"mode": "dropshipping"|"template",
 * "productDefinitionId": number|null}`) are still shown correctly, and replaced on the next change.
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

        /** Offers "Use plugin default" - for a product, not for the plugin default itself. */
        allowPluginDefault: {
            type: Boolean,
            required: false,
            default: false,
        },
    },

    data() {
        return {
            mode: MODE_NONE,
            productDefinitionId: null,
            lastEmittedValue: undefined,
            productDefinitions: [],
            productDefinitionsLoaded: false,
            isLoading: false,
            loadError: null,
            isProductDefinitionDropdownOpen: false,
            highlightedDefinitionId: null,
            productDefinitionSearchTerm: '',
            productDefinitionPopoverStyle: {},
        };
    },

    computed: {
        modeOptions() {
            const options = [
                { value: MODE_NONE, label: this.$t('printess-shopware-integration.dropshippingSelect.modeNone') },
                { value: MODE_DROPSHIPPING, label: this.$t('printess-shopware-integration.dropshippingSelect.modeDropshipping') },
                { value: MODE_TEMPLATE, label: this.$t('printess-shopware-integration.dropshippingSelect.modeTemplate') },
            ];

            if (this.allowPluginDefault) {
                options.unshift({
                    value: MODE_PLUGIN_DEFAULT,
                    label: this.$t('printess-shopware-integration.dropshippingSelect.modePluginDefault'),
                });
            }

            return options;
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
                // Our own change coming back from the parent. Re-reading it would turn "Use drop
                // shipping" without a chosen definition (saved as `-1`) back into "No drop
                // shipping" and hide the definition selector.
                if (newValue === this.lastEmittedValue) {
                    return;
                }

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
        this.removeProductDefinitionDropdownListeners();
    },

    methods: {
        /**
         * Mirrors `ProductionDropshippingResolver`: anything that isn't one of the values above
         * is shown as "No drop shipping", because that is how production treats it.
         */
        parseValue(raw) {
            const text = raw === null || raw === undefined ? '' : String(raw).trim();

            if (/^[+-]?\d+$/.test(text)) {
                return this.modeForProductDefinitionId(Number(text));
            }

            try {
                const legacy = JSON.parse(text);

                if (legacy?.mode === MODE_TEMPLATE) {
                    return this.modeForProductDefinitionId(PRODUCT_DEFINITION_ID_TEMPLATE);
                }

                if (legacy?.mode === MODE_DROPSHIPPING && Number.isInteger(legacy.productDefinitionId)) {
                    return this.modeForProductDefinitionId(legacy.productDefinitionId);
                }
            } catch (error) {
                // Empty or not JSON: no dropshipping.
            }

            return this.modeForProductDefinitionId(PRODUCT_DEFINITION_ID_NONE);
        },

        modeForProductDefinitionId(id) {
            if (id === PRODUCT_DEFINITION_ID_PLUGIN_DEFAULT && this.allowPluginDefault) {
                return { mode: MODE_PLUGIN_DEFAULT, productDefinitionId: null };
            }

            if (id === PRODUCT_DEFINITION_ID_TEMPLATE) {
                return { mode: MODE_TEMPLATE, productDefinitionId: null };
            }

            if (id > 0) {
                return { mode: MODE_DROPSHIPPING, productDefinitionId: id };
            }

            return { mode: MODE_NONE, productDefinitionId: null };
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

            this.addProductDefinitionDropdownListeners();
        },

        closeProductDefinitionDropdown() {
            this.isProductDefinitionDropdownOpen = false;

            this.removeProductDefinitionDropdownListeners();
        },

        /**
         * Besides closing on an outside click, keeps the popover attached to the input while it is
         * open: it is `position: fixed` in `<body>` (see the template), so scrolling the page or
         * the admin's own scroll container would otherwise leave it floating where it was.
         * Capturing `scroll` catches every scrolling ancestor, not just the window.
         */
        addProductDefinitionDropdownListeners() {
            this.updateProductDefinitionPopoverPosition();

            document.addEventListener('click', this.onDocumentClickOutside, true);
            window.addEventListener('scroll', this.updateProductDefinitionPopoverPosition, true);
            window.addEventListener('resize', this.updateProductDefinitionPopoverPosition);
        },

        removeProductDefinitionDropdownListeners() {
            document.removeEventListener('click', this.onDocumentClickOutside, true);
            window.removeEventListener('scroll', this.updateProductDefinitionPopoverPosition, true);
            window.removeEventListener('resize', this.updateProductDefinitionPopoverPosition);
        },

        /**
         * Places the popover under the input, or above it when there is less room below than the
         * popover needs and more above - the same flip mt-select does - and shrinks it to the space
         * that side has, so the list never runs off screen.
         */
        updateProductDefinitionPopoverPosition() {
            const field = this.$refs.productDefinitionField;

            if (!field) {
                return;
            }

            const rect = field.getBoundingClientRect();
            const spaceBelow = window.innerHeight - rect.bottom - POPOVER_OFFSET - POPOVER_VIEWPORT_MARGIN;
            const spaceAbove = rect.top - POPOVER_OFFSET - POPOVER_VIEWPORT_MARGIN;
            const above = spaceBelow < POPOVER_MAX_HEIGHT && spaceAbove > spaceBelow;

            this.productDefinitionPopoverStyle = {
                left: `${rect.left}px`,
                width: `${rect.width}px`,
                maxHeight: `${Math.min(POPOVER_MAX_HEIGHT, above ? spaceAbove : spaceBelow)}px`,
                ...(above
                    ? { bottom: `${window.innerHeight - rect.top + POPOVER_OFFSET}px` }
                    : { top: `${rect.bottom + POPOVER_OFFSET}px` }),
            };
        },

        /** The popover is not inside `$el` any more (see the template), so it is checked on its own. */
        onDocumentClickOutside(event) {
            const popover = this.$refs.productDefinitionPopover;

            if (!this.$el.contains(event.target) && !popover?.contains(event.target)) {
                this.closeProductDefinitionDropdown();
            }
        },

        onProductDefinitionSearchInput(event) {
            if (!this.isProductDefinitionDropdownOpen) {
                this.isProductDefinitionDropdownOpen = true;
                this.addProductDefinitionDropdownListeners();
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

        /** "Use drop shipping" without a chosen product definition is saved as no dropshipping. */
        emitValue() {
            const id = {
                [MODE_PLUGIN_DEFAULT]: PRODUCT_DEFINITION_ID_PLUGIN_DEFAULT,
                [MODE_TEMPLATE]: PRODUCT_DEFINITION_ID_TEMPLATE,
                [MODE_DROPSHIPPING]: this.productDefinitionId,
            }[this.mode] ?? PRODUCT_DEFINITION_ID_NONE;

            this.lastEmittedValue = String(id);
            this.$emit('update:value', this.lastEmittedValue);
        },
    },
});
