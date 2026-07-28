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

import template from './sw-product-detail-printess.html.twig';
import './sw-product-detail-printess.scss';

const { Criteria } = Shopware.Data;

/**
 * Normalizes the raw `PrintessMergeTemplates` custom field value into a flat array of merge
 * template configs. The field is stored as a JSON-serialized string (or, for backwards
 * compatibility with the legacy `printess-editor` plugin, a plain template name string).
 */
const TEMPLATE_SEARCH_DEBOUNCE_MS = 250;
const PRODUCT_SETTINGS_SAVE_DEBOUNCE_MS = 600;
const ACTIVATION_CONFLICT_DISPLAY_MS = 20000;
const MAX_DISPLAYED_TEMPLATES = 120;
const LAYOUT_SNIPPET_ID_PREFIX = 'sid~';
const LAYOUT_SNIPPET_GLOBAL_SUFFIX = '~gid';

/**
 * A price rule condition's `operator` - mirrors `PrintessPriceCalculatorService`'s PHP-side operator
 * set. `greaterThan`/`greaterThanOrEqual`/`lessThan`/`lessThanOrEqual` are only offered when
 * `compareBy` is "length" (see `operatorOptionsFor()`); comparing arbitrary text values only ever
 * makes sense as an equals/not-equals check.
 */
const VALID_OPERATORS = ['equals', 'notEquals', 'greaterThan', 'greaterThanOrEqual', 'lessThan', 'lessThanOrEqual'];
const LENGTH_ONLY_OPERATORS = ['greaterThan', 'greaterThanOrEqual', 'lessThan', 'lessThanOrEqual'];

/**
 * A "Preselected form field value" (see `formFields`) can be pushed as a fixed, admin-typed value
 * (the only option before this existed - `source` defaults to "fixed" for configs saved before it
 * did), or as one of these "variable" keys, resolved server-side against the storefront's currently
 * logged in customer - mirrors `PrintessCustomerVariableResolver::VARIABLE_KEYS` on the PHP side.
 * `value` doubles as the fallback used when the variable can't be resolved (no customer logged in,
 * or that field isn't set on their account/address).
 */
const FORM_FIELD_VARIABLE_KEYS = [
    'customer.firstName',
    'customer.lastName',
    'customer.fullName',
    'customer.email',
    'customer.customerNumber',
    'customer.company',
    'billingAddress.street',
    'billingAddress.zipcode',
    'billingAddress.city',
    'billingAddress.country',
    'shippingAddress.street',
    'shippingAddress.zipcode',
    'shippingAddress.city',
    'shippingAddress.country',
];

/**
 * A `"property.<propertyGroupId>"` variable (see `PrintessProductPropertyResolver` on the PHP
 * side) isn't in `FORM_FIELD_VARIABLE_KEYS` since the group id is per-shop/per-product, not a fixed
 * enum - matched by prefix instead, same as the PHP-side dispatch in `PrintessExtension::resolveFormFieldValue()`.
 */
const FORM_FIELD_PROPERTY_VARIABLE_PREFIX = 'property.';

function isValidFormFieldVariable(variable) {
    return FORM_FIELD_VARIABLE_KEYS.includes(variable) || (typeof variable === 'string' && variable.startsWith(FORM_FIELD_PROPERTY_VARIABLE_PREFIX));
}

/**
 * Layout snippet references are stored in a merge template's `templateName` field (same field
 * used for a plain template name when mergeMode is "merge") using Printess's own encoding:
 * `sid~<id>` for a shop-owned snippet, `sid~<id>~gid` for a global one.
 */
function encodeLayoutSnippetReference(id, isGlobal) {
    return `${LAYOUT_SNIPPET_ID_PREFIX}${id}${isGlobal ? LAYOUT_SNIPPET_GLOBAL_SUFFIX : ''}`;
}

function decodeLayoutSnippetReference(value) {
    if (typeof value !== 'string' || !value.startsWith(LAYOUT_SNIPPET_ID_PREFIX)) {
        return null;
    }

    let id = value.slice(LAYOUT_SNIPPET_ID_PREFIX.length);
    let isGlobal = false;

    if (id.endsWith(LAYOUT_SNIPPET_GLOBAL_SUFFIX)) {
        isGlobal = true;
        id = id.slice(0, -LAYOUT_SNIPPET_GLOBAL_SUFFIX.length);
    }

    return id ? { id, isGlobal } : null;
}

function isLayoutSnippetMergeMode(mergeMode) {
    return mergeMode !== 'merge';
}

/**
 * The `PrintessPriceConfig` custom field is a JSON-serialized `IPrintessPriceConfig` object (see
 * `PrintessPriceCalculatorService` for the PHP-side shape it mirrors), or null/empty if unset.
 */
function parsePriceConfig(raw) {
    if (raw === null || typeof raw === 'undefined' || raw === '' || typeof raw !== 'string') {
        return null;
    }

    try {
        const parsed = JSON.parse(raw);

        return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : null;
    } catch (error) {
        return null;
    }
}

/**
 * Every key of the Printess editor's `iExternalBookSettings` (see `printess-editor.d.ts`) this card
 * lets an admin configure, passed to the editor's `adjustBook()` API as-is once loaded (see
 * `printess-design-now.plugin.js`). `TEXT_FIELD_KEYS` covers the "Length value" properties (a fixed
 * value with unit like `"2cm"`, a plain pixel number, or - for `spine` only - an equation), kept as
 * free text rather than parsed, since the editor accepts either shape as a string.
 */
const BOOK_SETTINGS_TEXT_FIELD_KEYS = ['spine', 'hinge', 'edgeX', 'edgeY', 'bleedX', 'bleedY', 'bookImposition', 'coverImposition'];
/**
 * `addSpreads` is typed `1 | 2` in the editor's own `iExternalBookSettings` - kept configurable as
 * an arbitrary number here regardless (per explicit product requirement), since nothing in this
 * plain-JS integration enforces that literal union at runtime; values other than 1 or 2 may not be
 * fully supported by the editor.
 */
const BOOK_SETTINGS_INT_FIELD_KEYS = ['minPages', 'maxPages', 'initialFreestylePhotobookPages', 'debossedCoverImageCount', 'addSpreads'];
const BOOK_SETTINGS_BOOL_FIELD_KEYS = ['layflat', 'lockCoverInside', 'useDebossedCover'];
const BOOK_SETTINGS_PREVIEW_COVER_TYPES = ['hard', 'soft'];

/**
 * The `PrintessBookSettings` custom field is a JSON-serialized, sparse `iExternalBookSettings`
 * object (only the keys an admin actually configured), or null/empty if unset.
 */
function parseBookSettings(raw) {
    if (raw === null || typeof raw === 'undefined' || raw === '' || typeof raw !== 'string') {
        return {};
    }

    try {
        const parsed = JSON.parse(raw);

        return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
    } catch (error) {
        return {};
    }
}

/**
 * `parseBookSettings()`'s sparse object, filled out into the flat, always-fully-keyed shape the
 * form fields bind to - every text/int key defaults to `''`/`null` and every bool key to `false`
 * when absent.
 */
