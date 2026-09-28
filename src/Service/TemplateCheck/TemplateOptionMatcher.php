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
 * The matching rules the template check and the variant sync share. They mirror what the
 * storefront does when the editor reports a form field change (`printess-design-now.plugin.js`,
 * `_onEditorFormFieldChanged`): a property group matches a form field by the field's name **or**
 * label, an option matches a list entry by the entry's key **or** label, both exactly.
 *
 * Every lookup also has a second, case-insensitive pass. It exists only to *report* near misses -
 * the storefront itself never matches ignoring case.
 */
final class TemplateOptionMatcher
{
    /**
     * The template field a property group corresponds to: by the group's system-language name
     * exactly, then ignoring case, then - only to explain a mismatch - by one of its names in
     * another language.
     *
     * @param list<array{name: string, label: string}> $fields
     * @param list<string>                             $translatedNames the group's names in other languages
     *
     * @return array{field: array<string, mixed>, kind: 'exact'|'case'|'translation', spelled: string|null}|null
     *                                                                                  `spelled` is the template's spelling unless the match is exact
     */
    public static function findField(array $fields, string $groupName, array $translatedNames = []): ?array
    {
        foreach ($fields as $field) {
            if ($field['name'] === $groupName || $field['label'] === $groupName) {
                return ['field' => $field, 'kind' => 'exact', 'spelled' => null];
            }
        }

        $lower = mb_strtolower($groupName);

        foreach ($fields as $field) {
            if (mb_strtolower($field['name']) === $lower) {
                return ['field' => $field, 'kind' => 'case', 'spelled' => $field['name']];
            }

            if (mb_strtolower($field['label']) === $lower) {
                return ['field' => $field, 'kind' => 'case', 'spelled' => $field['label']];
            }
        }

        foreach ($fields as $field) {
            foreach ($translatedNames as $translated) {
                if ($field['name'] === $translated || $field['label'] === $translated) {
                    return ['field' => $field, 'kind' => 'translation', 'spelled' => $translated];
                }
            }
        }

        return null;
    }

    /**
     * Pairs a group's options with a field's list entries, 1:1 and greedily in the product's order:
     * by the option's system-language name, exactly (key or label) then ignoring case, and finally -
     * only to explain the mismatch - by one of its names in another language.
     *
     * @param list<array{name: string, translatedNames?: list<string>}> $options
     * @param list<array{key: string, label: string}>                   $entries
     *
     * @return array{caseOnly: list<array{0: string, 1: string}>, translation: list<array{0: string, 1: string}>, onlyInProduct: list<string>, onlyInTemplate: list<string>}
     */
    public static function compareValues(array $options, array $entries): array
    {
        $used = [];
        $result = ['caseOnly' => [], 'translation' => [], 'onlyInProduct' => [], 'onlyInTemplate' => []];

        foreach ($options as $option) {
            $value = $option['name'];
            $index = self::firstUnusedEntry($entries, $used, static fn (array $entry): bool => $entry['key'] === $value || $entry['label'] === $value);

            if ($index !== null) {
                $used[] = $index;

                continue;
            }

            $lower = mb_strtolower($value);
            $index = self::firstUnusedEntry($entries, $used, static fn (array $entry): bool => mb_strtolower($entry['key']) === $lower || mb_strtolower($entry['label']) === $lower);

            if ($index !== null) {
                $used[] = $index;
                $entry = $entries[$index];
                $result['caseOnly'][] = [$value, mb_strtolower($entry['label']) === $lower ? $entry['label'] : $entry['key']];

                continue;
            }

            $translated = $option['translatedNames'] ?? [];
            $index = self::firstUnusedEntry($entries, $used, static fn (array $entry): bool => \in_array($entry['key'], $translated, true) || \in_array($entry['label'], $translated, true));

            if ($index !== null) {
                $used[] = $index;
                $entry = $entries[$index];
                $result['translation'][] = [$value, \in_array($entry['label'], $translated, true) ? $entry['label'] : $entry['key']];

                continue;
            }

            $result['onlyInProduct'][] = $value;
        }

        foreach ($entries as $index => $entry) {
            if (!\in_array($index, $used, true)) {
                $result['onlyInTemplate'][] = $entry['label'] !== '' ? $entry['label'] : $entry['key'];
            }
        }

        return $result;
    }

    /**
     * The first not yet used item whose name equals one of the candidate spellings - exactly first,
     * then ignoring case. Used by the variant sync to match a field to a property group (candidates:
     * label, name) and a list entry to an option (candidates: label, key).
     *
     * @param list<array{name: string}> $items
     * @param list<int>                 $used
     * @param list<string>              $candidates in order of preference
     *
     * @return array{index: int, spelled: string|null}|null `spelled` is the matching candidate when only case matched
     */
    public static function matchByName(array $items, array $used, array $candidates): ?array
    {
        foreach ($items as $index => $item) {
            if (!\in_array($index, $used, true) && \in_array($item['name'], $candidates, true)) {
                return ['index' => $index, 'spelled' => null];
            }
        }

        foreach ($items as $index => $item) {
            if (\in_array($index, $used, true)) {
                continue;
            }

            $lower = mb_strtolower($item['name']);

            foreach ($candidates as $candidate) {
                if (mb_strtolower($candidate) === $lower) {
                    return ['index' => $index, 'spelled' => $candidate];
                }
            }
        }

        return null;
    }

    /**
     * Like `matchByName()`, but against the items' names in other languages than the system
     * language (`translatedNames`): exactly, then ignoring case. Used by the variant sync to reuse
     * a shared group or option the template spells like one of its translations, rather than to
     * create a duplicate of it.
     *
     * @param list<array{translatedNames?: list<string>}> $items
     * @param list<int>                                   $used
     * @param list<string>                                $candidates
     *
     * @return array{index: int, spelled: string}|null `spelled` is the matching candidate
     */
    public static function matchByTranslation(array $items, array $used, array $candidates): ?array
    {
        foreach ($items as $index => $item) {
            if (\in_array($index, $used, true)) {
                continue;
            }

            foreach ($candidates as $candidate) {
                if (\in_array($candidate, $item['translatedNames'] ?? [], true)) {
                    return ['index' => $index, 'spelled' => $candidate];
                }
            }
        }

        foreach ($items as $index => $item) {
            if (\in_array($index, $used, true)) {
                continue;
            }

            $lowered = array_map('mb_strtolower', $item['translatedNames'] ?? []);

            foreach ($candidates as $candidate) {
                if (\in_array(mb_strtolower($candidate), $lowered, true)) {
                    return ['index' => $index, 'spelled' => $candidate];
                }
            }
        }

        return null;
    }

    /**
     * @param list<array{key: string, label: string}> $entries
     * @param list<int>                               $used
     */
    private static function firstUnusedEntry(array $entries, array $used, callable $test): ?int
    {
        foreach ($entries as $index => $entry) {
            if (!\in_array($index, $used, true) && $test($entry)) {
                return $index;
            }
        }

        return null;
    }
}
