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

namespace PrintessShopwareIntegration;

use PrintessShopwareIntegration\Service\CustomField\CustomerCustomFieldsInstaller;
use PrintessShopwareIntegration\Service\CustomField\OrderCustomFieldsInstaller;
use PrintessShopwareIntegration\Service\CustomField\OrderLineItemCustomFieldsInstaller;
use PrintessShopwareIntegration\Service\CustomField\ProductCustomFieldsInstaller;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;

class PrintessShopwareIntegration extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        $this->getProductCustomFieldsInstaller()->install($installContext->getContext());
        $this->getOrderLineItemCustomFieldsInstaller()->install($installContext->getContext());
        $this->getOrderCustomFieldsInstaller()->install($installContext->getContext());
        $this->getCustomerCustomFieldsInstaller()->install($installContext->getContext());
    }

    public function update(UpdateContext $updateContext): void
    {
        $this->getProductCustomFieldsInstaller()->update($updateContext->getContext());
        $this->getOrderLineItemCustomFieldsInstaller()->update($updateContext->getContext());
        $this->getOrderCustomFieldsInstaller()->update($updateContext->getContext());
        $this->getCustomerCustomFieldsInstaller()->update($updateContext->getContext());
    }

    public function activate(ActivateContext $activateContext): void
    {
        $this->getProductCustomFieldsInstaller()->activate($activateContext->getContext());
        $this->getOrderLineItemCustomFieldsInstaller()->activate($activateContext->getContext());
        $this->getOrderCustomFieldsInstaller()->activate($activateContext->getContext());
        $this->getCustomerCustomFieldsInstaller()->activate($activateContext->getContext());
    }

    private function getProductCustomFieldsInstaller(): ProductCustomFieldsInstaller
    {
        return new ProductCustomFieldsInstaller(
            $this->container->get('custom_field_set.repository'),
            $this->container->get('custom_field_set_relation.repository'),
        );
    }

    private function getOrderLineItemCustomFieldsInstaller(): OrderLineItemCustomFieldsInstaller
    {
        return new OrderLineItemCustomFieldsInstaller(
            $this->container->get('custom_field_set.repository'),
            $this->container->get('custom_field_set_relation.repository'),
        );
    }

    private function getOrderCustomFieldsInstaller(): OrderCustomFieldsInstaller
    {
        return new OrderCustomFieldsInstaller(
            $this->container->get('custom_field_set.repository'),
            $this->container->get('custom_field_set_relation.repository'),
        );
    }

    private function getCustomerCustomFieldsInstaller(): CustomerCustomFieldsInstaller
    {
        return new CustomerCustomFieldsInstaller(
            $this->container->get('custom_field_set.repository'),
            $this->container->get('custom_field_set_relation.repository'),
        );
    }
}