function normalizeBookSettings(raw) {
    const parsed = parseBookSettings(raw);
    const normalized = {};

    BOOK_SETTINGS_TEXT_FIELD_KEYS.forEach((key) => {
        normalized[key] = typeof parsed[key] === 'string' || typeof parsed[key] === 'number' ? String(parsed[key]) : '';
    });

    BOOK_SETTINGS_INT_FIELD_KEYS.forEach((key) => {
        normalized[key] = typeof parsed[key] === 'number' && Number.isFinite(parsed[key]) ? parsed[key] : null;
    });

    BOOK_SETTINGS_BOOL_FIELD_KEYS.forEach((key) => {
        normalized[key] = parsed[key] === true;
    });

    normalized.previewCoverType = BOOK_SETTINGS_PREVIEW_COVER_TYPES.includes(parsed.previewCoverType) ? parsed.previewCoverType : '';

    return normalized;
}

/**
 * `formFields` on a single rule is either one `{name, value, compareBy?, operator?}` object or a
 * list of them (all of which must match - an AND combination). Normalized here into a flat list
 * either way, so the UI always deals with a list of condition rows. `compareBy`/`operator` default
 * to "value"/"equals" - the only shape a condition could have before these existed, so configs
 * saved before this feature keep behaving exactly as they did.
 */
function normalizePriceRuleConditions(formFields) {
    if (Array.isArray(formFields)) {
        return formFields
            .filter((condition) => condition && typeof condition === 'object')
            .map((condition) => normalizeSinglePriceRuleCondition(condition));
    }

    if (formFields && typeof formFields === 'object') {
        return [normalizeSinglePriceRuleCondition(formFields)];
    }

    return [];
}

function normalizeSinglePriceRuleCondition(condition) {
    return {
        name: condition.name ?? '',
        value: condition.value ?? '',
        compareBy: condition.compareBy === 'length' ? 'length' : 'value',
        operator: VALID_OPERATORS.includes(condition.operator) ? condition.operator : 'equals',
    };
}

/**
 * A price rule's `label` is either a plain string (legacy shape, shown regardless of the
 * storefront's language - kept working forever as-is) or an object keyed by language id (current
 * shape, one translation per shop language - see `PrintessPriceCalculatorService::resolveRuleLabel()`
 * for how the storefront picks one at render time). Normalized here into the object shape either
 * way, so the UI always deals with one text field per language.
 */
function normalizePriceRuleLabel(label, systemLanguageId) {
    if (typeof label === 'string') {
        return label.trim() !== '' ? { [systemLanguageId]: label } : {};
    }

    if (label && typeof label === 'object' && !Array.isArray(label)) {
        const result = {};

        Object.entries(label).forEach(([languageId, value]) => {
            if (typeof value === 'string') {
                result[languageId] = value;
            }
        });

        return result;
    }

    return {};
}

function parseMergeTemplates(raw) {
    if (raw === null || typeof raw === 'undefined' || raw === '') {
        return [];
    }

    if (Array.isArray(raw)) {
        return raw.flatMap((entry) => parseMergeTemplates(entry));
    }

    if (typeof raw === 'object') {
        return [raw];
    }

    if (typeof raw !== 'string') {
        return [];
    }

    try {
        return parseMergeTemplates(JSON.parse(raw));
    } catch (error) {
        return [{ templateName: raw }];
    }
}

