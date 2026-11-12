<?php declare(strict_types=1);

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

namespace PrintessShopwareIntegration\Service\Pricing;

use Shopware\Core\Defaults;

/**
 * Picks one text out of a merchant-entered per-language label map (`{languageId: text}`), as used
 * by custom pricing rules (`PrintessPriceConfig.formFieldPrices[].label`) and the cart display rows
 * (`PrintessCartDisplayFields[].label`): the current language's translation, falling back to
 * Shopware's default/system language, falling back to whichever translation happens to be first
 * (in stored order) rather than showing nothing. A plain string (pre-dating per-language labels) is
 * used as-is regardless of language.
 */
final class PrintessLabelTranslation
{
    public static function resolve(mixed $label, string $languageId): ?string
    {
        if (\is_string($label)) {
            return trim($label) !== '' ? trim($label) : null;
        }

        if (!\is_array($label) || $label === []) {
            return null;
        }

        $current = $label[$languageId] ?? null;

        if (\is_string($current) && trim($current) !== '') {
            return trim($current);
        }

        $default = $label[Defaults::LANGUAGE_SYSTEM] ?? null;

        if (\is_string($default) && trim($default) !== '') {
            return trim($default);
        }

        foreach ($label as $value) {
            if (\is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
