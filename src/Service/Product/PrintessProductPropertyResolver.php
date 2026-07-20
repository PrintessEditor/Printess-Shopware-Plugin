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

use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

/**
 * Lets a "Preselected form field value" (see `sw-product-detail-printess`) be sourced from one of
 * this product's own non-variant properties (e.g. "Material") instead of a fixed/customer-variable
 * value, so an admin can choose exactly which form field name a given property is pushed as - as
 * opposed to the old behaviour of auto-pushing every non-variant property under its own group name.
 *
 * Variant-generating properties (`product.optionIds`/`configuratorSettings`) are unrelated and still
 * pushed automatically regardless of this - the storefront's variant-switching and price-relevant
 * form field logic depends on those always being present.
 */
class PrintessProductPropertyResolver
{
    public function __construct(private readonly EntityRepository $productRepository)
    {
    }

    /**
     * Resolves the option name this product has assigned for the given property group, or null if
     * the product has no property assigned in that group at all (the caller then falls back to the
     * admin-configured fallback value).
     */
    public function resolve(?ProductEntity $product, string $groupId): ?string
    {
        foreach ($product?->getProperties() ?? [] as $option) {
            if ($option->getGroupId() === $groupId) {
                return $option->getName();
            }
        }

        return null;
    }

    /**
     * The distinct property groups this product currently has at least one option assigned from -
     * used to populate the admin's "Variable" picker when a preselected form field's source is set
     * to "Product property". Read with inheritance enabled, since a variant's own `properties`
     * assignment can be inherited from its parent, same as the storefront-effective value this
     * mirrors.
     *
     * @return list<array{id: string, name: string}>
     */
    public function getPropertyGroupOptions(string $productId, Context $context): array
    {
        $product = $context->enableInheritance(
            fn (Context $inheritanceContext) => $this->loadProductWithProperties($productId, $inheritanceContext)
        );

        $groups = [];

        foreach ($product?->getProperties() ?? [] as $option) {
            $group = $option->getGroup();

            if ($group === null || $group->getName() === null || isset($groups[$group->getId()])) {
                continue;
            }

            $groups[$group->getId()] = ['id' => $group->getId(), 'name' => $group->getName()];
        }

        return array_values($groups);
    }

    private function loadProductWithProperties(string $productId, Context $context): ?ProductEntity
    {
        $criteria = new Criteria([$productId]);
        $criteria->addAssociation('properties.group');

        return $this->productRepository->search($criteria, $context)->getEntities()->first();
    }
}
