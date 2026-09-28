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

use PrintessShopwareIntegration\Service\Product\ProductConfiguratorGroupNameResolver;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionEntity;
use Shopware\Core\Content\Property\PropertyGroupEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\Price;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

/**
 * Reads what the template check and the variant sync compare the template with: the variant
 * property groups of a product, their options, and its variants - always from what is *saved*,
 * and always in the system language, which is what the storefront matches template form fields
 * against (see `ProductOptionFormFieldService`).
 *
 * A variant is resolved to its parent: the variant-building options live on the parent's
 * configurator settings.
 */
class ProductVariantStateReader
{
    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $propertyGroupOptionRepository,
        private readonly EntityRepository $propertyGroupRepository,
        private readonly ProductConfiguratorGroupNameResolver $productConfiguratorGroupNameResolver,
    ) {
    }

    /**
     * @return array{
     *     productId: string,
     *     isVariant: bool,
     *     productNumber: string,
     *     isCloseout: bool,
     *     groups: list<array{id: string, name: string, translatedNames: list<string>, options: list<array{id: string, name: string, translatedNames: list<string>}>, allOptions: list<array{id: string, name: string, translatedNames: list<string>}>}>,
     *     variants: list<array{id: string, productNumber: string, createdAt: string, optionIds: list<string>, price: list<array<string, mixed>>|null, stock: int}>
     * }|null null when the product does not exist
     */
    public function read(string $productId): ?array
    {
        // The default context reads the system language and does not resolve inheritance, so a
        // variant's `price` is its own (null when it inherits the parent's).
        $context = Context::createDefaultContext();
        $requested = $this->productRepository->search(new Criteria([$productId]), $context)->first();

        if (!$requested instanceof ProductEntity) {
            return null;
        }

        $parentId = $requested->getParentId() ?? $requested->getId();
        $parent = $requested->getParentId() !== null
            ? $this->productRepository->search(new Criteria([$parentId]), $context)->first()
            : $requested;

        if (!$parent instanceof ProductEntity) {
            return null;
        }

        return [
            'productId' => $parentId,
            'isVariant' => $requested->getParentId() !== null,
            'productNumber' => $parent->getProductNumber(),
            'isCloseout' => (bool) $parent->getIsCloseout(),
            'groups' => $this->readGroups($parentId, $context),
            'variants' => $this->readVariants($parentId, $context),
        ];
    }

    /**
     * Property groups whose name - in any language - equals one of the given names, ignoring case
     * (the database collation compares case-insensitively; exact comparison is up to the caller),
     * with all their options. Used to reuse an existing, shared group for a template field instead
     * of creating a duplicate.
     *
     * @param list<string> $names
     *
     * @return list<array{id: string, name: string, translatedNames: list<string>, allOptions: list<array{id: string, name: string, translatedNames: list<string>}>}>
     */
    public function findSharedGroups(array $names): array
    {
        $names = array_values(array_unique(array_filter($names, static fn (string $name): bool => $name !== '')));

        if ($names === []) {
            return [];
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('translations.name', $names));
        $criteria->addAssociation('translations');
        $criteria->addAssociation('options.translations');

        $groups = [];

        /** @var PropertyGroupEntity $group */
        foreach ($this->propertyGroupRepository->search($criteria, Context::createDefaultContext()) as $group) {
            if ($group->getName() === null) {
                continue;
            }

            $groups[] = [
                'id' => $group->getId(),
                'name' => $group->getName(),
                'translatedNames' => $this->translatedNames($group),
                'allOptions' => $this->sortedOptions($group->getOptions()?->getElements() ?? []),
            ];
        }

        return $groups;
    }

    /**
     * @return list<array{id: string, name: string, translatedNames: list<string>, options: list<array{id: string, name: string, translatedNames: list<string>}>, allOptions: list<array{id: string, name: string, translatedNames: list<string>}>}>
     */
    private function readGroups(string $parentId, Context $context): array
    {
        $optionIds = $this->productConfiguratorGroupNameResolver->getConfiguratorOptionIds($parentId, $context);

        if ($optionIds === []) {
            return [];
        }

        $criteria = new Criteria($optionIds);
        $criteria->addAssociation('translations');
        $criteria->addAssociation('group.translations');
        $criteria->addAssociation('group.options.translations');

        $byGroup = [];
        $groupEntities = [];

        /** @var PropertyGroupOptionEntity $option */
        foreach ($this->propertyGroupOptionRepository->search($criteria, $context) as $option) {
            $group = $option->getGroup();

            if ($group === null || $group->getName() === null || $option->getName() === null) {
                continue;
            }

            $groupEntities[$group->getId()] = $group;
            $byGroup[$group->getId()][] = $option;
        }

        uasort($groupEntities, static fn (PropertyGroupEntity $a, PropertyGroupEntity $b): int => [$a->getPosition() ?? 0, $a->getName()] <=> [$b->getPosition() ?? 0, $b->getName()]);

        $groups = [];

        foreach ($groupEntities as $groupId => $group) {
            $groups[] = [
                'id' => $groupId,
                'name' => (string) $group->getName(),
                'translatedNames' => $this->translatedNames($group),
                'options' => $this->sortedOptions($byGroup[$groupId]),
                'allOptions' => $this->sortedOptions($group->getOptions()?->getElements() ?? []),
            ];
        }

        return $groups;
    }

    /**
     * @return list<array{id: string, productNumber: string, createdAt: string, optionIds: list<string>, price: list<array<string, mixed>>|null, stock: int}>
     */
    private function readVariants(string $parentId, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('parentId', $parentId));
        // Read from the mapping itself, not from the denormalized `optionIds`, which only the
        // product indexer fills.
        $criteria->addAssociation('options');

        $variants = [];

        /** @var ProductEntity $variant */
        foreach ($this->productRepository->search($criteria, $context) as $variant) {
            $variants[] = [
                'id' => $variant->getId(),
                'productNumber' => $variant->getProductNumber(),
                'createdAt' => $variant->getCreatedAt()?->format(\DATE_ATOM) ?? '',
                'optionIds' => array_values($variant->getOptions()?->getIds() ?? []),
                'price' => $variant->getPrice() === null ? null : array_values(array_map(
                    static fn (Price $price): array => self::serializePrice($price),
                    $variant->getPrice()->getElements(),
                )),
                'stock' => $variant->getStock(),
            ];
        }

        return $variants;
    }

    /**
     * @param array<PropertyGroupOptionEntity> $options
     *
     * @return list<array{id: string, name: string, translatedNames: list<string>}>
     */
    private function sortedOptions(array $options): array
    {
        $options = array_filter($options, static fn (PropertyGroupOptionEntity $option): bool => $option->getName() !== null);
        usort($options, static fn (PropertyGroupOptionEntity $a, PropertyGroupOptionEntity $b): int => [$a->getPosition() ?? 0, $a->getName()] <=> [$b->getPosition() ?? 0, $b->getName()]);

        return array_values(array_map(
            fn (PropertyGroupOptionEntity $option): array => ['id' => $option->getId(), 'name' => (string) $option->getName(), 'translatedNames' => $this->translatedNames($option)],
            $options,
        ));
    }

    /**
     * The entity's names in every language other than the system language, which is the one the
     * storefront compares. A template spelled after one of them (typically a shop whose system
     * language is not the template's) is reported instead of silently treated as a mismatch.
     *
     * @return list<string>
     */
    private function translatedNames(PropertyGroupEntity|PropertyGroupOptionEntity $entity): array
    {
        $names = [];

        /** @var Entity $translation */
        foreach ($entity->getTranslations() ?? [] as $translation) {
            $name = $translation->get('name');

            if ($translation->get('languageId') !== Defaults::LANGUAGE_SYSTEM && \is_string($name) && $name !== '' && $name !== $entity->getName()) {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * The write format of a price, so a new variant can copy it.
     *
     * @return array<string, mixed>
     */
    private static function serializePrice(Price $price): array
    {
        $data = [
            'currencyId' => $price->getCurrencyId(),
            'gross' => $price->getGross(),
            'net' => $price->getNet(),
            'linked' => $price->getLinked(),
        ];

        if ($price->getListPrice() !== null) {
            $data['listPrice'] = self::serializePrice($price->getListPrice());
        }

        if ($price->getRegulationPrice() !== null) {
            $data['regulationPrice'] = self::serializePrice($price->getRegulationPrice());
        }

        return $data;
    }
}
