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
 * Plans how to make a product's variant properties and variants match the template's list form
 * fields. Pure: it reads nothing and writes nothing, so the preview and the apply run the very same
 * function on the same choices - the browser never sends changes, only the merchant's choices, and
 * what is applied is exactly what was shown.
 *
 * - **Property groups:** a chosen field becomes a variant property group of the product, matched
 *   to one it already has by the field's label or name (the storefront's rule). Without one, an
 *   existing shared group of that name is reused, and only failing that is a new group created.
 *   Groups no chosen field matches are removed from the product unless the merchant keeps them.
 * - **Options:** the field's list entries, in the template's order. An entry reuses the product's
 *   option, then another option of the same group, and only failing that creates one. Options the
 *   template lacks are removed from the product unless kept.
 * - **Variants:** existing variants are updated in place wherever they still fit - their prices,
 *   stock and numbers are the merchant's work. A variant pointing at a removed option is deleted
 *   unless kept (which keeps that option too); variants collapsing onto the same combination
 *   because a group was removed are merged into the oldest. Missing combinations are created,
 *   copying the price of the variant closest to them, a limited number per run.
 *
 * Property groups and options are shared by every product in Shopware, so they are never renamed
 * or deleted here: a spelling that differs from the template is reported instead, and "removing"
 * only takes an option out of *this* product's configurator.
 */
class VariantSyncPlanner
{
    public const DEFAULT_LIMIT = 50;

    private const STATUS_KEEP = 'keep';
    private const STATUS_KEPT = 'kept';
    private const STATUS_ADD = 'add';
    private const STATUS_CREATE = 'create';

