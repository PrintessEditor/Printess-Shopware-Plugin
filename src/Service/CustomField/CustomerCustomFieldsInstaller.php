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
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\CustomField\CustomFieldTypes;

/**
 * A single system-generated field on the customer entity: a stable, opaque identity token handed to
 * the Printess editor as `shopUserId` so it can recognize the same customer's saved designs across
 * visits - see `PrintessCustomerShopUserIdResolver`. Not meant to be edited by shop staff.
 */
class CustomerCustomFieldsInstaller
{
    private const CUSTOM_FIELD_SET_ID = '019f5fc2cfd570b7b2dcc4730560274c';
    private const CUSTOM_FIELD_SET_NAME = 'printess_customer';

    private const SHOP_USER_ID_FIELD_ID = '019f5fc2cfd570b7b2dcc47305d57bb4';
    private const SHOP_USER_ID_FIELD_NAME = 'PrintessShopUserId';

    private const CUSTOMER_RELATION_ID = '019f5fc2cfd570b7b2dcc473063fe265';

    public function __construct(
        private readonly EntityRepository $customFieldSetRepository,
        private readonly EntityRepository $customFieldSetRelationRepository,
    ) {
    }

    public function install(Context $context): void
    {
        $this->upsertCustomFieldSet($context);
    }

    public function update(Context $context): void
    {
        $this->upsertCustomFieldSet($context);
    }

    public function activate(Context $context): void
    {
        $this->customFieldSetRelationRepository->upsert([
            [
                'id' => Uuid::fromStringToHex(self::CUSTOMER_RELATION_ID),
                'customFieldSetId' => Uuid::fromStringToHex(self::CUSTOM_FIELD_SET_ID),
                'entityName' => 'customer',
            ],
        ], $context);
    }

    private function upsertCustomFieldSet(Context $context): void
    {
        $this->customFieldSetRepository->upsert([
            [
                'id' => Uuid::fromStringToHex(self::CUSTOM_FIELD_SET_ID),
                'name' => self::CUSTOM_FIELD_SET_NAME,
                'config' => [
                    'label' => [
                        'en-GB' => 'Printess customer settings',
                        'de-DE' => 'Printess Kunden Einstellungen',
                    ],
                    'customFieldPosition' => 0,
                ],
                'customFields' => [
                    [
                        'id' => Uuid::fromStringToHex(self::SHOP_USER_ID_FIELD_ID),
                        'name' => self::SHOP_USER_ID_FIELD_NAME,
                        'type' => CustomFieldTypes::TEXT,
                        'config' => [
                            'label' => [
                                'en-GB' => 'Printess shop user id (system-generated)',
                                'de-DE' => 'Printess Shop-Benutzer-ID (systemgeneriert)',
                            ],
                            'helpText' => [
                                'en-GB' => 'Generated automatically the first time this customer saves a design in the Printess editor. Do not edit - changing or clearing it disconnects the customer from their previously saved designs.',
                                'de-DE' => 'Wird automatisch generiert, sobald dieser Kunde erstmals ein Design im Printess-Editor speichert. Nicht bearbeiten - eine Änderung oder Löschung trennt den Kunden von seinen zuvor gespeicherten Designs.',
                            ],
                            'customFieldPosition' => 1,
                        ],
                    ],
                ],
            ],
        ], $context);
    }
}
