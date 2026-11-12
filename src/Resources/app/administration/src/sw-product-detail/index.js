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

import template from './sw-product-detail.html.twig';

/**
 * Matches `ProductCustomFieldsInstaller::CUSTOM_FIELD_SET_NAME` - the Printess fields are already
 * fully managed on their own "Printess" tab, so showing the same raw custom field set again in the
 * generic "Specifications" tab is redundant and confusing, and for the JSON fields actively broken:
 * the generic custom-field renderer has no widget for JSON arrays-of-objects and just stringifies
 * each entry (`[object Object],[object Object]`).
 */
const PRINTESS_CUSTOM_FIELD_SET_NAME = 'Printess';

Shopware.Component.override('sw-product-detail', {
    template,

    methods: {
        /**
         * Since Shopware 6.7 the sets come from `customFieldDataProviderService.getCustomFieldSets()`
         * (cached, keyed by entity and language) instead of a component criteria, so they're filtered
         * after loading. `filter()` returns a new array, leaving the service's cached result intact.
         * Only hides the set in this view - the custom fields themselves stay assigned to products,
         * so the API, import/export and the rule builder keep seeing them.
         */
        loadAttributeSet() {
            return this.$super('loadAttributeSet').then(() => {
                const store = Shopware.Store.get('swProductDetail');

                if (Array.isArray(store.customFieldSets)) {
                    store.customFieldSets = store.customFieldSets
                        .filter((set) => set?.name !== PRINTESS_CUSTOM_FIELD_SET_NAME);
                }
            });
        },
    },
});
