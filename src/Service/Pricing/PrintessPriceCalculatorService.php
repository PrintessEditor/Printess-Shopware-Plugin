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
 * Computes the per-unit Printess pricing surcharge (page-count-based and/or form-field-combination
 * based, e.g. for photo books) from a product's `PrintessPriceConfig` custom field. Used both by the
 * storefront price-preview endpoint (`PrintessPriceController`) and the cart price processor
 * (`PrintessSurchargeCartProcessor`) - a single implementation so the price shown in the editor and
 * the price actually charged at checkout can never drift apart.
 *
 * Expected `$priceConfig` shape (matches the shop-integration-wide `IPrintessPriceConfig` TypeScript
 * definition):
 * ```
 * {
 *     "includedPageCount": number|null,
 *     "pricePerPage": number|null,
 *     "formFieldPrices": [
 *         {
 *             "formFields": Condition | [Condition, ...],
 *             "label": string|{[languageId: string]: string}|null,
 *             "price": number|null,
 *             "pricePerPage": number|null
 *         },
 *         ...
 *     ]
 * }
 * ```
 * where a single condition is:
 * ```
 * {
 *     "name": string,
 *     "value": string|number,
 *     "compareBy": "value"|"length",
 *     "operator": "equals"|"notEquals"|"greaterThan"|"greaterThanOrEqual"|"lessThan"|"lessThanOrEqual"
 * }
 * ```
 * `compareBy`/`operator` default to `"value"`/`"equals"` when absent - the only shape a condition
 * could have before these existed, so configs saved before this feature keep matching exactly as
 * they did. `compareBy: "length"` compares the form field value's character count instead of the
 * value itself, e.g. `{"name": "Notes", "compareBy": "length", "operator": "greaterThan", "value": 10}`
 * matches whenever the "Notes" form field's value is longer than 10 characters. The ordering
 * operators only make sense for a length comparison - see `conditionMatches()`.
 *
 * `label` is either a plain string (shown regardless of language - kept working for configs saved
 * before per-language display names existed) or an object keyed by Shopware language id, one entry
 * per shop language the admin has translated - see `resolveRuleLabel()` for how a single string is
 * picked out of that per-language shape for a given render.
 */
class PrintessPriceCalculatorService
{
    /**
     * @param array<string, mixed>|null $priceConfig decoded `PrintessPriceConfig` JSON, or null/[] if unconfigured
     * @param array<string, string> $priceRelevantFormFields name => value, as reported by the editor's
     *                                                        `priceChangeCallback`
     * @param string $languageId the storefront's current language id, used to resolve a matching
     *                            rule's translated display name (see `resolveRuleLabel()`)
     *
     * @return float the per-unit surcharge to add on top of the product's own price; 0.0 if nothing applies
     */
    public function calculateSurcharge(?array $priceConfig, int $pageCount, array $priceRelevantFormFields, string $languageId): float
    {
        return $this->calculateBreakdown($priceConfig, $pageCount, $priceRelevantFormFields, $languageId)['total'];
    }

    /**
     * Same calculation as {@see calculateSurcharge()}, but itemized so the storefront can explain to
     * the customer why the price differs from the product's base price - see
     * `PrintessSurchargeCartProcessor`, which stores this on the cart line item's payload.
     *
     * @param array<string, mixed>|null $priceConfig decoded `PrintessPriceConfig` JSON, or null/[] if unconfigured
     * @param array<string, string> $priceRelevantFormFields name => value, as reported by the editor's
     *                                                        `priceChangeCallback`
     * @param string $languageId the storefront's current language id, used to resolve a matching
     *                            rule's translated display name (see `resolveRuleLabel()`)
     *
     * @return array{
     *     billablePageCount: int,
     *     pageSurcharge: float,
     *     ruleSurcharges: list<array{label: string, price: float}>,
     *     total: float,
     * }
     */
    public function calculateBreakdown(?array $priceConfig, int $pageCount, array $priceRelevantFormFields, string $languageId): array
    {
        if ($priceConfig === null || $priceConfig === []) {
            return ['billablePageCount' => 0, 'pageSurcharge' => 0.0, 'ruleSurcharges' => [], 'total' => 0.0];
        }

        $billablePages = $this->getBillablePageCount($priceConfig, $pageCount);
        $pageSurcharge = $billablePages * $this->toFloat($priceConfig['pricePerPage'] ?? null);
        $total = $pageSurcharge;
        $ruleSurcharges = [];

        foreach ($this->normalizeFormFieldPrices($priceConfig['formFieldPrices'] ?? null) as $rule) {
            $conditions = $this->normalizeConditions($rule['formFields'] ?? null);

            if (!$this->ruleMatches($rule, $priceRelevantFormFields)) {
                continue;
            }

            $rulePrice = $this->toFloat($rule['price'] ?? null) + $billablePages * $this->toFloat($rule['pricePerPage'] ?? null);
            $total += $rulePrice;

            if ($rulePrice === 0.0) {
                continue;
            }

            $ruleSurcharges[] = [
                'label' => $this->resolveRuleLabel($rule, $conditions, $languageId),
                'price' => $rulePrice,
            ];
        }

        return [
            'billablePageCount' => $billablePages,
            'pageSurcharge' => $pageSurcharge,
            'ruleSurcharges' => $ruleSurcharges,
            'total' => $total,
        ];
    }

