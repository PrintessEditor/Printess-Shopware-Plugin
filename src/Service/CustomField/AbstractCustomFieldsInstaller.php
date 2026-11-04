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

namespace PrintessShopwareIntegration\Service\CustomField;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

/**
 * Writes custom field sets/fields/relations so that rows left behind by an earlier installation
 * (an uninstall that kept the plugin data, the legacy `printess-editor` plugin, a manual import, ...)
 * are reused rather than collided with: `custom_field.name` and
 * `custom_field_set_relation (set_id, entity_name)` are unique, so upserting our own IDs on top of
 * rows that carry the same name/set+entity under different IDs fails with a duplicate key error.
 * Every write therefore first swaps our IDs for the IDs already in the database.
 */
abstract class AbstractCustomFieldsInstaller
{
    public function __construct(
        protected readonly EntityRepository $customFieldSetRepository,
        protected readonly EntityRepository $customFieldSetRelationRepository,
        protected readonly EntityRepository $customFieldRepository,
    ) {
    }

    /**
     * @param array<string, mixed> $customFieldSet
     */
    protected function upsertCustomFieldSetReusingExisting(array $customFieldSet, Context $context): void
    {
        $customFieldSet['id'] = $this->resolveCustomFieldSetId($customFieldSet['id'], $customFieldSet['name'], $context);

        $fieldNames = array_column($customFieldSet['customFields'] ?? [], 'name');
        if ($fieldNames !== []) {
            $existingFieldIds = [];
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsAnyFilter('name', $fieldNames));
            foreach ($this->customFieldRepository->search($criteria, $context)->getEntities() as $existingField) {
                $existingFieldIds[$existingField->getName()] = $existingField->getId();
            }

            foreach ($customFieldSet['customFields'] as &$customField) {
                $customField['id'] = $existingFieldIds[$customField['name']] ?? $customField['id'];
            }
            unset($customField);
        }

        $this->customFieldSetRepository->upsert([$customFieldSet], $context);
    }

    protected function upsertCustomFieldSetRelation(string $relationId, string $customFieldSetId, string $customFieldSetName, string $entityName, Context $context): void
    {
        $customFieldSetId = $this->resolveCustomFieldSetId($customFieldSetId, $customFieldSetName, $context);

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customFieldSetId', $customFieldSetId));
        $criteria->addFilter(new EqualsFilter('entityName', $entityName));
        $existingRelationId = $this->customFieldSetRelationRepository->searchIds($criteria, $context)->firstId();

        $this->customFieldSetRelationRepository->upsert([
            [
                'id' => $existingRelationId ?? $relationId,
                'customFieldSetId' => $customFieldSetId,
                'entityName' => $entityName,
            ],
        ], $context);
    }

    /**
     * Our own set ID if it exists (or nothing does yet), otherwise the ID of an existing set of the
     * same name - so a set created under a different ID is updated rather than duplicated.
     */
    private function resolveCustomFieldSetId(string $customFieldSetId, string $customFieldSetName, Context $context): string
    {
        if ($this->customFieldSetRepository->searchIds(new Criteria([$customFieldSetId]), $context)->firstId() !== null) {
            return $customFieldSetId;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', $customFieldSetName));

        return $this->customFieldSetRepository->searchIds($criteria, $context)->firstId() ?? $customFieldSetId;
    }
}
