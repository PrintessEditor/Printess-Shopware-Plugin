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

class ProductConfiguratorGroupNameResolver
{
    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly ProductOptionFormFieldService $productOptionFormFieldService,
    ) {
    }

    /**
     * The variant-generating options are configured on the parent/main product, so a variant's own id
     * is resolved to its parent before reading `configuratorSettings`.
     *
     * @return string[] distinct, untranslated (default-language) property group names used to build
     *                   variants of the given product
     */
    public function getGroupNames(string $productId, Context $context): array
    {
        $optionIds = $this->getConfiguratorOptionIds($productId, $context);
        $groupNames = array_column($this->productOptionFormFieldService->getFormFields($optionIds), 'name');

        return array_values(array_unique($groupNames));
    }

    /**
     * All variant-generating option ids for this product's parent (resolved from a variant's own id
     * if needed) - the flat, per-option-row shape `product.configuratorSettings` is actually stored
     * as (each row is one (product, option) assignment), as opposed to the twig-only, grouped-by-
     * property-group `configuratorSettings`/`sortedPropertyGroups` shape used by
     * `ProductOptionFormFieldService::getConfiguratorOptionsMap()`. Use
     * `getConfiguratorOptionsMap()`-equivalent `ProductOptionFormFieldService::getConfiguratorOptionsMapForOptionIds()`
     * with this method's result to build a group/option id map outside of a twig context.
     *
     * @return string[]
     */
    public function getConfiguratorOptionIds(string $productId, Context $context): array
    {
        $product = $this->loadProductWithConfiguratorSettings($productId, $context);

        if ($product === null) {
            return [];
        }

        if ($product->getParentId() !== null) {
            $product = $this->loadProductWithConfiguratorSettings($product->getParentId(), $context);
        }

        $optionIds = [];

        foreach ($product?->getConfiguratorSettings() ?? [] as $setting) {
            $optionIds[] = $setting->getOptionId();
        }

        return array_values(array_unique($optionIds));
    }

    private function loadProductWithConfiguratorSettings(string $productId, Context $context): ?ProductEntity
    {
        $criteria = new Criteria([$productId]);
        $criteria->addAssociation('configuratorSettings');

        return $this->productRepository->search($criteria, $context)->first();
    }
}
