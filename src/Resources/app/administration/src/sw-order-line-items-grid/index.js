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

import template from './sw-order-line-items-grid.html.twig';

/**
 * Adds a "Printess production" column to the order line items grid, showing the design-approval /
 * production-status / result-links for whichever line items carry a Printess save token. Line items
 * without one render nothing in that column (see `printess-order-line-item-production`).
 */
Shopware.Component.override('sw-order-line-items-grid', {
    template,

    computed: {
        getLineItemColumns() {
            const columns = this.$super('getLineItemColumns');

            return [
                ...columns,
                {
                    property: 'printessProduction',
                    dataIndex: 'printessProduction',
                    label: 'printess-shopware-integration.orderLineItemProduction.columnLabel',
                    allowResize: true,
                    multiLine: true,
                },
            ];
        },
    },
});