export default Shopware.Component.wrapComponentConfig({
    template,

    inject: [
        'acl',
        'printessTemplateService',
        'repositoryFactory',
    ],

    data() {
        return {
            isLoadingConfigStatus: true,
            hasServiceToken: false,
            hasShopToken: false,
            perLanguageEnabled: false,
            defaultLanguageFields: {},
            isLoadingDefaultLanguageFields: false,
            defaultLanguageFieldsLoaded: false,
            productSettingsSaveError: null,
            isLoadingTemplates: false,
            loadError: null,
            templates: [],
            showTemplatePicker: false,
            templatePickerTarget: 'main',
            searchTerm: '',
            debouncedSearchTerm: '',
            formFields: [],
            configuratorGroupNames: [],
            productPropertyGroups: [],
            mergeTemplates: [],
            mergeTemplateIdSeed: 0,
            includedPageCount: null,
            pricePerPage: null,
            priceRules: [],
            priceRuleIdSeed: 0,
            bookSettings: normalizeBookSettings(null),
            photobookTheme: '',
            insidePageCount: null,
            insidePageCountEnabled: false,
            magicPhotobookEnabled: false,
            slimUiEnabled: false,

            /**
             * Which of the two mutually-exclusive cards ('insidePageCount' or 'magicPhotobook') the
             * admin just tried to enable while the other was already on - drives which card shows the
             * conflict error (see `updateInsidePageCountEnabled`/`updateMagicPhotobookEnabled`, which
             * reject the attempt rather than letting both end up enabled). Cleared on the next toggle
             * attempt in either direction.
             */
            activationConflictCard: null,
            shopLanguages: [],
            layoutSnippetLabelsById: {},
            showLayoutSnippetPicker: false,
            layoutSnippetPickerTarget: null,
            layoutSnippets: [],
            isLoadingLayoutSnippets: false,
            layoutSnippetSearchError: null,
            manualLayoutSnippetId: '',
            layoutSnippetTags: [],
            selectedLayoutSnippetTagNames: [],
            isLoadingLayoutSnippetTags: false,
            layoutSnippetTagsLoaded: false,
            selectedLayoutSnippetKeywordNames: [],
        };
    },

    computed: {
        isConfigured() {
            return this.hasServiceToken && this.hasShopToken;
        },

        /**
         * Whether the settings cards are ready to render: either per-language mode is on (nothing
         * else to wait for), or it's off and the shared default-language values have finished
         * loading.
         */
        isSettingsReady() {
            return this.perLanguageEnabled || this.defaultLanguageFieldsLoaded;
        },

        product() {
            return Shopware.Store.get('swProductDetail').product;
        },

        selectedTemplateName() {
            const value = this.perLanguageEnabled
                ? this.product?.customFields?.PrintessTemplateName
                : this.defaultLanguageFields.PrintessTemplateName;

            return value ?? null;
        },

        printSettingName() {
            const value = this.perLanguageEnabled
                ? this.product?.customFields?.PrintessPrintSettingName
                : this.defaultLanguageFields.PrintessPrintSettingName;

            return value ?? null;
        },

        dropshippingConfig() {
            const value = this.perLanguageEnabled
                ? this.product?.customFields?.PrintessDropshippingConfig
                : this.defaultLanguageFields.PrintessDropshippingConfig;

            return value ?? null;
        },

        selectedTemplate() {
            if (!this.selectedTemplateName) {
                return null;
            }

            return this.templates.find((template) => template.name === this.selectedTemplateName) ?? null;
        },

        filteredTemplates() {
            if (!this.debouncedSearchTerm) {
                return this.templates;
            }

            const term = this.debouncedSearchTerm.toLowerCase();

            return this.templates.filter((template) => (template.name || '').toLowerCase().includes(term));
        },

        displayedTemplates() {
            return this.filteredTemplates.slice(0, MAX_DISPLAYED_TEMPLATES);
        },

        hasMoreTemplates() {
            return this.filteredTemplates.length > MAX_DISPLAYED_TEMPLATES;
        },

        canEdit() {
            return this.acl.can('product.editor');
        },

        /**
         * Shopware's own default/system language id - the fallback used when a price rule's
         * display name has no translation for the storefront's current language. Matches
         * `Shopware\Core\Defaults::LANGUAGE_SYSTEM` on the PHP side.
         */
        systemLanguageId() {
            return Shopware.Store.get('context').api.systemLanguageId;
        },

        productId() {
            return this.product?.id ?? null;
        },

        currentPickerTemplateName() {
            if (this.templatePickerTarget === 'main') {
                return this.selectedTemplateName;
            }

            const row = this.mergeTemplates.find((mergeTemplate) => mergeTemplate.clientId === this.templatePickerTarget);

            return row?.templateName || null;
        },

        mergeModeOptions() {
            return [
                { value: 'merge', label: this.$t('printess-shopware-integration.productDetail.mergeModeMerge') },
                { value: 'layout-snippet-no-repeat', label: this.$t('printess-shopware-integration.productDetail.mergeModeLayoutSnippetNoRepeat') },
                { value: 'layout-snippet-repeat-all', label: this.$t('printess-shopware-integration.productDetail.mergeModeLayoutSnippetRepeatAll') },
                { value: 'layout-snippet-repeat-inside', label: this.$t('printess-shopware-integration.productDetail.mergeModeLayoutSnippetRepeatInside') },
                { value: 'layout-snippet-no-repeat-persist-stickers', label: this.$t('printess-shopware-integration.productDetail.mergeModeLayoutSnippetNoRepeatPersistStickers') },
                { value: 'layout-snippet-repeat-all-persist-stickers', label: this.$t('printess-shopware-integration.productDetail.mergeModeLayoutSnippetRepeatAllPersistStickers') },
                { value: 'layout-snippet-repeat-inside-persist-stickers', label: this.$t('printess-shopware-integration.productDetail.mergeModeLayoutSnippetRepeatInsidePersistStickers') },
            ];
        },

        compareByOptions() {
            return [
                { value: 'value', label: this.$t('printess-shopware-integration.productDetail.compareByValue') },
                { value: 'length', label: this.$t('printess-shopware-integration.productDetail.compareByLength') },
            ];
        },

        formFieldSourceOptions() {
            return [
                { value: 'fixed', label: this.$t('printess-shopware-integration.productDetail.formFieldSourceFixed') },
                { value: 'variable', label: this.$t('printess-shopware-integration.productDetail.formFieldSourceVariable') },
            ];
        },

        previewCoverTypeOptions() {
            return [
                { value: '', label: this.$t('printess-shopware-integration.productDetail.addSpreadsNotSet') },
                { value: 'hard', label: this.$t('printess-shopware-integration.productDetail.previewCoverTypeHard') },
                { value: 'soft', label: this.$t('printess-shopware-integration.productDetail.previewCoverTypeSoft') },
            ];
        },

        /**
         * A product must never be configured as both a Magic Photobook (Magic Photobook settings)
         * and a regular book with a fixed inside page count (Book inside page count) - the two
         * represent mutually exclusive ways of telling the editor how many pages the book should
         * have, and only the enabled one is ever forwarded to the storefront editor at all (see
         * `PrintessBookInsidePageCountEnabled`/`PrintessMagicPhotobookEnabled` gating in
         * `buy-widget-form.html.twig`). `updateInsidePageCountEnabled()`/`updateMagicPhotobookEnabled()`
         * already refuse to let both end up enabled, so `bothEnabled` should only ever be true for
         * data that predates these toggles or was edited outside this UI - shown on both cards
         * defensively in that case. `activationConflictCard` additionally flags whichever single card
         * the admin just tried (and failed) to enable, for a live rejected-attempt error.
         */
        bothBookSettingsEnabled() {
            return this.insidePageCountEnabled && this.magicPhotobookEnabled;
        },

        showInsidePageCountConflictError() {
            return this.activationConflictCard === 'insidePageCount' || this.bothBookSettingsEnabled;
        },

        showMagicPhotobookConflictError() {
            return this.activationConflictCard === 'magicPhotobook' || this.bothBookSettingsEnabled;
        },

        /**
         * One option per `FORM_FIELD_VARIABLE_KEYS` entry (mirrors `PrintessCustomerVariableResolver::VARIABLE_KEYS`
         * on the PHP side), plus one per non-variant property group actually assigned to this product
         * (mirrors `PrintessProductPropertyResolver`) - both dispatched by the same `variable` value at
         * render time, see `PrintessExtension::resolveFormFieldValue()`.
         */
        formFieldVariableOptions() {
            const customerOptions = FORM_FIELD_VARIABLE_KEYS.map((key) => ({
                value: key,
                label: this.$t(`printess-shopware-integration.productDetail.formFieldVariable.${key}`),
            }));

            const propertyOptions = this.productPropertyGroups.map((group) => ({
                value: `${FORM_FIELD_PROPERTY_VARIABLE_PREFIX}${group.id}`,
                label: this.$t('printess-shopware-integration.productDetail.formFieldVariablePropertyPrefix', { name: group.name }),
            }));

            return [...customerOptions, ...propertyOptions];
        },

        currentLayoutSnippetId() {
            const row = this.mergeTemplates.find((mergeTemplate) => mergeTemplate.clientId === this.layoutSnippetPickerTarget);

            return decodeLayoutSnippetReference(row?.templateName)?.id ?? null;
        },

        /**
         * There's no server-side endpoint for "keywords available for these tags" — Printess only exposes an
         * account-wide keyword vocabulary, unrelated to the current tag selection. So the keyword filter is
         * instead derived from whatever the current tag-filtered search actually returned: the union of each
         * matching layout snippet's own keywords. This also means it re-narrows as keywords are selected,
         * since selecting a keyword re-runs the search and recomputes this from the smaller result set.
         */
        layoutSnippetKeywordOptions() {
            const keywords = new Set();

            this.layoutSnippets.forEach((snippet) => {
                (snippet.keywords || []).forEach((keyword) => keywords.add(keyword));
            });

            return [...keywords].sort().map((keyword) => ({ keyword }));
        },
    },

    watch: {
        productId: {
            immediate: true,
            handler(newId, oldId) {
                if (newId && newId !== oldId) {
                    this.initFormFields();
                    this.initMergeTemplates();
                    this.initPriceConfig();
                    this.initBookSettings();
                    this.initPhotobookTheme();
                    this.initInsidePageCount();
                    this.initInsidePageCountEnabled();
                    this.initMagicPhotobookEnabled();
                    this.initSlimUiEnabled();
                    this.loadConfiguratorGroupNames();
                    this.loadPropertyGroupOptions();
                    this.maybeLoadDefaultLanguageFields();
                }
            },
        },

        searchTerm(value) {
            clearTimeout(this._searchDebounceTimer);
            this._searchDebounceTimer = setTimeout(() => {
                this.debouncedSearchTerm = value;
            }, TEMPLATE_SEARCH_DEBOUNCE_MS);
        },
    },

    created() {
        this._searchDebounceTimer = null;
        this._activationConflictTimer = null;
        this.languageRepository = this.repositoryFactory.create('language');
        this.loadConfigStatus();
        this.loadShopLanguages();
    },

    beforeUnmount() {
        clearTimeout(this._searchDebounceTimer);
        clearTimeout(this._activationConflictTimer);
        Object.values(this._productSettingsSaveDebounceTimers ?? {}).forEach(clearTimeout);
    },

    methods: {
        initFormFields() {
            const stored = this.perLanguageEnabled
                ? this.product?.customFields?.PrintessFormFields
                : this.defaultLanguageFields.PrintessFormFields;

            this.formFields = Array.isArray(stored)
                ? stored.map((field) => ({
                    name: field?.name ?? '',
                    value: field?.value ?? '',
                    source: field?.source === 'variable' ? 'variable' : 'fixed',
                    variable: isValidFormFieldVariable(field?.variable) ? field.variable : '',
                }))
                : [];
        },

        loadConfiguratorGroupNames() {
            return this.printessTemplateService.getConfiguratorGroupNames(this.productId)
                .then((response) => {
                    this.configuratorGroupNames = response?.groupNames ?? [];
                })
                .catch(() => {
                    this.configuratorGroupNames = [];
                });
        },

        loadPropertyGroupOptions() {
            return this.printessTemplateService.getPropertyGroupOptions(this.productId)
                .then((response) => {
                    this.productPropertyGroups = response?.propertyGroups ?? [];
                })
                .catch(() => {
                    this.productPropertyGroups = [];
                });
        },

        addFormField() {
            this.formFields.push({ name: '', value: '', source: 'fixed', variable: '' });
        },

        updateFormField(index, key, value) {
            this.formFields[index][key] = value;
            this.syncFormFields();
        },

        removeFormField(index) {
            this.formFields.splice(index, 1);
            this.syncFormFields();
        },

        syncFormFields() {
            const serialized = this.formFields
                .filter((field) => field.name && field.name.trim() !== '')
                .map((field) => {
                    const serializedField = { name: field.name.trim(), value: field.value ?? '' };

                    if (field.source === 'variable' && field.variable) {
                        serializedField.source = 'variable';
                        serializedField.variable = field.variable;
                    }

                    return serializedField;
                });

            if (this.perLanguageEnabled) {
                if (!this.product.customFields) {
                    this.product.customFields = {};
                }

                this.product.customFields.PrintessFormFields = serialized;
            } else {
                this.persistDefaultLanguageFieldDebounced('PrintessFormFields', serialized);
            }
        },

        initMergeTemplates() {
            const stored = (this.perLanguageEnabled
                ? this.product?.customFields?.PrintessMergeTemplates
                : this.defaultLanguageFields.PrintessMergeTemplates) ?? null;

            this.mergeTemplates = parseMergeTemplates(stored).map((entry) => this.createMergeTemplateRow(entry));

            const idsToResolve = this.mergeTemplates
                .map((row) => decodeLayoutSnippetReference(row.templateName)?.id)
                .filter(Boolean);

            this.resolveLayoutSnippetLabels(idsToResolve);
        },

        createMergeTemplateRow(entry) {
            const { templateName, mergeMode, documentName, spreadIndex, ...extra } = entry || {};

            this.mergeTemplateIdSeed += 1;

            return {
                clientId: this.mergeTemplateIdSeed,
                templateName: templateName || '',
                mergeMode: mergeMode || 'merge',
                documentName: documentName || '',
                spreadIndex: typeof spreadIndex === 'number' && Number.isFinite(spreadIndex) ? spreadIndex : null,
                extra,
            };
        },

        addMergeTemplateRow() {
            this.mergeTemplates.push(this.createMergeTemplateRow({}));
        },

        updateMergeTemplateField(clientId, key, value) {
            const row = this.mergeTemplates.find((mergeTemplate) => mergeTemplate.clientId === clientId);

            if (!row) {
                return;
            }

            if (key === 'spreadIndex') {
                const numeric = Number(value);
                row.spreadIndex = value === '' || value === null || typeof value === 'undefined' || Number.isNaN(numeric) ? null : numeric;
            } else if (key === 'mergeMode') {
                if (isLayoutSnippetMergeMode(row.mergeMode) !== isLayoutSnippetMergeMode(value)) {
                    row.templateName = '';
                }

                row.mergeMode = value;
            } else {
                row[key] = value;
            }

            this.syncMergeTemplates();
        },

        removeMergeTemplateRow(clientId) {
            this.mergeTemplates = this.mergeTemplates.filter((mergeTemplate) => mergeTemplate.clientId !== clientId);
            this.syncMergeTemplates();
        },

        syncMergeTemplates() {
            const serialized = this.mergeTemplates
                .filter((row) => row.templateName && row.templateName.trim() !== '')
                .map((row) => {
                    const cleaned = { ...row.extra, templateName: row.templateName.trim() };

                    if (row.mergeMode && row.mergeMode !== 'merge') {
                        cleaned.mergeMode = row.mergeMode;
                    }

                    if (row.documentName && row.documentName.trim() !== '') {
                        cleaned.documentName = row.documentName.trim();
                    }

                    if (typeof row.spreadIndex === 'number' && Number.isFinite(row.spreadIndex)) {
                        cleaned.spreadIndex = row.spreadIndex;
                    }

                    return cleaned;
                });

            const value = serialized.length > 0 ? JSON.stringify(serialized) : null;

            if (this.perLanguageEnabled) {
                if (!this.product.customFields) {
                    this.product.customFields = {};
                }

                this.product.customFields.PrintessMergeTemplates = value;
            } else {
                this.persistDefaultLanguageFieldDebounced('PrintessMergeTemplates', value);
            }
        },

        initPriceConfig() {
            const stored = this.perLanguageEnabled
                ? this.product?.customFields?.PrintessPriceConfig
                : this.defaultLanguageFields.PrintessPriceConfig;

            const parsed = parsePriceConfig(stored);

            this.includedPageCount = typeof parsed?.includedPageCount === 'number' ? parsed.includedPageCount : null;
            this.pricePerPage = typeof parsed?.pricePerPage === 'number' ? parsed.pricePerPage : null;
            this.priceRules = Array.isArray(parsed?.formFieldPrices)
                ? parsed.formFieldPrices.map((entry) => this.createPriceRuleRow(entry))
                : [];
        },

        createPriceRuleRow(entry) {
            const conditions = normalizePriceRuleConditions(entry?.formFields);

            this.priceRuleIdSeed += 1;

            return {
                clientId: this.priceRuleIdSeed,
                conditions: conditions.length > 0 ? conditions : [{ name: '', value: '' }],
                labelByLanguageId: normalizePriceRuleLabel(entry?.label, this.systemLanguageId),
                price: typeof entry?.price === 'number' ? entry.price : null,
                pricePerPage: typeof entry?.pricePerPage === 'number' ? entry.pricePerPage : null,
            };
        },

        addPriceRule() {
            this.priceRules.push(this.createPriceRuleRow(null));
        },

        removePriceRule(clientId) {
            this.priceRules = this.priceRules.filter((rule) => rule.clientId !== clientId);
            this.syncPriceConfig();
        },

        updatePriceRuleField(clientId, key, value) {
            const rule = this.priceRules.find((candidate) => candidate.clientId === clientId);

            if (!rule) {
                return;
            }

            const numeric = Number(value);
            rule[key] = value === '' || value === null || typeof value === 'undefined' || Number.isNaN(numeric) ? null : numeric;

            this.syncPriceConfig();
        },

        updatePriceRuleLabel(clientId, languageId, value) {
            const rule = this.priceRules.find((candidate) => candidate.clientId === clientId);

            if (!rule) {
                return;
            }

            rule.labelByLanguageId = { ...rule.labelByLanguageId, [languageId]: value ?? '' };
            this.syncPriceConfig();
        },

        addPriceRuleCondition(clientId) {
            const rule = this.priceRules.find((candidate) => candidate.clientId === clientId);

            if (!rule) {
                return;
            }

            rule.conditions.push({ name: '', value: '', compareBy: 'value', operator: 'equals' });
        },

        removePriceRuleCondition(clientId, conditionIndex) {
            const rule = this.priceRules.find((candidate) => candidate.clientId === clientId);

            if (!rule || rule.conditions.length <= 1) {
                return;
            }

            rule.conditions.splice(conditionIndex, 1);
            this.syncPriceConfig();
        },

        updatePriceRuleCondition(clientId, conditionIndex, key, value) {
            const rule = this.priceRules.find((candidate) => candidate.clientId === clientId);

            if (!rule || !rule.conditions[conditionIndex]) {
                return;
            }

            const condition = rule.conditions[conditionIndex];

            // Comparing text only ever makes sense as equals/not-equals, so switching back from
            // "Length" drops an operator like "greater than" that no longer applies; the previously
            // entered value is cleared too, since a length is a number but a value is text.
            if (key === 'compareBy' && value !== condition.compareBy) {
                if (value !== 'length' && LENGTH_ONLY_OPERATORS.includes(condition.operator)) {
                    condition.operator = 'equals';
                }

                condition.value = value === 'length' ? null : '';
            }

            condition[key] = value;
            this.syncPriceConfig();
        },

        /**
         * Comparing a form field's raw value only ever makes sense as an equals/not-equals check;
         * comparing its length also supports numeric ordering operators.
         */
        operatorOptionsFor(compareBy) {
            const options = [
                { value: 'equals', label: this.$t('printess-shopware-integration.productDetail.operatorEquals') },
                { value: 'notEquals', label: this.$t('printess-shopware-integration.productDetail.operatorNotEquals') },
            ];

            if (compareBy !== 'length') {
                return options;
            }

            return [
                ...options,
                { value: 'greaterThan', label: this.$t('printess-shopware-integration.productDetail.operatorGreaterThan') },
                { value: 'greaterThanOrEqual', label: this.$t('printess-shopware-integration.productDetail.operatorGreaterThanOrEqual') },
                { value: 'lessThan', label: this.$t('printess-shopware-integration.productDetail.operatorLessThan') },
                { value: 'lessThanOrEqual', label: this.$t('printess-shopware-integration.productDetail.operatorLessThanOrEqual') },
            ];
        },

        updateIncludedPageCount(value) {
            const numeric = Number(value);
            this.includedPageCount = value === '' || value === null || typeof value === 'undefined' || Number.isNaN(numeric) ? null : numeric;
            this.syncPriceConfig();
        },

        updatePricePerPage(value) {
            const numeric = Number(value);
            this.pricePerPage = value === '' || value === null || typeof value === 'undefined' || Number.isNaN(numeric) ? null : numeric;
            this.syncPriceConfig();
        },

        syncPriceConfig() {
            const formFieldPrices = this.priceRules
                .map((rule) => {
                    const conditions = rule.conditions
                        .filter((condition) => condition.name && condition.name.trim() !== '')
                        .map((condition) => {
                            const serializedCondition = { name: condition.name.trim(), value: condition.value ?? '' };

                            if (condition.compareBy === 'length') {
                                serializedCondition.compareBy = 'length';
                            }

                            if (condition.operator && condition.operator !== 'equals') {
                                serializedCondition.operator = condition.operator;
                            }

                            return serializedCondition;
                        });

                    if (conditions.length === 0) {
                        return null;
                    }

                    const entry = { formFields: conditions.length === 1 ? conditions[0] : conditions };

                    const labelTranslations = Object.fromEntries(
                        Object.entries(rule.labelByLanguageId || {})
                            .filter(([, labelValue]) => typeof labelValue === 'string' && labelValue.trim() !== '')
                            .map(([languageId, labelValue]) => [languageId, labelValue.trim()]),
                    );

                    if (Object.keys(labelTranslations).length > 0) {
                        entry.label = labelTranslations;
                    }

                    if (typeof rule.price === 'number' && Number.isFinite(rule.price)) {
                        entry.price = rule.price;
                    }

                    if (typeof rule.pricePerPage === 'number' && Number.isFinite(rule.pricePerPage)) {
                        entry.pricePerPage = rule.pricePerPage;
                    }

                    return entry;
                })
                .filter(Boolean);

            const config = {};

            if (typeof this.includedPageCount === 'number' && Number.isFinite(this.includedPageCount)) {
                config.includedPageCount = this.includedPageCount;
            }

            if (typeof this.pricePerPage === 'number' && Number.isFinite(this.pricePerPage)) {
                config.pricePerPage = this.pricePerPage;
            }

            if (formFieldPrices.length > 0) {
                config.formFieldPrices = formFieldPrices;
            }

            const value = Object.keys(config).length > 0 ? JSON.stringify(config) : null;

            if (this.perLanguageEnabled) {
                if (!this.product.customFields) {
                    this.product.customFields = {};
                }

                this.product.customFields.PrintessPriceConfig = value;
            } else {
                this.persistDefaultLanguageFieldDebounced('PrintessPriceConfig', value);
            }
        },

        initBookSettings() {
            const stored = this.perLanguageEnabled
                ? this.product?.customFields?.PrintessBookSettings
                : this.defaultLanguageFields.PrintessBookSettings;

            this.bookSettings = normalizeBookSettings(stored);
        },

        updateBookSettingField(key, value) {
            this.bookSettings = { ...this.bookSettings, [key]: value };
            this.syncBookSettings();
        },

        updateBookSettingIntField(key, value) {
            const numeric = Number(value);
            const normalized = value === '' || value === null || typeof value === 'undefined' || Number.isNaN(numeric) ? null : numeric;
            this.updateBookSettingField(key, normalized);
        },

        syncBookSettings() {
            const config = {};

            BOOK_SETTINGS_TEXT_FIELD_KEYS.forEach((key) => {
                if (typeof this.bookSettings[key] === 'string' && this.bookSettings[key].trim() !== '') {
                    config[key] = this.bookSettings[key].trim();
                }
            });

            BOOK_SETTINGS_INT_FIELD_KEYS.forEach((key) => {
                if (typeof this.bookSettings[key] === 'number' && Number.isFinite(this.bookSettings[key])) {
                    config[key] = this.bookSettings[key];
                }
            });

            BOOK_SETTINGS_BOOL_FIELD_KEYS.forEach((key) => {
                if (this.bookSettings[key] === true) {
                    config[key] = true;
                }
            });

            if (BOOK_SETTINGS_PREVIEW_COVER_TYPES.includes(this.bookSettings.previewCoverType)) {
                config.previewCoverType = this.bookSettings.previewCoverType;
            }

            const value = Object.keys(config).length > 0 ? JSON.stringify(config) : null;

            if (this.perLanguageEnabled) {
                if (!this.product.customFields) {
                    this.product.customFields = {};
                }

                this.product.customFields.PrintessBookSettings = value;
            } else {
                this.persistDefaultLanguageFieldDebounced('PrintessBookSettings', value);
            }
        },

        initPhotobookTheme() {
            const stored = this.perLanguageEnabled
                ? this.product?.customFields?.PrintessPhotobookTheme
                : this.defaultLanguageFields.PrintessPhotobookTheme;

            this.photobookTheme = typeof stored === 'string' ? stored : '';
        },

        updatePhotobookTheme(value) {
            this.photobookTheme = value ?? '';

            const trimmed = this.photobookTheme.trim();
            const syncedValue = trimmed !== '' ? trimmed : null;

            if (this.perLanguageEnabled) {
                if (!this.product.customFields) {
                    this.product.customFields = {};
                }

                this.product.customFields.PrintessPhotobookTheme = syncedValue;
            } else {
                this.persistDefaultLanguageFieldDebounced('PrintessPhotobookTheme', syncedValue);
            }
        },

        initInsidePageCount() {
            const stored = this.perLanguageEnabled
                ? this.product?.customFields?.PrintessBookInsidePageCount
                : this.defaultLanguageFields.PrintessBookInsidePageCount;

            this.insidePageCount = typeof stored === 'number' && Number.isFinite(stored) ? stored : null;
        },

        updateInsidePageCount(value) {
            const numeric = Number(value);
            this.insidePageCount = value === '' || value === null || typeof value === 'undefined' || Number.isNaN(numeric) ? null : numeric;

            if (this.perLanguageEnabled) {
                if (!this.product.customFields) {
                    this.product.customFields = {};
                }

                this.product.customFields.PrintessBookInsidePageCount = this.insidePageCount;
            } else {
                this.persistDefaultLanguageFieldDebounced('PrintessBookInsidePageCount', this.insidePageCount);
            }
        },

        initInsidePageCountEnabled() {
            const stored = this.perLanguageEnabled
                ? this.product?.customFields?.PrintessBookInsidePageCountEnabled
                : this.defaultLanguageFields.PrintessBookInsidePageCountEnabled;

            this.insidePageCountEnabled = stored === true;
        },

        initMagicPhotobookEnabled() {
            const stored = this.perLanguageEnabled
                ? this.product?.customFields?.PrintessMagicPhotobookEnabled
                : this.defaultLanguageFields.PrintessMagicPhotobookEnabled;

            this.magicPhotobookEnabled = stored === true;
        },

        initSlimUiEnabled() {
            const stored = this.perLanguageEnabled
                ? this.product?.customFields?.PrintessSlimUiEnabled
                : this.defaultLanguageFields.PrintessSlimUiEnabled;

            this.slimUiEnabled = stored === true;
        },

        /**
         * Toggle-driven (not typed), so this saves immediately via `saveDefaultLanguageField()`
         * rather than the debounced path, matching `updateInsidePageCountEnabled()`/
         * `updateMagicPhotobookEnabled()`. Unlike those two, this isn't mutually exclusive with
         * anything - it just hides the settings that don't apply to the SlimUi editor (merge
         * templates, the two book-page-count cards, page-relevant custom pricing fields), whose
         * underlying values are left untouched so they're preserved if SlimUi is turned off again.
         */
        updateSlimUiEnabled(value) {
            this.slimUiEnabled = !!value;

            if (this.perLanguageEnabled) {
                if (!this.product.customFields) {
                    this.product.customFields = {};
                }

                this.product.customFields.PrintessSlimUiEnabled = this.slimUiEnabled;
            } else {
                this.saveDefaultLanguageField('PrintessSlimUiEnabled', this.slimUiEnabled);
            }
        },

        /**
         * Rejects a toggle attempt made while the other card is already enabled. `mt-switch` binds
         * its native `<input type="checkbox">` `checked` state directly to the `modelValue` prop with
         * no local state of its own - but a checkbox's `checked` DOM property flips immediately on
         * click, via native browser behavior, before Vue ever re-renders. If `this[flagName]` simply
         * stayed at its previous value (because we refuse to update it), Vue's reactivity sees no
         * change to that prop at all and skips re-patching the DOM, leaving the switch stuck showing
         * "on" even though nothing was actually enabled. Flipping it true, then back to false on the
         * next tick, forces two genuine value transitions so Vue actually re-syncs the checkbox back
         * to unchecked. The conflict banner (`activationConflictCard`) auto-hides after
         * `ACTIVATION_CONFLICT_DISPLAY_MS`.
         */
        _rejectActivation(flagName, cardKey) {
            this[flagName] = true;
            this.$nextTick(() => {
                this[flagName] = false;
            });

            this.activationConflictCard = cardKey;
            this._activationConflictTimer = setTimeout(() => {
                this.activationConflictCard = null;
            }, ACTIVATION_CONFLICT_DISPLAY_MS);
        },

        /**
         * Toggle-driven (not typed), so this saves immediately via `saveDefaultLanguageField()`
         * rather than the debounced path, matching `updatePrintSettingName()`/`updateDropshippingConfig()`.
         * Refuses to enable this while the Magic Photobook card is already enabled - see
         * `_rejectActivation()`/`showInsidePageCountConflictError`.
         */
        updateInsidePageCountEnabled(value) {
            const enabled = !!value;
            clearTimeout(this._activationConflictTimer);
            this.activationConflictCard = null;

            if (enabled && this.magicPhotobookEnabled) {
                this._rejectActivation('insidePageCountEnabled', 'insidePageCount');
                return;
            }

            this.insidePageCountEnabled = enabled;

            if (this.perLanguageEnabled) {
                if (!this.product.customFields) {
                    this.product.customFields = {};
                }

                this.product.customFields.PrintessBookInsidePageCountEnabled = enabled;
            } else {
                this.saveDefaultLanguageField('PrintessBookInsidePageCountEnabled', enabled);
            }
        },

        /**
         * Mirrors `updateInsidePageCountEnabled()` - refuses to enable this while the Book inside
         * page count card is already enabled.
         */
        updateMagicPhotobookEnabled(value) {
            const enabled = !!value;
            clearTimeout(this._activationConflictTimer);
            this.activationConflictCard = null;

            if (enabled && this.insidePageCountEnabled) {
                this._rejectActivation('magicPhotobookEnabled', 'magicPhotobook');
                return;
            }

            this.magicPhotobookEnabled = enabled;

            if (this.perLanguageEnabled) {
                if (!this.product.customFields) {
                    this.product.customFields = {};
                }

                this.product.customFields.PrintessMagicPhotobookEnabled = enabled;
            } else {
                this.saveDefaultLanguageField('PrintessMagicPhotobookEnabled', enabled);
            }
        },

        /**
         * The full list of the shop's active languages, used to render one display-name input per
         * language on each price rule - see `normalizePriceRuleLabel()`. Same criteria
         * (`active = true`, sorted by name) as Shopware's own admin language switcher.
         */
        loadShopLanguages() {
            const criteria = new Criteria(1, 500);
            criteria.addSorting(Criteria.sort('name', 'ASC', false));
            criteria.addFilter(Criteria.equals('active', true));

            return this.languageRepository.search(criteria)
                .then((languages) => {
                    this.shopLanguages = languages.map((language) => ({ id: language.id, name: language.name }));
                })
                .catch(() => {
                    this.shopLanguages = [];
                });
        },

        findTemplateByName(name) {
            return this.templates.find((template) => template.name === name) ?? null;
        },

        isLayoutSnippetMode(mergeMode) {
            return isLayoutSnippetMergeMode(mergeMode);
        },

        layoutSnippetLabel(mergeTemplate) {
            const decoded = decodeLayoutSnippetReference(mergeTemplate.templateName);

            if (!decoded) {
                return mergeTemplate.templateName || '';
            }

            return this.layoutSnippetLabelsById[decoded.id]?.name ?? decoded.id;
        },

        layoutSnippetThumbnail(mergeTemplate) {
            const decoded = decodeLayoutSnippetReference(mergeTemplate.templateName);

            return decoded ? this.layoutSnippetLabelsById[decoded.id]?.thumbnailUrl ?? null : null;
        },

        cacheLayoutSnippetLabels(snippets) {
            (snippets || []).forEach((snippet) => {
                if (snippet?.id) {
                    this.layoutSnippetLabelsById = {
                        ...this.layoutSnippetLabelsById,
                        [snippet.id]: { name: snippet.name, thumbnailUrl: snippet.thumbnailUrl },
                    };
                }
            });
        },

        resolveLayoutSnippetLabels(ids) {
            const idsToLoad = [...new Set(ids)].filter((id) => id && !this.layoutSnippetLabelsById[id]);

            if (idsToLoad.length === 0) {
                return;
            }

            this.printessTemplateService.getLayoutSnippetsById(idsToLoad)
                .then((response) => {
                    this.cacheLayoutSnippetLabels(response?.layoutSnippets ?? []);
                })
                .catch(() => {
                    // Leave unresolved ids as-is; the UI falls back to displaying the raw id.
                });
        },

        openLayoutSnippetPicker(clientId) {
            this.layoutSnippetPickerTarget = clientId;
            this.layoutSnippets = [];
            this.layoutSnippetSearchError = null;
            this.manualLayoutSnippetId = '';
            this.selectedLayoutSnippetTagNames = [];
            this.selectedLayoutSnippetKeywordNames = [];
            this.showLayoutSnippetPicker = true;
            this.loadLayoutSnippetTags();
        },

        closeLayoutSnippetPicker() {
            this.showLayoutSnippetPicker = false;
        },

        loadLayoutSnippetTags() {
            if (this.layoutSnippetTagsLoaded || this.isLoadingLayoutSnippetTags) {
                return;
            }

            this.isLoadingLayoutSnippetTags = true;

            return this.printessTemplateService.getLayoutSnippetTags()
                .then((response) => {
                    this.layoutSnippetTags = response?.tags ?? [];
                    this.layoutSnippetTagsLoaded = true;
                })
                .catch(() => {
                    this.layoutSnippetTags = [];
                })
                .finally(() => {
                    this.isLoadingLayoutSnippetTags = false;
                });
        },

        onLayoutSnippetTagAdded(tagOption) {
            if (!this.selectedLayoutSnippetTagNames.includes(tagOption.tag)) {
                this.selectedLayoutSnippetTagNames = [...this.selectedLayoutSnippetTagNames, tagOption.tag];
            }

            this.searchLayoutSnippets();
        },

        onLayoutSnippetTagRemoved(tagOption) {
            this.selectedLayoutSnippetTagNames = this.selectedLayoutSnippetTagNames.filter((tag) => tag !== tagOption.tag);
            this.searchLayoutSnippets();
        },

        onLayoutSnippetKeywordAdded(keywordOption) {
            if (!this.selectedLayoutSnippetKeywordNames.includes(keywordOption.keyword)) {
                this.selectedLayoutSnippetKeywordNames = [...this.selectedLayoutSnippetKeywordNames, keywordOption.keyword];
            }

            this.searchLayoutSnippets();
        },

        onLayoutSnippetKeywordRemoved(keywordOption) {
            this.selectedLayoutSnippetKeywordNames = this.selectedLayoutSnippetKeywordNames
                .filter((keyword) => keyword !== keywordOption.keyword);
            this.searchLayoutSnippets();
        },

        /**
         * `sw-multi-select` only closes its dropdown on an outside click, which isn't obvious to
         * users. It doesn't expose a public method or prop to close it programmatically, so this
         * simulates the one interaction it DOES already listen for directly on its trigger
         * element: pressing Escape (`sw-select-base` binds `@keydown.esc` on `.sw-select__selection`).
         */
        closeLayoutSnippetTagDropdown() {
            const trigger = this.$refs.layoutSnippetTagSelect?.$el?.querySelector?.('.sw-select__selection');

            trigger?.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
        },

        closeLayoutSnippetKeywordDropdown() {
            const trigger = this.$refs.layoutSnippetKeywordSelect?.$el?.querySelector?.('.sw-select__selection');

            trigger?.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
        },

        searchLayoutSnippets() {
            if (this.selectedLayoutSnippetTagNames.length === 0) {
                this.layoutSnippets = [];
                this.layoutSnippetSearchError = null;

                return Promise.resolve();
            }

            this.isLoadingLayoutSnippets = true;
            this.layoutSnippetSearchError = null;

            return this.printessTemplateService.searchLayoutSnippets(this.selectedLayoutSnippetTagNames, this.selectedLayoutSnippetKeywordNames)
                .then((response) => {
                    this.layoutSnippets = response?.layoutSnippets ?? [];
                    this.cacheLayoutSnippetLabels(this.layoutSnippets);
                })
                .catch((error) => {
                    this.layoutSnippets = [];
                    this.layoutSnippetSearchError = error?.response?.data?.error
                        ?? error?.message
                        ?? this.$t('printess-shopware-integration.productDetail.layoutSnippetSearchError');
                })
                .finally(() => {
                    this.isLoadingLayoutSnippets = false;
                });
        },

        selectLayoutSnippet(snippet) {
            this.cacheLayoutSnippetLabels([snippet]);
            this.updateMergeTemplateField(
                this.layoutSnippetPickerTarget,
                'templateName',
                encodeLayoutSnippetReference(snippet.id, snippet.isGlobal),
            );
            this.closeLayoutSnippetPicker();
        },

        useManualLayoutSnippetId() {
            const id = this.manualLayoutSnippetId.trim();

            if (!id) {
                return;
            }

            this.updateMergeTemplateField(this.layoutSnippetPickerTarget, 'templateName', encodeLayoutSnippetReference(id, false));
            this.resolveLayoutSnippetLabels([id]);
            this.closeLayoutSnippetPicker();
        },

        formFieldNameCollision(name) {
            if (!name) {
                return false;
            }

            return this.configuratorGroupNames.includes(name.trim());
        },

        loadConfigStatus() {
            this.isLoadingConfigStatus = true;

            return this.printessTemplateService.getConfigStatus()
                .then((response) => {
                    this.hasServiceToken = !!response?.hasServiceToken;
                    this.hasShopToken = !!response?.hasShopToken;
                    this.perLanguageEnabled = !!response?.perLanguageProductSettingsEnabled;

                    if (this.hasServiceToken && this.hasShopToken) {
                        this.loadTemplates();
                    }

                    this.maybeLoadDefaultLanguageFields();
                })
                .catch(() => {
                    this.hasServiceToken = false;
                    this.hasShopToken = false;
                })
                .finally(() => {
                    this.isLoadingConfigStatus = false;
                });
        },

        /**
         * Only relevant when `perLanguageEnabled` is off: fetches the shared default-language
         * values for the 5 Printess product fields, used for display/editing instead of whatever
         * `this.product.customFields` resolved for the admin's currently active language. Re-inits
         * the form-field/merge-template rows afterwards, since those were initialized before this
         * data was available.
         */
        maybeLoadDefaultLanguageFields() {
            if (this.perLanguageEnabled || !this.productId || this.isLoadingDefaultLanguageFields) {
                return;
            }

            this.isLoadingDefaultLanguageFields = true;
            this.defaultLanguageFieldsLoaded = false;

            this.printessTemplateService.getDefaultLanguageProductSettings(this.productId)
                .then((response) => {
                    this.defaultLanguageFields = response ?? {};
                    this.defaultLanguageFieldsLoaded = true;
                    this.initFormFields();
                    this.initMergeTemplates();
                    this.initPriceConfig();
                    this.initBookSettings();
                    this.initPhotobookTheme();
                    this.initInsidePageCount();
                    this.initInsidePageCountEnabled();
                    this.initMagicPhotobookEnabled();
                    this.initSlimUiEnabled();
                })
                .catch(() => {
                    this.defaultLanguageFields = {};
                    this.defaultLanguageFieldsLoaded = true;
                })
                .finally(() => {
                    this.isLoadingDefaultLanguageFields = false;
                });
        },

        /**
         * Debounced auto-save for the two fields edited via free-text typing (form fields, merge
         * templates) - saving on every keystroke would be excessive. Template/print-setting/
         * dropshipping selections are picker-driven, not typed, so those save immediately via
         * `saveDefaultLanguageField()` instead.
         */
        persistDefaultLanguageFieldDebounced(fieldName, value) {
            this.defaultLanguageFields = { ...this.defaultLanguageFields, [fieldName]: value };

            this._productSettingsSaveDebounceTimers ??= {};
            clearTimeout(this._productSettingsSaveDebounceTimers[fieldName]);
            this._productSettingsSaveDebounceTimers[fieldName] = setTimeout(() => {
                this.saveDefaultLanguageField(fieldName, value);
            }, PRODUCT_SETTINGS_SAVE_DEBOUNCE_MS);
        },

        saveDefaultLanguageField(fieldName, value) {
            this.defaultLanguageFields = { ...this.defaultLanguageFields, [fieldName]: value };
            this.productSettingsSaveError = null;

            return this.printessTemplateService.setDefaultLanguageProductSettings(this.productId, { [fieldName]: value })
                .catch((error) => {
                    this.productSettingsSaveError = error?.response?.data?.error
                        ?? error?.message
                        ?? this.$t('printess-shopware-integration.productDetail.saveDefaultLanguageError');
                });
        },

        loadTemplates() {
            this.isLoadingTemplates = true;
            this.loadError = null;

            return this.printessTemplateService.getTemplates()
                .then((response) => {
                    this.templates = response?.templates ?? [];
                })
                .catch((error) => {
                    this.loadError = error?.response?.data?.error
                        ?? error?.message
                        ?? this.$t('printess-shopware-integration.productDetail.loadError');
                })
                .finally(() => {
                    this.isLoadingTemplates = false;
                });
        },

        openTemplatePicker(target) {
            clearTimeout(this._searchDebounceTimer);
            this.templatePickerTarget = target;
            this.searchTerm = '';
            this.debouncedSearchTerm = '';
            this.showTemplatePicker = true;
        },

        closeTemplatePicker() {
            this.showTemplatePicker = false;
        },

        selectTemplate(template) {
            if (this.templatePickerTarget === 'main') {
                if (this.perLanguageEnabled) {
                    if (!this.product.customFields) {
                        this.product.customFields = {};
                    }

                    this.product.customFields.PrintessTemplateName = template.name;
                } else {
                    this.saveDefaultLanguageField('PrintessTemplateName', template.name);
                }
            } else {
                this.updateMergeTemplateField(this.templatePickerTarget, 'templateName', template.name);
            }

            this.closeTemplatePicker();
        },

        clearTemplate() {
            if (this.perLanguageEnabled) {
                if (this.product.customFields) {
                    this.product.customFields.PrintessTemplateName = null;
                }
            } else {
                this.saveDefaultLanguageField('PrintessTemplateName', null);
            }
        },

        updatePrintSettingName(value) {
            if (this.perLanguageEnabled) {
                if (!this.product.customFields) {
                    this.product.customFields = {};
                }

                this.product.customFields.PrintessPrintSettingName = value || null;
            } else {
                this.saveDefaultLanguageField('PrintessPrintSettingName', value || null);
            }
        },

        updateDropshippingConfig(value) {
            if (this.perLanguageEnabled) {
                if (!this.product.customFields) {
                    this.product.customFields = {};
                }

                this.product.customFields.PrintessDropshippingConfig = value || null;
            } else {
                this.saveDefaultLanguageField('PrintessDropshippingConfig', value || null);
            }
        },
    },
});
