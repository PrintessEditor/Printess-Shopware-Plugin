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
 * The `{{ variable }}` placeholders the shop fills in when production starts, shared by the PDF
 * document properties and the Printess order metadata editors. Mirrors
 * `OrderPlaceholderResolver::VARIABLE_KEYS` on the PHP side (keep both in sync); the option group
 * variables (`product.option.<name>`) are added per group at runtime.
 */
const { Criteria } = Shopware.Data;

export const OPTION_VARIABLE_PREFIX = 'product.option.';

export const VARIABLE_GROUPS = [
    { key: 'order', variables: ['order.number', 'order.id', 'order.date', 'order.customerComment', 'order.salesChannel'] },
    { key: 'lineItem', variables: ['lineItem.id', 'lineItem.position', 'lineItem.quantity'] },
    { key: 'product', variables: ['product.name', 'product.number', 'product.variant', 'product.options'] },
    { key: 'customer', variables: ['customer.name', 'customer.firstName', 'customer.lastName', 'customer.company', 'customer.email', 'customer.number'] },
    { key: 'shipping', variables: ['shipping.name', 'shipping.company', 'shipping.zip', 'shipping.city', 'shipping.country', 'shipping.method'] },
    { key: 'production', variables: ['production.date'] },
];

const KNOWN_VARIABLES = new Set(VARIABLE_GROUPS.flatMap((group) => group.variables));

/** Global flag only for `replace`/`matchAll`, which don't keep state between calls. */
export const PLACEHOLDER_PATTERN = /\{\{\s*([^{}]*?)\s*\}\}/g;

export function token(variable) {
    return `{{ ${variable} }}`;
}

export function today() {
    return new Date().toISOString().slice(0, 10);
}

export function uniqueGroupNames(names) {
    return [...new Set((names ?? []).filter((name) => typeof name === 'string' && name.trim() !== ''))];
}

/** Every property group of the shop - for the plugin settings, which apply to all products. */
export function loadPropertyGroupNames(repositoryFactory) {
    const criteria = new Criteria(1, 500);
    criteria.addSorting(Criteria.sort('name', 'ASC', false));

    return repositoryFactory.create('property_group')
        .search(criteria, Shopware.Context.api)
        .then((result) => result.map((group) => group.translated?.name ?? group.name))
        .catch(() => []);
}

/** The picker's variable groups, with the option groups as their own group after "product". */
export function buildVariableGroups($t, groupNames) {
    const groups = VARIABLE_GROUPS.map((group) => ({
        key: group.key,
        label: $t(`printess-shopware-integration.pdfMetadataConfig.groups.${group.key}`),
        variables: group.variables.map((variable) => ({
            variable,
            token: token(variable),
            label: $t(`printess-shopware-integration.pdfMetadataConfig.variables.${variable.replace('.', '_')}`),
        })),
    }));

    if (groupNames.length) {
        groups.splice(3, 0, {
            key: 'options',
            label: $t('printess-shopware-integration.pdfMetadataConfig.groups.options'),
            variables: groupNames.map((name) => ({
                variable: `${OPTION_VARIABLE_PREFIX}${name}`,
                token: token(`${OPTION_VARIABLE_PREFIX}${name}`),
                label: $t('printess-shopware-integration.pdfMetadataConfig.optionVariableLabel', { name }),
            })),
        });
    }

    return groups;
}

/** Sample values for the preview; `previewProduct` ({name, number}) replaces the sample product. */
export function buildSampleValues($t, groupNames, previewProduct) {
    const productName = previewProduct?.name || $t('printess-shopware-integration.pdfMetadataConfig.sample.productName');
    const optionSamples = groupNames.slice(0, 2).map((name, index) => ({
        group: name,
        option: $t(`printess-shopware-integration.pdfMetadataConfig.sample.option${index + 1}`),
    }));

    return {
        'order.number': '10042',
        'order.id': '0190f3c4a6d27b8e9c1f2a3b4c5d6e7f',
        'order.date': today(),
        'order.customerComment': $t('printess-shopware-integration.pdfMetadataConfig.sample.customerComment'),
        'order.salesChannel': 'Storefront',
        'lineItem.id': '0190f3c4a6d27b8e9c1f2a3b4c5d6e80',
        'lineItem.position': '1',
        'lineItem.quantity': '2',
        'product.name': productName,
        'product.number': previewProduct?.number || 'SW10001',
        'product.variant': optionSamples.map((option) => option.option).join(' / '),
        'product.options': optionSamples.map((option) => `${option.group}: ${option.option}`).join(', '),
        'customer.name': 'Jane Doe',
        'customer.firstName': 'Jane',
        'customer.lastName': 'Doe',
        'customer.company': 'Example Inc.',
        'customer.email': 'jane.doe@example.com',
        'customer.number': 'C-10001',
        'shipping.name': 'Jane Doe',
        'shipping.company': 'Example Inc.',
        'shipping.zip': '48151',
        'shipping.city': 'Münster',
        'shipping.country': $t('printess-shopware-integration.pdfMetadataConfig.sample.country'),
        'shipping.method': 'Standard',
        'production.date': today(),
        options: optionSamples,
        fallbackOption: $t('printess-shopware-integration.pdfMetadataConfig.sample.option1'),
    };
}

/** Mirrors the PHP side: known variables get their (sample) value, everything else becomes empty. */
export function previewText(text, samples) {
    return (text ?? '')
        .replace(PLACEHOLDER_PATTERN, (match, variable) => {
            if (variable.startsWith(OPTION_VARIABLE_PREFIX)) {
                const groupName = variable.slice(OPTION_VARIABLE_PREFIX.length).trim().toLowerCase();
                const sample = samples.options.find((option) => option.group.toLowerCase() === groupName);

                return sample ? sample.option : samples.fallbackOption;
            }

            const value = samples[variable];

            return typeof value === 'string' ? value : '';
        })
        .trim();
}

export function unknownVariables(text) {
    const unknown = new Set();

    for (const match of (text ?? '').matchAll(PLACEHOLDER_PATTERN)) {
        const variable = match[1];

        if (!KNOWN_VARIABLES.has(variable) && !(variable.startsWith(OPTION_VARIABLE_PREFIX) && variable.length > OPTION_VARIABLE_PREFIX.length)) {
            unknown.add(match[0]);
        }
    }

    return [...unknown];
}

export function hasUnclosedPlaceholder(text) {
    return (text ?? '').replace(PLACEHOLDER_PATTERN, '').includes('{{');
}
