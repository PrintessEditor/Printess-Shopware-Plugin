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

/**
 * Core's own `lineItems[{id}][id]` input - the only `lineItems[...]` field whose name ends in `[id]`
 * (the Printess hidden fields in `buy-widget-form.html.twig` all end in `[_printess...]`), so it
 * identifies which product the buy form currently adds.
 */
const LINE_ITEM_ID_INPUT_SELECTOR = 'input[name^="lineItems["][name$="][id]"]';
const LINE_ITEM_REFERENCED_ID_INPUT_SELECTOR = 'input[name^="lineItems["][name$="][referencedId]"]';
const REDIRECT_PARAMETERS_INPUT_SELECTOR = '[data-redirect-parameters]';

/**
 * Points the buy form's line item at a different variant than the one the page was rendered for.
 *
 * Core renders every line item field as `lineItems[{{ product.id }}][...]`, with that id fixed at
 * render time. An editor that switches variants without re-rendering the buy widget (SlimUi always,
 * the full editor when its switch has not landed) would therefore add the rendered variant, not the
 * one whose price is on screen. Both the key and the `id`/`referencedId` values are rewritten:
 * `LineItemAddedSubscriber` looks the Printess fields up by `$lineItem->getId()`, so the key and the
 * id must stay in step.
 *
 * Reads the current id back from the form on every call, so it can run again after further variant
 * changes. Returns whether anything was changed.
 */
export function retargetBuyFormLineItem(form, productId) {
    if (!form || !productId) {
        return false;
    }

    const idInput = form.querySelector(LINE_ITEM_ID_INPUT_SELECTOR);
    const currentId = idInput ? idInput.value : null;

    if (!currentId || currentId === productId) {
        return false;
    }

    const currentPrefix = `lineItems[${currentId}]`;
    const targetPrefix = `lineItems[${productId}]`;

    form.querySelectorAll('[name^="lineItems["]').forEach((field) => {
        if (field.name.startsWith(currentPrefix)) {
            field.name = targetPrefix + field.name.slice(currentPrefix.length);
        }
    });

    idInput.value = productId;

    const referencedIdInput = form.querySelector(LINE_ITEM_REFERENCED_ID_INPUT_SELECTOR);

    if (referencedIdInput) {
        referencedIdInput.value = productId;
    }

    // Only read by the non-JS fallback (a plain form post redirecting back to the detail page), but
    // it should still land on the variant that was added.
    const redirectParametersInput = form.querySelector(REDIRECT_PARAMETERS_INPUT_SELECTOR);

    if (redirectParametersInput) {
        try {
            const parameters = JSON.parse(redirectParametersInput.value);

            if (parameters && typeof parameters === 'object' && 'productId' in parameters) {
                redirectParametersInput.value = JSON.stringify({ ...parameters, productId });
            }
        } catch (error) {
            // Not ours to repair - leave whatever core rendered.
        }
    }

    return true;
}
