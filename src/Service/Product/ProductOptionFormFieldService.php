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

namespace PrintessShopwareIntegration\Service\Product;

use Shopware\Core\Content\Property\PropertyGroupCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

class ProductOptionFormFieldService
{
    public function __construct(private readonly EntityRepository $propertyGroupOptionRepository)
    {
    }

    /**
     * @param string[] $optionIds
     *
     * @return array<int, array{name: string, value: string}>
     */
    public function getFormFields(array $optionIds): array
    {
        return array_map(
            static fn (array $option): array => ['name' => $option['groupName'], 'value' => $option['optionName']],
            $this->getOptionDetails($optionIds),
        );
    }

    /**
     * Maps every option of every group in the variant configurator to its group/option id, so the
     * storefront can look up which group/option was matched by an editor form field change and switch
     * the displayed variant to it.
     *
     * @return array<int, array{groupId: string, optionId: string, name: string, value: string}>
     */
    public function getConfiguratorOptionsMap(PropertyGroupCollection $configuratorSettings): array
    {
        $optionIds = [];

        foreach ($configuratorSettings as $group) {
            foreach ($group->getOptions() ?? [] as $option) {
                $optionIds[] = $option->getId();
            }
        }

        return $this->getConfiguratorOptionsMapForOptionIds($optionIds);
    }

    /**
     * Same output as `getConfiguratorOptionsMap()`, but starting from a flat list of option ids
     * (e.g. from `ProductConfiguratorGroupNameResolver::getConfiguratorOptionIds()`) instead of the
     * twig-only, grouped-by-property-group `PropertyGroupCollection` shape - for use outside of a
     * twig rendering context, where that grouped collection isn't available.
     *
     * @param string[] $optionIds
     *
     * @return array<int, array{groupId: string, optionId: string, name: string, value: string}>
     */
    public function getConfiguratorOptionsMapForOptionIds(array $optionIds): array
    {
        return array_map(
            static fn (array $option): array => [
                'groupId' => $option['groupId'],
                'optionId' => $option['optionId'],
                'name' => $option['groupName'],
                'value' => $option['optionName'],
            ],
            $this->getOptionDetails($optionIds),
        );
    }

    /**
     * @param string[] $optionIds
     *
     * @return array<int, array{groupId: string, optionId: string, groupName: string, optionName: string}>
     */
    private function getOptionDetails(array $optionIds): array
    {
        if ($optionIds === []) {
            return [];
        }

        $criteria = new Criteria($optionIds);
        $criteria->addAssociation('group');

        // Storefront requests resolve translations for the visitor's language, but the
        // template designer builds form field mappings against the values entered in the
        // shop's default language, so options/groups are re-fetched with the default context
        // rather than reusing whatever language the current sales channel request resolved.
        $options = $this->propertyGroupOptionRepository->search($criteria, Context::createDefaultContext());

        $details = [];

        foreach ($options as $option) {
            $group = $option->getGroup();

            if ($group === null || $group->getName() === null || $option->getName() === null) {
                continue;
            }

            $details[] = [
                'groupId' => $group->getId(),
                'optionId' => $option->getId(),
                'groupName' => $group->getName(),
                'optionName' => $option->getName(),
            ];
        }

        return $details;
    }
}
