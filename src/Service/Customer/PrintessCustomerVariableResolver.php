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

use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;

/**
 * Resolves one of a fixed set of "variable" keys (see `VARIABLE_KEYS`) against the storefront's
 * currently logged in customer, for a "Preselected form field value" (see
 * `sw-product-detail-printess`) that an admin configured to be pushed dynamically instead of as a
 * fixed value - e.g. "billingAddress.city" resolves to the customer's default billing address city.
 *
 * Returns null (never an empty string) whenever the variable can't be resolved - no customer is
 * logged in (guest), or the customer has no address of the requested kind - so callers can tell
 * "unresolved" apart from "resolved to an empty string" and fall back to the admin-configured
 * fallback value.
 */
class PrintessCustomerVariableResolver
{
    public const VARIABLE_KEYS = [
        'customer.firstName',
        'customer.lastName',
        'customer.fullName',
        'customer.email',
        'customer.customerNumber',
        'customer.company',
        'billingAddress.street',
        'billingAddress.zipcode',
        'billingAddress.city',
        'billingAddress.country',
        'shippingAddress.street',
        'shippingAddress.zipcode',
        'shippingAddress.city',
        'shippingAddress.country',
    ];

    public function resolve(?CustomerEntity $customer, string $variableKey): ?string
    {
        if ($customer === null) {
            return null;
        }

        return match ($variableKey) {
            'customer.firstName' => $this->nullIfEmpty($customer->getFirstName()),
            'customer.lastName' => $this->nullIfEmpty($customer->getLastName()),
            'customer.fullName' => $this->nullIfEmpty(trim($customer->getFirstName() . ' ' . $customer->getLastName())),
            'customer.email' => $this->nullIfEmpty($customer->getEmail()),
            'customer.customerNumber' => $this->nullIfEmpty($customer->getCustomerNumber()),
            'customer.company' => $this->nullIfEmpty($customer->getCompany()),
            'billingAddress.street' => $this->nullIfEmpty($customer->getDefaultBillingAddress()?->getStreet()),
            'billingAddress.zipcode' => $this->nullIfEmpty($customer->getDefaultBillingAddress()?->getZipcode()),
            'billingAddress.city' => $this->nullIfEmpty($customer->getDefaultBillingAddress()?->getCity()),
            'billingAddress.country' => $this->countryName($customer->getDefaultBillingAddress()),
            'shippingAddress.street' => $this->nullIfEmpty($customer->getDefaultShippingAddress()?->getStreet()),
            'shippingAddress.zipcode' => $this->nullIfEmpty($customer->getDefaultShippingAddress()?->getZipcode()),
            'shippingAddress.city' => $this->nullIfEmpty($customer->getDefaultShippingAddress()?->getCity()),
            'shippingAddress.country' => $this->countryName($customer->getDefaultShippingAddress()),
            default => null,
        };
    }

    private function countryName(?CustomerAddressEntity $address): ?string
    {
        return $this->nullIfEmpty($address?->getCountry()?->getName());
    }

    private function nullIfEmpty(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }
}