    /**
     * @param list<array<string, mixed>> $fields       template form fields, as from `PrintessFormFieldsClient::load()`
     * @param array<string, mixed>       $state        as from `ProductVariantStateReader::read()`
     * @param list<array<string, mixed>> $sharedGroups as from `ProductVariantStateReader::findSharedGroups()`
     * @param list<string>               $selected     names of the fields to build property groups from
     * @param array<string, mixed>       $keep         `{groups: string[], options: string[], variants: string[]}` - ids not to remove
     *
     * @return array{public: array<string, mixed>, internal: array<string, mixed>}
     */
    public function plan(array $fields, array $state, array $sharedGroups, array $selected, array $keep, int $limit = self::DEFAULT_LIMIT): array
    {
        $choices = $this->choices($fields);
        $groups = $state['groups'];
        $variants = $this->sortOldestFirst($state['variants']);
        $names = $this->optionNames($groups, $sharedGroups);
        $keep = $this->expandKeep($this->normalizeKeep($keep), $variants, $groups);
        $selected = array_values(array_intersect(array_column($choices, 'name'), array_map('strval', $selected)));

        $productGroupIds = array_column($groups, 'id');
        $sharedGroups = array_values(array_filter($sharedGroups, static fn (array $group): bool => !\in_array($group['id'], $productGroupIds, true)));

        $usedGroups = [];
        $usedShared = [];
        $targets = [];
        $notes = [];

        // 1. Every chosen field becomes a variant property group.
        foreach ($choices as $choice) {
            if (!\in_array($choice['name'], $selected, true)) {
                continue;
            }

            $candidates = [$choice['label'], $choice['name']];
            // The product's own group first - its variants use it - then a shared one; each by its
            // system-language name, then by a name in another language.
            [$match, $translated] = $this->matchItem($groups, $usedGroups, $candidates);
            $existing = $match !== null ? $groups[$match['index']] : null;
            $shared = null;

            // Values Printess does not list, and no group of the product's own to take them from:
            // there is nothing to build.
            if ($choice['open'] && $existing === null) {
                $notes[] = ['type' => 'openNew', 'group' => $choice['label']];

                continue;
            }

            if ($existing === null) {
                [$sharedMatch, $sharedTranslated] = $this->matchItem($sharedGroups, $usedShared, $candidates);

                if ($sharedMatch !== null) {
                    $usedShared[] = $sharedMatch['index'];
                    $shared = $sharedGroups[$sharedMatch['index']];
                    $match = $sharedMatch;
                    $translated = $sharedTranslated;
                }
            } else {
                $usedGroups[] = $match['index'];
            }

            $group = $existing ?? $shared;

            if ($group !== null && $match['spelled'] !== null) {
                $this->noteSpelling($notes, $group['name'], null, $match['spelled'], $translated);
            }

            $position = \count($targets);
            $target = [
                'groupId' => $group['id'] ?? null,
                'groupRef' => $group['id'] ?? 'new-group:' . $position,
                'onProduct' => $existing !== null,
                'name' => $group['name'] ?? $choice['label'],
                'field' => $choice['name'],
                'values' => [],
                'removed' => [],
            ];

            $productOptions = $existing['options'] ?? [];
            $productOptionIds = array_column($productOptions, 'id');
            $otherOptions = array_values(array_filter($group['allOptions'] ?? [], static fn (array $option): bool => !\in_array($option['id'], $productOptionIds, true)));
            $takenOwn = [];
            $takenOther = [];

            if ($choice['open']) {
                // A colour field: the product's own values, kept exactly as they are.
                foreach ($productOptions as $index => $option) {
                    $takenOwn[] = $index;
                    $target['values'][] = $this->value($option['id'], $option['name'], self::STATUS_KEEP);
                }
            }

            foreach ($choice['entries'] as $entryIndex => $entry) {
                $entryCandidates = [$entry['label'], $entry['key']];
                // The product's own option first, then another option of the group - never a
                // duplicate of an option the shop already has under another language's name.
                [$own, $ownTranslated] = $this->matchItem($productOptions, $takenOwn, $entryCandidates);

                if ($own !== null) {
                    $takenOwn[] = $own['index'];
                    $option = $productOptions[$own['index']];
                    $target['values'][] = $this->value($option['id'], $option['name'], self::STATUS_KEEP);
                    $this->noteSpelling($notes, $target['name'], $option['name'], $own['spelled'], $ownTranslated);

                    continue;
                }

                [$other, $otherTranslated] = $this->matchItem($otherOptions, $takenOther, $entryCandidates);

                if ($other !== null) {
                    $takenOther[] = $other['index'];
                    $option = $otherOptions[$other['index']];
                    $target['values'][] = $this->value($option['id'], $option['name'], self::STATUS_ADD);
                    $this->noteSpelling($notes, $target['name'], $option['name'], $other['spelled'], $otherTranslated);

                    continue;
                }

                $value = $this->value(null, $entry['label'], self::STATUS_CREATE);
                $value['ref'] = 'new-option:' . $position . ':' . $entryIndex;
                $value['position'] = $entryIndex + 1;
                $target['values'][] = $value;
            }

            // The product's options the template does not have: removed, unless kept.
            foreach ($productOptions as $index => $option) {
                if (\in_array($index, $takenOwn, true)) {
                    continue;
                }

                if (\in_array($option['id'], $keep['options'], true)) {
                    $target['values'][] = $this->value($option['id'], $option['name'], self::STATUS_KEPT);
                } else {
                    $target['removed'][] = ['id' => $option['id'], 'name' => $option['name']];
                }
            }

            $targets[] = $target;
        }

        // 2. Property groups of the product no chosen field matches: removed, unless kept.
        $removedGroups = [];
        $keptGroups = [];

        foreach ($groups as $index => $group) {
            if (\in_array($index, $usedGroups, true)) {
                continue;
            }

            if (\in_array($group['id'], $keep['groups'], true)) {
                $keptGroups[] = ['id' => $group['id'], 'name' => $group['name']];
                $targets[] = [
                    'groupId' => $group['id'],
                    'groupRef' => $group['id'],
                    'onProduct' => true,
                    'name' => $group['name'],
                    'field' => null,
                    'values' => array_map(fn (array $option): array => $this->value($option['id'], $option['name'], self::STATUS_KEEP), $group['options']),
                    'removed' => [],
                ];
            } else {
                $removedGroups[] = ['id' => $group['id'], 'name' => $group['name'], 'values' => array_column($group['options'], 'name')];
            }
        }

        // 3. Variants: map each onto the new combinations.
        $productOptionIds = [];

        foreach ($groups as $group) {
            array_push($productOptionIds, ...array_column($group['options'], 'id'));
        }

        $mapped = [];
        $orphans = [];

        foreach ($variants as $variant) {
            $combination = $this->combinationOf($variant, $targets);

            if ($combination === null) {
                $orphans[] = $variant;

                continue;
            }

            $mapped[] = ['variant' => $variant, 'combination' => $combination];
        }

        // Variants are sorted oldest first, so the oldest keeps each combination.
        $survivors = [];
        $merged = [];

        foreach ($mapped as $entry) {
            $signature = implode('|', $entry['combination']);

            if (isset($survivors[$signature])) {
                $merged[] = [
                    'id' => $entry['variant']['id'],
                    'label' => $this->variantLabel($entry['variant'], $groups, $names),
                    'mergedInto' => $this->variantLabel($survivors[$signature]['variant'], $groups, $names),
                ];

                continue;
            }

            $survivors[$signature] = $entry;
        }

        // Every option the plan knows: the product's, and every option of a target group. A
        // variant's options outside of these (of a group unrelated to the product) are left alone.
        $knownOptionIds = $productOptionIds;

        foreach ($targets as $target) {
            array_push($knownOptionIds, ...array_filter(array_column($target['values'], 'optionId')), ...array_column($target['removed'], 'id'));
        }

        $update = [];

        foreach ($survivors as $entry) {
            $current = array_values(array_intersect($entry['variant']['optionIds'], $knownOptionIds));
            $add = array_values(array_diff($entry['combination'], $current));
            $remove = array_values(array_diff($current, $entry['combination']));

            if ($add !== [] || $remove !== []) {
                $update[] = ['id' => $entry['variant']['id'], 'add' => $add, 'remove' => $remove];
            }
        }

        // Missing combinations, each copying the price of the variant closest to it.
        $create = [];
        $missing = 0;

        foreach ($this->combinations($targets) as $combination) {
            if (isset($survivors[implode('|', $combination)])) {
                continue;
            }

            ++$missing;

            if (\count($create) >= $limit) {
                continue;
            }

            $create[] = ['refs' => $combination, 'copyFrom' => $this->closest($combination, $survivors)];
        }

        // 4. Configurator settings: exactly the options the targets use.
        $desiredRefs = [];

        foreach ($targets as $target) {
            array_push($desiredRefs, ...array_column($target['values'], 'ref'));
        }

        $newGroups = [];
        $newOptions = [];

        foreach ($targets as $position => $target) {
            if ($target['groupId'] === null) {
                $newGroups[$target['groupRef']] = ['name' => $target['name']];
            }

            foreach ($target['values'] as $value) {
                if ($value['status'] === self::STATUS_CREATE) {
                    $newOptions[$value['ref']] = ['groupRef' => $target['groupRef'], 'name' => $value['name'], 'position' => $value['position']];
                }
            }
        }

        $public = $this->publicPlan($choices, $selected, $groups, $sharedGroups, $targets, $removedGroups, $keptGroups, $variants, $keep, $orphans, $merged, $update, $create, $missing, $limit, $notes, $names, $state);

        return [
            'public' => $public,
            'internal' => [
                'productId' => $state['productId'],
                'productNumber' => $state['productNumber'],
                'newGroups' => $newGroups,
                'newOptions' => $newOptions,
                // The full set, not a diff: the applier compares it with what is stored at the very
                // end, after the variant writes (see `VariantSyncApplier`).
                'configuratorOptions' => array_values(array_unique($desiredRefs)),
                'delete' => array_merge(array_column($orphans, 'id'), array_column($merged, 'id')),
                'update' => $update,
                'create' => array_map(function (array $pending) use ($survivors): array {
                    $source = $pending['copyFrom'] !== null ? $this->findSurvivor($survivors, $pending['copyFrom']) : null;

                    return ['refs' => $pending['refs'], 'price' => $source['price'] ?? null];
                }, $create),
            ],
        ];
    }

