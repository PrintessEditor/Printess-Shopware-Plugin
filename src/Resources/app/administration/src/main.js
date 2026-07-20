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

import './sw-product-detail';
import './sw-order-line-items-grid';

import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

import PrintessTemplateService from './service/printess-template-service';
import PrintessProductionService from './service/printess-production-service';

Shopware.Component.register('sw-product-detail-printess', () => import('./sw-product-detail-printess'));
Shopware.Component.register('printess-print-setting-select', () => import('./printess-print-setting-select'));
Shopware.Component.register('printess-photobook-theme-select', () => import('./printess-photobook-theme-select'));
Shopware.Component.register('printess-imposition-select', () => import('./printess-imposition-select'));
Shopware.Component.register('printess-dropshipping-select', () => import('./printess-dropshipping-select'));
Shopware.Component.register('printess-order-line-item-production', () => import('./printess-order-line-item-production'));
Shopware.Component.register('sw-order-line-item-printess-editor', () => import('./sw-order-line-item-printess-editor'));

Shopware.Application.addServiceProvider('printessTemplateService', () => {
    return new PrintessTemplateService(
        Shopware.Application.getContainer('init').httpClient,
        Shopware.Service('loginService'),
    );
});

Shopware.Application.addServiceProvider('printessProductionService', () => {
    return new PrintessProductionService(
        Shopware.Application.getContainer('init').httpClient,
        Shopware.Service('loginService'),
    );
});

Shopware.Module.register('printess-shopware-integration-product-tab', {
    type: 'plugin',
    name: 'PrintessShopwareIntegration',

    routeMiddleware(next, currentRoute) {
        if (currentRoute.name === 'sw.product.detail') {
            currentRoute.children.push({
                name: 'sw.product.detail.printess',
                path: '/sw/product/detail/:id/printess',
                component: 'sw-product-detail-printess',
                meta: {
                    parentPath: 'sw.product.index',
                },
            });
        }

        next(currentRoute);
    },

    snippets: {
        'de-DE': deDE,
        'en-GB': enGB,
    },
});

/**
 * A standalone top-level route (not nested under `sw-order-detail`'s own tabs) since the Printess
 * editor takes over the whole screen as its own overlay - it doesn't belong inside the order
 * detail page's usual card/tab layout the way `sw-product-detail-printess` belongs inside the
 * product detail page's tabs.
 */
Shopware.Module.register('printess-shopware-integration-order-line-item-editor', {
    type: 'plugin',
    name: 'PrintessOrderLineItemEditor',
    routePrefixName: 'sw.order.printess',
    routePrefixPath: 'sw/order/printess',

    routes: {
        lineItemEditor: {
            component: 'sw-order-line-item-printess-editor',
            path: 'line-item-editor/:orderId/:lineItemId',
            meta: {
                privilege: 'order.viewer',
            },
        },
    },

    snippets: {
        'de-DE': deDE,
        'en-GB': enGB,
    },
});
