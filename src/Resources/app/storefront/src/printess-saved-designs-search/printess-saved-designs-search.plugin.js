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

import Plugin from 'src/plugin-system/plugin.class';

const PRODUCT_SELECTOR = '.printess-saved-designs__product';
const DESIGN_SELECTOR = '.printess-saved-designs__design';

/**
 * Instant, client-side filter over the "My saved designs" page - matches against either the
 * product's name or an individual saved design's own display name. Everything is already rendered
 * up front (no pagination), so this just shows/hides existing DOM nodes rather than re-fetching
 * anything: a product's name matching shows all of its designs, otherwise only the designs whose
 * own name matches stay visible, and a product with no visible designs left is hidden entirely.
 */
export default class PrintessSavedDesignsSearchPlugin extends Plugin {
    init() {
        this.products = Array.from(document.querySelectorAll(PRODUCT_SELECTOR));
        this.noResultsEl = document.querySelector('[data-printess-saved-designs-no-results]');

        this._registerEvents();
    }

    _registerEvents() {
        this.el.addEventListener('input', this._onInput.bind(this));
    }

    _onInput() {
        const term = this.el.value.trim().toLowerCase();
        let anyVisible = false;

        this.products.forEach((product) => {
            const productMatches = term === '' || this._matches(product.dataset.printessSearchProductName, term);
            let productHasVisibleDesign = false;

            Array.from(product.querySelectorAll(DESIGN_SELECTOR)).forEach((design) => {
                const visible = productMatches || this._matches(design.dataset.printessSearchDesignName, term);

                design.classList.toggle('d-none', !visible);

                if (visible) {
                    productHasVisibleDesign = true;
                }
            });

            product.classList.toggle('d-none', !productHasVisibleDesign);

            if (productHasVisibleDesign) {
                anyVisible = true;
            }
        });

        if (this.noResultsEl) {
            this.noResultsEl.classList.toggle('d-none', term === '' || anyVisible);
        }
    }

    _matches(value, term) {
        return (value || '').toLowerCase().includes(term);
    }
}