    /**
     * The template fields that can become property groups: list fields with entries, and colour
     * fields (a list whose values Printess does not report - their values are the product's own).
     *
     * @param list<array<string, mixed>> $fields
     *
     * @return list<array{name: string, label: string, entries: list<array{key: string, label: string}>, open: bool, isPriceRelevant: bool}>
     */
    private function choices(array $fields): array
    {
        $choices = [];

        foreach ($fields as $field) {
            $entries = [];

            foreach ($field['entries'] ?? [] as $entry) {
                $key = trim((string) ($entry['key'] ?? ''));
                $label = trim((string) ($entry['label'] ?? ''));

                if ($key === '' && $label === '') {
                    continue;
                }

                $entries[] = ['key' => $key !== '' ? $key : $label, 'label' => $label !== '' ? $label : $key];
            }

            $name = trim((string) ($field['name'] ?? ''));
            $open = $entries === [] && !empty($field['isList']);

            if ($name === '' || ($entries === [] && !$open)) {
                continue;
            }

            $label = trim((string) ($field['label'] ?? ''));
            $choices[] = [
                'name' => $name,
                'label' => $label !== '' ? $label : $name,
                'entries' => $entries,
                'open' => $open,
                'isPriceRelevant' => !empty($field['isPriceRelevant']),
            ];
        }

        return $choices;
    }

