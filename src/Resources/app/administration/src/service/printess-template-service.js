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

export default class PrintessTemplateService extends Shopware.Classes.ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'printess') {
        super(httpClient, loginService, apiEndpoint);
        this.name = 'printessTemplateService';
    }

    getTemplates() {
        return this.httpClient
            .post('/_action/printess/templates', {}, { headers: this.getBasicHeaders() })
            .then((response) => Shopware.Classes.ApiService.handleResponse(response));
    }

    getConfigStatus() {
        return this.httpClient
            .get('/_action/printess/config-status', { headers: this.getBasicHeaders() })
            .then((response) => Shopware.Classes.ApiService.handleResponse(response));
    }

    getConfiguratorGroupNames(productId) {
        return this.httpClient
            .get(`/_action/printess/products/${productId}/configurator-group-names`, { headers: this.getBasicHeaders() })
            .then((response) => Shopware.Classes.ApiService.handleResponse(response));
    }

    getPropertyGroupOptions(productId) {
        return this.httpClient
            .get(`/_action/printess/products/${productId}/property-group-options`, { headers: this.getBasicHeaders() })
            .then((response) => Shopware.Classes.ApiService.handleResponse(response));
    }

    getLayoutSnippetTags() {
        return this.httpClient
            .get('/_action/printess/layout-snippet-tags', { headers: this.getBasicHeaders() })
            .then((response) => Shopware.Classes.ApiService.handleResponse(response));
    }

    searchLayoutSnippets(tags, keywords) {
        return this.httpClient
            .post('/_action/printess/layout-snippets/search', { tags, keywords }, { headers: this.getBasicHeaders() })
            .then((response) => Shopware.Classes.ApiService.handleResponse(response));
    }

    getLayoutSnippetsById(ids) {
        return this.httpClient
            .post('/_action/printess/layout-snippets/by-id', { ids }, { headers: this.getBasicHeaders() })
            .then((response) => Shopware.Classes.ApiService.handleResponse(response));
    }

    getPrintSettings() {
        return this.httpClient
            .get('/_action/printess/print-settings', { headers: this.getBasicHeaders() })
            .then((response) => Shopware.Classes.ApiService.handleResponse(response));
    }

    getDropshipProductDefinitions() {
        return this.httpClient
            .get('/_action/printess/dropship-product-definitions', { headers: this.getBasicHeaders() })
            .then((response) => Shopware.Classes.ApiService.handleResponse(response));
    }

    getPhotobookThemes() {
        return this.httpClient
            .get('/_action/printess/photobook-themes', { headers: this.getBasicHeaders() })
            .then((response) => Shopware.Classes.ApiService.handleResponse(response));
    }

    getImpositions() {
        return this.httpClient
            .get('/_action/printess/impositions', { headers: this.getBasicHeaders() })
            .then((response) => Shopware.Classes.ApiService.handleResponse(response));
    }

    getEditorConfig(salesChannelId, productId) {
        const params = {};

        if (salesChannelId) {
            params.salesChannelId = salesChannelId;
        }

        if (productId) {
            params.productId = productId;
        }

        return this.httpClient
            .get('/_action/printess/editor-config', {
                params,
                headers: this.getBasicHeaders(),
            })
            .then((response) => Shopware.Classes.ApiService.handleResponse(response));
    }

    getDefaultLanguageProductSettings(productId) {
        return this.httpClient
            .get(`/_action/printess/products/${productId}/default-language-settings`, { headers: this.getBasicHeaders() })
            .then((response) => Shopware.Classes.ApiService.handleResponse(response));
    }

    setDefaultLanguageProductSettings(productId, values) {
        return this.httpClient
            .post(`/_action/printess/products/${productId}/default-language-settings`, values, { headers: this.getBasicHeaders() })
            .then((response) => Shopware.Classes.ApiService.handleResponse(response));
    }
}
