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

namespace PrintessShopwareIntegration\Service\TemplateCheck;

/**
 * Tells the merchant, per variant property group, whether the template will follow it.
 *
 * Variant sync on the storefront needs the property group and option names to match the template's
 * form fields exactly, and nothing fails loudly when they don't: the editor opens on the wrong
 * option, or never switches the variant. It also only reports changes to form fields marked
 * **Price Relevant** - a Size field without it opens on the right size and then silently never
 * switches. This check is the one place that can say so before a shopper finds out.
 *
 * Returns issue codes with their data rather than texts, so the admin renders them in its own
 * language.
 */
class TemplateCheckService
{
    /**
     * @param list<array<string, mixed>>                                                                  $fields as returned by `PrintessFormFieldsClient::load()`
     * @param list<array{id: string, name: string, translatedNames?: list<string>, options: list<array{id: string, name: string, translatedNames?: list<string>}>}> $groups the product's variant property groups
     *
     * @return list<array{groupId: string, groupName: string, headline: array<string, mixed>, details: list<array<string, mixed>>}>
     */
    public function check(array $fields, array $groups): array
    {
        $result = [];

        foreach ($groups as $group) {
            $result[] = [
                'groupId' => $group['id'],
                'groupName' => $group['name'],
                ...$this->checkGroup($fields, $group),
            ];
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>>                                                            $fields
     * @param array{id: string, name: string, options: list<array{id: string, name: string}>}       $group
     *
     * @return array{headline: array<string, mixed>, details: list<array<string, mixed>>}
     */
    private function checkGroup(array $fields, array $group): array
    {
        $found = TemplateOptionMatcher::findField($fields, $group['name'], $group['translatedNames'] ?? []);

        if ($found === null) {
            return [
                'headline' => ['code' => 'notInTemplate', 'tone' => 'warning', 'option' => $group['name']],
                'details' => [],
            ];
        }

        $field = $found['field'];
        $entries = $field['entries'];
        $values = array_column($group['options'], 'name');
        $details = [];
        $valuesDiffer = false;

        if ($entries !== []) {
            $comparison = TemplateOptionMatcher::compareValues($group['options'], $entries);

            if ($comparison['caseOnly'] !== []) {
                $details[] = ['code' => 'valueCase', 'pairs' => $comparison['caseOnly']];
            }

            if ($comparison['translation'] !== []) {
                $details[] = ['code' => 'valueTranslation', 'pairs' => $comparison['translation']];
            }

            if (\count($values) !== \count($entries)) {
                $details[] = ['code' => 'countDiffers', 'option' => $group['name'], 'productCount' => \count($values), 'templateCount' => \count($entries)];
            }

            if ($comparison['onlyInProduct'] !== []) {
                $details[] = ['code' => 'onlyInProduct', 'values' => $comparison['onlyInProduct']];
            }

            if ($comparison['onlyInTemplate'] !== []) {
                $details[] = ['code' => 'onlyInTemplate', 'values' => $comparison['onlyInTemplate']];
            }

            $valuesDiffer = $comparison['caseOnly'] !== [] || $comparison['translation'] !== [] || $comparison['onlyInProduct'] !== [] || $comparison['onlyInTemplate'] !== [];
        } elseif (!$field['isList']) {
            $details[] = ['code' => 'freeField', 'option' => $group['name']];
        }
        // else: a colour field. A list in the editor whose colours Printess does not enumerate - it
        // reads and writes values like any other field, so there is nothing to compare or warn about.

        if ($found['kind'] !== 'exact') {
            // The name is the more fundamental problem; the Price Relevant flag becomes a detail.
            if (!$field['isPriceRelevant']) {
                array_unshift($details, ['code' => 'notPriceRelevant', 'field' => $found['spelled']]);
            }

            return [
                'headline' => [
                    // `nameTranslation`: the template follows one of the group's names in another
                    // language, but the storefront compares the system-language name.
                    'code' => $found['kind'] === 'case' ? 'nameCase' : 'nameTranslation',
                    'tone' => 'warning',
                    'option' => $group['name'],
                    'spelled' => $found['spelled'],
                ],
                'details' => $details,
            ];
        }

        if (!$field['isPriceRelevant']) {
            return [
                'headline' => ['code' => 'notPriceRelevant', 'tone' => 'warning', 'field' => $group['name']],
                'details' => $details,
            ];
        }

        if ($valuesDiffer) {
            return [
                'headline' => ['code' => 'valuesDiffer', 'tone' => 'warning', 'option' => $group['name']],
                'details' => $details,
            ];
        }

        return [
            'headline' => ['code' => 'synced', 'tone' => 'success', 'option' => $group['name']],
            'details' => $details,
        ];
    }
}