    /**
     * The combination an existing variant ends up with, as one option ref per target group, or null
     * when it points at an option that is being removed.
     *
     * @param array<string, mixed>       $variant
     * @param list<array<string, mixed>> $targets
     *
     * @return list<string>|null
     */
    private function combinationOf(array $variant, array $targets): ?array
    {
        $combination = [];

        foreach ($targets as $target) {
            $current = null;

            // Looked up for every group, including one that is not (yet) in the product's
            // configurator: a variant may already carry one of its options.
            foreach ($target['values'] as $value) {
                if (\in_array($value['ref'], $variant['optionIds'], true)) {
                    $current = $value;

                    break;
                }
            }

            if ($current === null) {
                foreach ($target['removed'] as $removed) {
                    if (\in_array($removed['id'], $variant['optionIds'], true)) {
                        return null;
                    }
                }
            }

            // A group new to the variant gives it the group's first value.
            $combination[] = ($current ?? $target['values'][0])['ref'];
        }

        return $combination;
    }

    /**
     * Every combination of the target groups' values, as option refs.
     *
     * @param list<array<string, mixed>> $targets
     *
     * @return list<list<string>>
     */
    private function combinations(array $targets): array
    {
        if ($targets === []) {
            return [];
        }

        $combinations = [[]];

        foreach ($targets as $target) {
            $next = [];

            foreach ($combinations as $combination) {
                foreach ($target['values'] as $value) {
                    $next[] = [...$combination, $value['ref']];
                }
            }

            $combinations = $next;
        }

        return $combinations;
    }

    /**
     * The surviving variant sharing the most options with a new combination. Survivors are ordered
     * oldest first, so a tie goes to the oldest.
     *
     * @param list<string>                     $combination
     * @param array<string, array<string, mixed>> $survivors
     */
    private function closest(array $combination, array $survivors): ?string
    {
        $best = null;
        $score = -1;

        foreach ($survivors as $entry) {
            $shared = \count(array_intersect_assoc($combination, $entry['combination']));

            if ($shared > $score) {
                $score = $shared;
                $best = $entry['variant']['id'];
            }
        }

        return $best;
    }

    /**
     * @param array<string, array<string, mixed>> $survivors
     *
     * @return array<string, mixed>|null
     */
    private function findSurvivor(array $survivors, string $variantId): ?array
    {
        foreach ($survivors as $entry) {
            if ($entry['variant']['id'] === $variantId) {
                return $entry['variant'];
            }
        }

        return null;
    }

    /**
     * Keeping a variant keeps what it is made of: its options and their groups. Otherwise a kept
     * variant would point at an option the product no longer offers, and the storefront could
     * never select it.
     *
     * @param array{groups: list<string>, options: list<string>, variants: list<string>} $keep
     * @param list<array<string, mixed>>                                                 $variants
     * @param list<array<string, mixed>>                                                 $groups
     *
     * @return array{groups: list<string>, options: list<string>, variants: list<string>}
     */
    private function expandKeep(array $keep, array $variants, array $groups): array
    {
        foreach ($variants as $variant) {
            if (!\in_array($variant['id'], $keep['variants'], true)) {
                continue;
            }

            foreach ($groups as $group) {
                foreach ($group['options'] as $option) {
                    if (\in_array($option['id'], $variant['optionIds'], true)) {
                        $keep['options'][] = $option['id'];
                        $keep['groups'][] = $group['id'];
                    }
                }
            }
        }

        $keep['options'] = array_values(array_unique($keep['options']));
        $keep['groups'] = array_values(array_unique($keep['groups']));

        return $keep;
    }

