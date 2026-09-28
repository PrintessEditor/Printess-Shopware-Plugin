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

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\PrefixFilter;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Executes the internal part of a `VariantSyncPlanner` plan.
 *
 * - Writes in the **system language**, so new property groups and options get the names the
 *   storefront matches template form fields against, while keeping the admin user's context source
 *   so the DAL still enforces their create/update/delete privileges.
 * - Runs in one transaction: a failure leaves the product as it was, and running the sync again
 *   recomputes the plan from scratch.
 * - Never disables indexing: the storefront and the "which combinations exist" lookup rely on the
 *   indexer filling a variant's `optionIds`.
 */
class VariantSyncApplier
{
    private const BATCH_SIZE = 25;

    public function __construct(
        private readonly Connection $connection,
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $propertyGroupRepository,
        private readonly EntityRepository $propertyGroupOptionRepository,
        private readonly EntityRepository $productConfiguratorSettingRepository,
        private readonly EntityRepository $productOptionRepository,
    ) {
    }

    /**
     * @param array<string, mixed> $plan the `internal` part of `VariantSyncPlanner::plan()`
     *
     * @return array{created: int, updated: int, deleted: int, createdGroups: int, createdOptions: int}
     */
    public function apply(array $plan, Context $context): array
    {
        $context = new Context(
            $context->getSource(),
            $context->getRuleIds(),
            $context->getCurrencyId(),
            [Defaults::LANGUAGE_SYSTEM],
            $context->getVersionId(),
            $context->getCurrencyFactor(),
            false,
            $context->getTaxState(),
            $context->getRounding(),
        );

        return $this->connection->transactional(fn (): array => $this->write($plan, $context));
    }

    /**
     * @param array<string, mixed> $plan
     *
     * @return array{created: int, updated: int, deleted: int, createdGroups: int, createdOptions: int}
     */
    private function write(array $plan, Context $context): array
    {
        $productId = $plan['productId'];

        // 1. New property groups and options, and the ids their refs stand for.
        $ids = [];

        foreach ($plan['newGroups'] as $ref => $group) {
            $ids[$ref] = Uuid::randomHex();
        }

        foreach ($plan['newOptions'] as $ref => $option) {
            $ids[$ref] = Uuid::randomHex();
        }

        $resolve = static fn (string $ref): string => $ids[$ref] ?? $ref;

        if ($plan['newGroups'] !== []) {
            $this->propertyGroupRepository->create(array_values(array_map(
                static fn (string $ref, array $group): array => ['id' => $ids[$ref], 'name' => $group['name']],
                array_keys($plan['newGroups']),
                $plan['newGroups'],
            )), $context);
        }

        if ($plan['newOptions'] !== []) {
            $this->propertyGroupOptionRepository->create(array_values(array_map(
                static fn (string $ref, array $option): array => [
                    'id' => $ids[$ref],
                    'groupId' => $resolve($option['groupRef']),
                    'name' => $option['name'],
                    'position' => $option['position'],
                ],
                array_keys($plan['newOptions']),
                $plan['newOptions'],
            )), $context);
        }

        // 2. Variants that no longer fit, or are duplicates of an older one.
        if ($plan['delete'] !== []) {
            $this->productRepository->delete(array_map(static fn (string $id): array => ['id' => $id], $plan['delete']), $context);
        }

        // 3. Surviving variants, moved to their new combination in place.
        $additions = [];
        $removals = [];

        foreach ($plan['update'] as $update) {
            if ($update['add'] !== []) {
                $additions[] = [
                    'id' => $update['id'],
                    'options' => array_map(static fn (string $ref): array => ['id' => $resolve($ref)], $update['add']),
                ];
            }

            foreach ($update['remove'] as $optionId) {
                $removals[] = ['productId' => $update['id'], 'optionId' => $optionId];
            }
        }

        if ($removals !== []) {
            $this->productOptionRepository->delete($removals, $context);
        }

        foreach (array_chunk($additions, self::BATCH_SIZE) as $chunk) {
            $this->productRepository->update($chunk, $context);
        }

        // 4. The missing combinations.
        $numbers = $this->productNumbers($plan['productNumber'], \count($plan['create']), $context);
        $variants = [];

        foreach ($plan['create'] as $index => $pending) {
            $variant = [
                'id' => Uuid::randomHex(),
                'parentId' => $productId,
                'productNumber' => $numbers[$index],
                'stock' => 0,
                'options' => array_map(static fn (string $ref): array => ['id' => $resolve($ref)], $pending['refs']),
            ];

            // Copied from the closest variant only when that one has its own price; otherwise the
            // new variant inherits the parent's, like the variant it is modelled on.
            if ($pending['price'] !== null) {
                $variant['price'] = $pending['price'];
            }

            $variants[] = $variant;
        }

        foreach (array_chunk($variants, self::BATCH_SIZE) as $chunk) {
            $this->productRepository->create($chunk, $context);
        }

        // 5. The product's configurator: exactly the options the variants are built from. Written
        // last, against what is stored *now*: deleting a variant makes Shopware remove every
        // configurator setting of its parent that no remaining variant uses
        // (`ProductSubscriber::cleanupConfiguratorSettings()`), so settings written before the
        // deletes would be gone again, and kept options no variant uses need restoring.
        $this->syncConfigurator($productId, array_map($resolve, $plan['configuratorOptions']), $context);

        return [
            'created' => \count($variants),
            'updated' => \count($plan['update']),
            'deleted' => \count($plan['delete']),
            'createdGroups' => \count($plan['newGroups']),
            'createdOptions' => \count($plan['newOptions']),
        ];
    }

    /**
     * @param list<string> $optionIds
     */
    private function syncConfigurator(string $productId, array $optionIds, Context $context): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('productId', $productId));

        $stored = [];

        foreach ($this->productConfiguratorSettingRepository->search($criteria, $context) as $setting) {
            $stored[$setting->getOptionId()] = $setting->getId();
        }

        $missing = array_values(array_diff($optionIds, array_keys($stored)));
        $obsolete = array_values(array_diff_key($stored, array_flip($optionIds)));

        if ($obsolete !== []) {
            $this->productConfiguratorSettingRepository->delete(array_map(
                static fn (string $id): array => ['id' => $id],
                $obsolete,
            ), $context);
        }

        if ($missing !== []) {
            $this->productConfiguratorSettingRepository->create(array_map(
                static fn (string $optionId): array => ['id' => Uuid::randomHex(), 'productId' => $productId, 'optionId' => $optionId],
                $missing,
            ), $context);
        }
    }

    /**
     * `{parentNumber}.{n}` numbers not used by any product yet, the scheme Shopware's own variant
     * generator uses. Product numbers are unique across the whole shop, not just among one
     * product's variants, so every product with that prefix is looked at.
     *
     * @return list<string>
     */
    private function productNumbers(string $parentNumber, int $count, Context $context): array
    {
        if ($count === 0) {
            return [];
        }

        $criteria = new Criteria();
        $criteria->addFilter(new PrefixFilter('productNumber', $parentNumber . '.'));

        $taken = [];

        foreach ($this->productRepository->search($criteria, $context) as $product) {
            $taken[mb_strtolower($product->getProductNumber())] = true;
        }

        $numbers = [];
        $next = 1;

        while (\count($numbers) < $count) {
            $candidate = $parentNumber . '.' . $next++;

            if (!isset($taken[mb_strtolower($candidate)])) {
                $numbers[] = $candidate;
            }
        }

        return $numbers;
    }
}
