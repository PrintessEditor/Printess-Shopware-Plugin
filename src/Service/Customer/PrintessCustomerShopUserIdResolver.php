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

namespace PrintessShopwareIntegration\Service\Customer;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

/**
 * Get-or-create for the stable, opaque `shopUserId` handed to the Printess editor so it can recognize
 * the same customer's saved designs across visits (the editor has no session of its own - see
 * `printess-design-now.plugin.js`'s save-callback wiring). Persisted on the customer's own
 * `PrintessShopUserId` custom field (see `CustomerCustomFieldsInstaller`) rather than derived from a
 * secret, so rotating any app-wide secret can never orphan a customer's saved designs.
 */
class PrintessCustomerShopUserIdResolver
{
    private const CUSTOM_FIELD_NAME = 'PrintessShopUserId';
    private const TOKEN_BYTE_LENGTH = 32;

    public function __construct(private readonly EntityRepository $customerRepository)
    {
    }

    public function resolve(CustomerEntity $customer, Context $context): string
    {
        $existing = $customer->getCustomFieldsValue(self::CUSTOM_FIELD_NAME);

        if (\is_string($existing) && $existing !== '') {
            return $existing;
        }

        $shopUserId = bin2hex(random_bytes(self::TOKEN_BYTE_LENGTH));

        $customFields = array_merge($customer->getCustomFields() ?? [], [self::CUSTOM_FIELD_NAME => $shopUserId]);

        $this->customerRepository->update([
            ['id' => $customer->getId(), 'customFields' => $customFields],
        ], $context);

        return $shopUserId;
    }
}