    /**
     * @param array<string, mixed> $keep
     *
     * @return array{groups: list<string>, options: list<string>, variants: list<string>}
     */
    private function normalizeKeep(array $keep): array
    {
        $ids = static fn (mixed $list): array => array_values(array_filter(
            array_map(static fn (mixed $id): string => \is_scalar($id) ? (string) $id : '', \is_array($list) ? $list : []),
            static fn (string $id): bool => $id !== '',
        ));

        return [
            'groups' => $ids($keep['groups'] ?? []),
            'options' => $ids($keep['options'] ?? []),
            'variants' => $ids($keep['variants'] ?? []),
        ];
    }

    /**
     * @param list<array<string, mixed>> $variants
     *
     * @return list<array<string, mixed>>
     */
    private function sortOldestFirst(array $variants): array
    {
        usort($variants, static fn (array $a, array $b): int => [$a['createdAt'], $a['id']] <=> [$b['createdAt'], $b['id']]);

        return $variants;
    }

    /**
     * @return array{ref: string, optionId: string|null, name: string, status: string}
     */
    private function value(?string $optionId, string $name, string $status): array
    {
        return ['ref' => (string) $optionId, 'optionId' => $optionId, 'name' => $name, 'status' => $status];
    }

    /**
     * By system-language name (exactly, then ignoring case), then by a name in another language.
     *
     * @param list<array<string, mixed>> $items
     * @param list<int>                  $used
     * @param list<string>               $candidates
     *
     * @return array{0: array{index: int, spelled: string|null}|null, 1: bool} the match, and whether it was by translation
     */
    private function matchItem(array $items, array $used, array $candidates): array
    {
        $match = TemplateOptionMatcher::matchByName($items, $used, $candidates);

        if ($match !== null) {
            return [$match, false];
        }

        $match = TemplateOptionMatcher::matchByTranslation($items, $used, $candidates);

        return [$match, $match !== null];
    }

    /**
     * A shared group or option that is reused although its system-language name differs from the
     * template: only in case (`sharedSpelling`), or because the template follows one of its names
     * in another language (`sharedTranslation`). Either way the storefront, which compares the
     * system-language name, will not follow it until the merchant renames it.
     *
     * @param list<array<string, mixed>> $notes
     */
    private function noteSpelling(array &$notes, string $group, ?string $value, ?string $spelled, bool $translated): void
    {
        if ($spelled !== null) {
            $notes[] = ['type' => $translated ? 'sharedTranslation' : 'sharedSpelling', 'group' => $group, 'value' => $value, 'templateSpelling' => $spelled];
        }
    }

    /**
     * Option id => name, for labelling variants.
     *
     * @param list<array<string, mixed>> $groups
     * @param list<array<string, mixed>> $sharedGroups
     *
     * @return array<string, string>
     */
    private function optionNames(array $groups, array $sharedGroups): array
    {
        $names = [];

        foreach ([...$groups, ...$sharedGroups] as $group) {
            foreach ([...($group['options'] ?? []), ...($group['allOptions'] ?? [])] as $option) {
                $names[$option['id']] = $option['name'];
            }
        }

        return $names;
    }

    /**
     * An existing variant as the merchant reads it: "SW10001.1 (Size: A4, Colour: Red)".
     *
     * @param array<string, mixed>       $variant
     * @param list<array<string, mixed>> $groups
     * @param array<string, string>      $names
     */
    private function variantLabel(array $variant, array $groups, array $names): string
    {
        $parts = [];

        foreach ($groups as $group) {
            foreach ($group['options'] as $option) {
                if (\in_array($option['id'], $variant['optionIds'], true)) {
                    $parts[] = $group['name'] . ': ' . ($names[$option['id']] ?? $option['name']);
                }
            }
        }

        return $variant['productNumber'] . ($parts !== [] ? ' (' . implode(', ', $parts) . ')' : '');
    }

    /**
     * A new combination as the merchant reads it: "Size: A4, Colour: Red".
     *
     * @param list<array<string, mixed>> $targets
     * @param list<string>               $combination
     */
    private function combinationLabel(array $targets, array $combination): string
    {
        $parts = [];

        foreach ($targets as $position => $target) {
            foreach ($target['values'] as $value) {
                if ($value['ref'] === $combination[$position]) {
                    $parts[] = $target['name'] . ': ' . $value['name'];
                }
            }
        }

        return implode(', ', $parts);
    }

