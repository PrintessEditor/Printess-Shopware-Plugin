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
 * Printess fields on the order:
 *
 * - `PrintessReproductionOf` / `PrintessReproducedAs` link an order duplicated to reproduce its
 *   dropship items with its copies (see `PrintessOrderReproductionService`).
 * - `PrintessDropshipAddressIds` mapped each shipping address of an order to the Printess dropship
 *   address record created for it. It is no longer written - every send of a dropship group now
 *   creates its own record (see `PrintessProductionService::produceLineItems()`) - and stays
 *   defined so the values on orders produced before remain visible.
 */
class OrderCustomFieldsInstaller
{
    private const CUSTOM_FIELD_SET_ID = '0B1C2D3E4F5648A7B8C9D0E1F2A3B4C5';
    private const CUSTOM_FIELD_SET_NAME = 'printess_order';

    private const DROPSHIP_ADDRESS_IDS_FIELD_ID = '1C2D3E4F5061728394A5B6C7D8E9F0A1';
    public const DROPSHIP_ADDRESS_IDS_FIELD_NAME = 'PrintessDropshipAddressIds';

    private const REPRODUCTION_OF_FIELD_ID = 'B489256EF36A4C40A381F4B1475A91A3';
    public const REPRODUCTION_OF_FIELD_NAME = 'PrintessReproductionOf';

    private const REPRODUCED_AS_FIELD_ID = '337C755FAFD34050BD2B9CAFE8984252';
    public const REPRODUCED_AS_FIELD_NAME = 'PrintessReproducedAs';

    private const ORDER_RELATION_ID = '2D3E4F5061728394A5B6C7D8E9F0A1B2';

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
                'id' => Uuid::fromStringToHex(self::ORDER_RELATION_ID),
                'customFieldSetId' => Uuid::fromStringToHex(self::CUSTOM_FIELD_SET_ID),
                'entityName' => 'order',
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
                        'en-GB' => 'Printess',
                        'de-DE' => 'Printess',
                    ],
                    'customFieldPosition' => 0,
                ],
                'customFields' => [
                    [
                        'id' => Uuid::fromStringToHex(self::DROPSHIP_ADDRESS_IDS_FIELD_ID),
                        'name' => self::DROPSHIP_ADDRESS_IDS_FIELD_NAME,
                        'type' => CustomFieldTypes::TEXT,
                        'config' => [
                            'label' => ['en-GB' => 'Printess dropship address ids', 'de-DE' => 'Printess Dropship-Adress-IDs'],
                            'customFieldPosition' => 1,
                        ],
                    ],
                    [
                        'id' => Uuid::fromStringToHex(self::REPRODUCTION_OF_FIELD_ID),
                        'name' => self::REPRODUCTION_OF_FIELD_NAME,
                        'type' => CustomFieldTypes::TEXT,
                        'config' => [
                            'label' => ['en-GB' => 'Printess: reproduction of order', 'de-DE' => 'Printess: erneute Produktion von Bestellung'],
                            'customFieldPosition' => 2,
                        ],
                    ],
                    [
                        'id' => Uuid::fromStringToHex(self::REPRODUCED_AS_FIELD_ID),
                        'name' => self::REPRODUCED_AS_FIELD_NAME,
                        'type' => CustomFieldTypes::TEXT,
                        'config' => [
                            'label' => ['en-GB' => 'Printess: reproduced as orders', 'de-DE' => 'Printess: erneut produziert als Bestellungen'],
                            'customFieldPosition' => 3,
                        ],
                    ],
                ],
            ],
        ], $context);
    }
}