    /**
     * @param array<string, mixed> $rule
     * @param list<array{name?: mixed, value?: mixed}> $conditions
     */
    private function resolveRuleLabel(array $rule, array $conditions, string $languageId): string
    {
        $translated = $this->resolveLabelTranslation($rule['label'] ?? null, $languageId);

        if ($translated !== null) {
            return $translated;
        }

        $parts = [];

        foreach ($conditions as $condition) {
            if (\is_string($condition['name'] ?? null) && $condition['name'] !== '') {
                $parts[] = $this->describeCondition($condition);
            }
        }

        return implode(', ', $parts);
    }

    /**
     * @param array<string, mixed> $condition
     */
    private function describeCondition(array $condition): string
    {
        $name = (string) $condition['name'];
        $value = (string) ($condition['value'] ?? '');
        $operator = \is_string($condition['operator'] ?? null) ? $condition['operator'] : 'equals';

        if (($condition['compareBy'] ?? 'value') === 'length') {
            return $name . ' length ' . $this->describeOperatorSymbol($operator) . ' ' . $value;
        }

        // Unchanged from before this feature existed, for the common equals-on-value case.
        return $operator === 'notEquals' ? $name . ' ≠ ' . $value : $name . ': ' . $value;
    }

    private function describeOperatorSymbol(string $operator): string
    {
        return match ($operator) {
            'notEquals' => '≠',
            'greaterThan' => '>',
            'greaterThanOrEqual' => '≥',
            'lessThan' => '<',
            'lessThanOrEqual' => '≤',
            default => '=',
        };
    }

    /**
     * Picks one label out of a rule's `label` config: the current language's translation, falling
     * back to Shopware's default/system language, falling back to whichever translation happens to
     * be first (in stored order) rather than showing nothing. A plain string (pre-dating per-language
     * display names) is used as-is regardless of language, matching its original behavior exactly.
     */
    private function resolveLabelTranslation(mixed $label, string $languageId): ?string
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

    /**
     * @param array<string, mixed> $priceConfig
     */
    private function getBillablePageCount(array $priceConfig, int $pageCount): int
    {
        if ($pageCount <= 0) {
            return 0;
        }

        $includedPageCount = $priceConfig['includedPageCount'] ?? null;

        if (!\is_int($includedPageCount) && !\is_float($includedPageCount)) {
            $includedPageCount = 0;
        }

        return max(0, $pageCount - (int) $includedPageCount);
    }

    /**
     * Normalizes the `formFields` condition of a single price rule - the TypeScript definition
     * allows either one `{name, value}` object or a list of them (all of which must match, i.e. an
     * AND combination) - into a flat list either way.
     *
     * @param mixed $formFieldPrices
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeFormFieldPrices(mixed $formFieldPrices): array
    {
        if (!\is_array($formFieldPrices)) {
            return [];
        }

        $rules = [];

        foreach ($formFieldPrices as $rule) {
            if (\is_array($rule)) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<string, string> $priceRelevantFormFields
     */
    private function ruleMatches(array $rule, array $priceRelevantFormFields): bool
    {
        $conditions = $this->normalizeConditions($rule['formFields'] ?? null);

        if ($conditions === []) {
            return false;
        }

        foreach ($conditions as $condition) {
            if (!$this->conditionMatches($condition, $priceRelevantFormFields)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $condition
     * @param array<string, string> $priceRelevantFormFields
     */
    private function conditionMatches(array $condition, array $priceRelevantFormFields): bool
    {
        $name = $condition['name'] ?? null;

        if (!\is_string($name) || $name === '' || !\array_key_exists($name, $priceRelevantFormFields)) {
            return false;
        }

        $rawValue = (string) $priceRelevantFormFields[$name];
        $operator = \is_string($condition['operator'] ?? null) ? $condition['operator'] : 'equals';

        if (($condition['compareBy'] ?? 'value') === 'length') {
            return $this->compareNumeric((float) mb_strlen($rawValue), $this->toFloat($condition['value'] ?? null), $operator);
        }

        return $this->compareStrings($rawValue, (string) ($condition['value'] ?? ''), $operator);
    }

    /**
     * A text value only ever supports an equals/not-equals check - "greater than" etc. on arbitrary
     * text isn't offered in the admin UI, so any such operator here would only come from a
     * hand-edited config; treated as "equals" rather than matching nothing.
     */
    private function compareStrings(string $actual, string $expected, string $operator): bool
    {
        return $operator === 'notEquals' ? $actual !== $expected : $actual === $expected;
    }

    private function compareNumeric(float $actual, float $expected, string $operator): bool
    {
        return match ($operator) {
            'notEquals' => $actual !== $expected,
            'greaterThan' => $actual > $expected,
            'greaterThanOrEqual' => $actual >= $expected,
            'lessThan' => $actual < $expected,
            'lessThanOrEqual' => $actual <= $expected,
            default => $actual === $expected,
        };
    }

    /**
     * @param mixed $formFields
     *
     * @return list<array{name?: mixed, value?: mixed}>
     */
    private function normalizeConditions(mixed $formFields): array
    {
        if (!\is_array($formFields)) {
            return [];
        }

        // A single {name, value} object (associative array) vs. a list of them.
        if (\array_key_exists('name', $formFields)) {
            return [$formFields];
        }

        $conditions = [];

        foreach ($formFields as $condition) {
            if (\is_array($condition)) {
                $conditions[] = $condition;
            }
        }

        return $conditions;
    }

    private function toFloat(mixed $value): float
    {
        return \is_int($value) || \is_float($value) ? (float) $value : 0.0;
    }
}