    /**
     * The plan in the shape the preview shows.
     *
     * @param list<array<string, mixed>>          $choices
     * @param list<string>                        $selected
     * @param list<array<string, mixed>>          $groups
     * @param list<array<string, mixed>>          $sharedGroups
     * @param list<array<string, mixed>>          $targets
     * @param list<array<string, mixed>>          $removedGroups
     * @param list<array<string, mixed>>          $keptGroups
     * @param list<array<string, mixed>>          $variants
     * @param array<string, list<string>>         $keep
     * @param list<array<string, mixed>>          $orphans
     * @param list<array<string, mixed>>          $merged
     * @param list<array<string, mixed>>          $update
     * @param list<array<string, mixed>>          $create
     * @param list<array<string, mixed>>          $notes
     * @param array<string, string>               $names
     * @param array<string, mixed>                $state
     *
     * @return array<string, mixed>
     */
    private function publicPlan(array $choices, array $selected, array $groups, array $sharedGroups, array $targets, array $removedGroups, array $keptGroups, array $variants, array $keep, array $orphans, array $merged, array $update, array $create, int $missing, int $limit, array $notes, array $names, array $state): array
    {
        $byStatus = static fn (array $target, string $status): array => array_values(array_column(
            array_filter($target['values'], static fn (array $value): bool => $value['status'] === $status),
            'name',
        ));

        $publicGroups = array_map(static fn (array $target): array => [
            'name' => $target['name'],
            'isNew' => $target['groupId'] === null,
            'addedToProduct' => $target['groupId'] !== null && !$target['onProduct'],
            'fromTemplate' => $target['field'] !== null,
            'added' => $byStatus($target, self::STATUS_ADD),
            'created' => $byStatus($target, self::STATUS_CREATE),
            'removed' => $target['removed'],
            'kept' => array_values(array_map(
                static fn (array $value): array => ['id' => (string) $value['optionId'], 'name' => $value['name']],
                array_filter($target['values'], static fn (array $value): bool => $value['status'] === self::STATUS_KEPT),
            )),
        ], $targets);

        $variantLabel = fn (array $variant): array => ['id' => $variant['id'], 'label' => $this->variantLabel($variant, $groups, $names)];

        $variantsById = [];

        foreach ($variants as $variant) {
            $variantsById[$variant['id']] = $variant;
        }

        $publicVariants = [
            'existing' => \count($variants),
            'create' => array_map(fn (array $pending): array => [
                'label' => $this->combinationLabel($targets, $pending['refs']),
                'copyFrom' => $pending['copyFrom'] !== null ? $variantLabel($variantsById[$pending['copyFrom']])['label'] : null,
            ], $create),
            'missing' => $missing,
            'limit' => $limit,
            'update' => \count($update),
            'delete' => array_map($variantLabel, $orphans),
            'kept' => array_values(array_map(
                $variantLabel,
                array_filter($variants, static fn (array $variant): bool => \in_array($variant['id'], $keep['variants'], true)),
            )),
            'merged' => $merged,
        ];

        if ($state['isCloseout'] && $create !== []) {
            $notes[] = ['type' => 'closeoutStock'];
        }

        $groupChanges = array_filter($publicGroups, static fn (array $group): bool => $group['isNew'] || $group['addedToProduct'] || $group['added'] !== [] || $group['created'] !== [] || $group['removed'] !== []);

        return [
            'choices' => array_map(function (array $choice) use ($selected, $groups, $sharedGroups): array {
                [$match] = $this->matchItem($groups, [], [$choice['label'], $choice['name']]);
                $shared = $match === null ? $this->matchItem($sharedGroups, [], [$choice['label'], $choice['name']])[0] : null;

                return [
                    'name' => $choice['name'],
                    'label' => $choice['label'],
                    'values' => array_column($choice['entries'], 'label'),
                    'isPriceRelevant' => $choice['isPriceRelevant'],
                    'open' => $choice['open'],
                    'selected' => \in_array($choice['name'], $selected, true),
                    'matches' => $match !== null ? $groups[$match['index']]['name'] : null,
                    'matchesShared' => $shared !== null ? $sharedGroups[$shared['index']]['name'] : null,
                ];
            }, $choices),
            'groups' => $publicGroups,
            'removedGroups' => $removedGroups,
            'keptGroups' => $keptGroups,
            'variants' => $publicVariants,
            'notes' => $notes,
            'becomesVariantProduct' => $variants === [] && $create !== [],
            'hasChanges' => $groupChanges !== [] || $removedGroups !== [] || $create !== [] || $update !== [] || $orphans !== [] || $merged !== [],
        ];
    }
}
