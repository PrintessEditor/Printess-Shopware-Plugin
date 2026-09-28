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

import template from './printess-variant-sync-modal.html.twig';
import './printess-variant-sync-modal.scss';

const SNIPPET_PREFIX = 'printess-shopware-integration.productDetail.variantSync';
const PREVIEW_DEBOUNCE_MS = 200;

/**
 * "Build variants from the template" dialog. It only ever sends the merchant's *choices* - which
 * template fields to use, and which groups/options/variants to keep - and shows the plan the server
 * computes from them. Applying sends the same choices, and the server recomputes the plan, so
 * nothing can be applied that was not shown.
 */
export default Shopware.Component.wrapComponentConfig({
    template,

    inject: ['printessTemplateService', 'repositoryFactory'],

    emits: ['close', 'applied'],

    props: {
        productId: {
            type: String,
            required: true,
        },

        templateName: {
            type: String,
            required: true,
        },

        product: {
            type: Object,
            required: false,
            default: null,
        },

        canEdit: {
            type: Boolean,
            required: false,
            default: false,
        },
    },

    data() {
        return {
            plan: null,
            // null until the first preview, which then preselects every field matching a group.
            selected: null,
            keep: { groups: [], options: [], variants: [] },
            requestId: 0,
            previewTimer: null,
            isLoading: true,
            isApplying: false,
            error: null,
        };
    },

    computed: {
        productRepository() {
            return this.repositoryFactory.create('product');
        },

        /**
         * Applying reloads the product afterwards, which would discard unsaved edits made on the
         * product's other tabs - so those have to be saved first.
         */
        hasUnsavedChanges() {
            return !!this.product && this.productRepository.hasChanges(this.product);
        },

        canApply() {
            return this.canEdit
                && !this.isLoading
                && !this.isApplying
                && !this.hasUnsavedChanges
                && !!this.plan
                && (this.selected ?? []).length > 0;
        },

        variants() {
            return this.plan?.variants ?? null;
        },

        variantsUnchanged() {
            const variants = this.variants;

            return !!variants
                && variants.create.length === 0
                && variants.update === 0
                && variants.delete.length === 0
                && variants.merged.length === 0;
        },
    },

    created() {
        this.preview();
    },

    beforeUnmount() {
        clearTimeout(this.previewTimer);
    },

    methods: {
        async preview() {
            const requestId = ++this.requestId;

            this.isLoading = true;
            this.error = null;

            try {
                const plan = await this.printessTemplateService.syncVariants(this.productId, {
                    templateName: this.templateName,
                    fields: this.selected ?? [],
                    keep: this.keep,
                    apply: false,
                });

                if (requestId !== this.requestId) {
                    return;
                }

                if (this.selected === null) {
                    this.selected = plan.choices
                        .filter((choice) => choice.matches !== null)
                        .map((choice) => choice.name);

                    if (this.selected.length) {
                        await this.preview();

                        return;
                    }
                }

                this.plan = plan;
            } catch (error) {
                if (requestId === this.requestId) {
                    this.error = this.errorMessage(error);
                }
            } finally {
                if (requestId === this.requestId) {
                    this.isLoading = false;
                }
            }
        },

        schedulePreview() {
            clearTimeout(this.previewTimer);
            this.isLoading = true;
            this.previewTimer = setTimeout(() => this.preview(), PREVIEW_DEBOUNCE_MS);
        },

        isSelected(choice) {
            return (this.selected ?? []).includes(choice.name);
        },

        /** A colour field can only use the product's own colours, so it needs a group to take them from. */
        isChoiceUnavailable(choice) {
            return choice.open && choice.matches === null;
        },

        toggleChoice(choice, checked) {
            const selected = (this.selected ?? []).filter((name) => name !== choice.name);

            if (checked) {
                selected.push(choice.name);
            }

            this.selected = selected;
            this.schedulePreview();
        },

        /**
         * Removal checkboxes are ticked for "remove"; unticking one keeps that group, option or
         * variant.
         */
        setRemoval(kind, id, remove) {
            const list = this.keep[kind].filter((candidate) => candidate !== id);

            if (!remove) {
                list.push(id);
            }

            this.keep = { ...this.keep, [kind]: list };
            this.schedulePreview();
        },

        async apply() {
            if (!this.canApply) {
                return;
            }

            this.isApplying = true;
            this.error = null;

            try {
                const result = await this.printessTemplateService.syncVariants(this.productId, {
                    templateName: this.templateName,
                    fields: this.selected,
                    keep: this.keep,
                    apply: true,
                });

                this.$emit('applied', result?.applied ?? {});
            } catch (error) {
                this.error = this.errorMessage(error);
            } finally {
                this.isApplying = false;
            }
        },

        close() {
            if (!this.isApplying) {
                this.$emit('close');
            }
        },

        errorMessage(error) {
            return error?.response?.data?.error ?? error?.message ?? this.$t(`${SNIPPET_PREFIX}.failed`);
        },

        quoted(values) {
            return values.map((value) => `“${value}”`).join(', ');
        },

        choiceTitle(choice) {
            return choice.label !== choice.name ? `${choice.label} (${choice.name})` : choice.label;
        },

        choiceValues(choice) {
            if (choice.open) {
                return this.$t(`${SNIPPET_PREFIX}.${choice.matches !== null ? 'openValues' : 'openNeedsGroup'}`);
            }

            return this.$t(`${SNIPPET_PREFIX}.values`, { count: choice.values.length, values: choice.values.join(', ') });
        },

        choiceMatch(choice) {
            if (choice.matches !== null) {
                return this.$t(`${SNIPPET_PREFIX}.matches`, { group: choice.matches });
            }

            if (choice.open) {
                return '';
            }

            if (choice.matchesShared !== null) {
                return this.$t(`${SNIPPET_PREFIX}.matchesShared`, { group: choice.matchesShared });
            }

            return this.$t(`${SNIPPET_PREFIX}.newGroup`);
        },

        isGroupUnchanged(group) {
            return !group.isNew
                && !group.addedToProduct
                && group.added.length === 0
                && group.created.length === 0
                && group.removed.length === 0
                && group.kept.length === 0;
        },

        groupTitle(group) {
            if (group.isNew) {
                return this.$t(`${SNIPPET_PREFIX}.groupNew`, { group: group.name });
            }

            if (group.addedToProduct) {
                return this.$t(`${SNIPPET_PREFIX}.groupAddedToProduct`, { group: group.name });
            }

            return this.$t(`${SNIPPET_PREFIX}.groupKept`, { group: group.name });
        },

        noteText(note) {
            switch (note.type) {
                case 'openNew':
                    return this.$t(`${SNIPPET_PREFIX}.noteOpenNew`, { group: note.group });
                case 'sharedSpelling':
                    return note.value
                        ? this.$t(`${SNIPPET_PREFIX}.noteSharedValueSpelling`, { value: note.value, group: note.group, spelled: note.templateSpelling })
                        : this.$t(`${SNIPPET_PREFIX}.noteSharedGroupSpelling`, { group: note.group, spelled: note.templateSpelling });
                case 'sharedTranslation':
                    return note.value
                        ? this.$t(`${SNIPPET_PREFIX}.noteSharedValueTranslation`, { value: note.value, group: note.group, spelled: note.templateSpelling })
                        : this.$t(`${SNIPPET_PREFIX}.noteSharedGroupTranslation`, { group: note.group, spelled: note.templateSpelling });
                case 'closeoutStock':
                    return this.$t(`${SNIPPET_PREFIX}.noteCloseoutStock`);
                default:
                    return '';
            }
        },
    },
});
