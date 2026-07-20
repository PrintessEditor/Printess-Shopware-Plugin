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

const { Criteria } = Shopware.Data;

/**
 * Matches `ProductCustomFieldsInstaller::CUSTOM_FIELD_SET_NAME` - the Printess fields are already
 * fully managed on their own "Printess" tab (including a proper JSON editor for
 * `PrintessFormFields`), so showing the same raw custom field set again in the generic
 * "Specifications" tab is redundant and, for the JSON field, actively broken: the generic
 * custom-field renderer has no widget for JSON arrays-of-objects and just stringifies each entry
 * (`[object Object],[object Object]`).
 */
const PRINTESS_CUSTOM_FIELD_SET_NAME = 'Printess';

Shopware.Component.override('sw-product-detail', {
    template,

    computed: {
        customFieldSetCriteria() {
            const criteria = this.$super('customFieldSetCriteria');

            criteria.addFilter(Criteria.not('AND', [Criteria.equals('name', PRINTESS_CUSTOM_FIELD_SET_NAME)]));

            return criteria;
        },
    },
});
