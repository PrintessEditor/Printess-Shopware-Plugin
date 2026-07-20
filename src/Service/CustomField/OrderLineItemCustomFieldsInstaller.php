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
 * Tracks the Printess production job for an order line item, from the moment it is sent to
 * production (or held back for manual approval) through to its final result.
 */
class OrderLineItemCustomFieldsInstaller
{
    private const CUSTOM_FIELD_SET_ID = 'B6D2E4F1A3C74C6F9C6E9F6E6A6B6C6D';
    private const CUSTOM_FIELD_SET_NAME = 'printess_production';

    private const STATUS_FIELD_ID = 'C7E3F5A2B4D85D709D7F0A7F7B7C7D7E';
    public const STATUS_FIELD_NAME = 'PrintessProductionStatus';

    private const JOB_ID_FIELD_ID = 'D8F4062B3C5E96E1AE8091B8C8D8E8F8';
    public const JOB_ID_FIELD_NAME = 'PrintessProductionJobId';

    private const RESULT_FIELD_ID = 'E9053173D4FA76F2BF91A2C9D9E9F909';
    public const RESULT_FIELD_NAME = 'PrintessProductionResult';

    private const ERROR_FIELD_ID = 'FA164284E50B87F3CA02B3DAEAFA0A1A';
    public const ERROR_FIELD_NAME = 'PrintessProductionError';

    private const ORDER_LINE_ITEM_RELATION_ID = 'A1B2C3D4E5F647A8B9C0D1E2F3A4B5C6';

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
                'id' => Uuid::fromStringToHex(self::ORDER_LINE_ITEM_RELATION_ID),
                'customFieldSetId' => Uuid::fromStringToHex(self::CUSTOM_FIELD_SET_ID),
                'entityName' => 'order_line_item',
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
                        'en-GB' => 'Printess production',
                        'de-DE' => 'Printess Produktion',
                    ],
                    'customFieldPosition' => 0,
                ],
                'customFields' => [
                    [
                        'id' => Uuid::fromStringToHex(self::STATUS_FIELD_ID),
                        'name' => self::STATUS_FIELD_NAME,
                        'type' => CustomFieldTypes::TEXT,
                        'config' => [
                            'label' => ['en-GB' => 'Printess production status', 'de-DE' => 'Printess Produktionsstatus'],
                            'customFieldPosition' => 1,
                        ],
                    ],
                    [
                        'id' => Uuid::fromStringToHex(self::JOB_ID_FIELD_ID),
                        'name' => self::JOB_ID_FIELD_NAME,
                        'type' => CustomFieldTypes::TEXT,
                        'config' => [
                            'label' => ['en-GB' => 'Printess production job id', 'de-DE' => 'Printess Produktionsauftrags-ID'],
                            'customFieldPosition' => 2,
                        ],
                    ],
                    [
                        'id' => Uuid::fromStringToHex(self::RESULT_FIELD_ID),
                        'name' => self::RESULT_FIELD_NAME,
                        'type' => CustomFieldTypes::TEXT,
                        'config' => [
                            'label' => ['en-GB' => 'Printess production result', 'de-DE' => 'Printess Produktionsergebnis'],
                            'customFieldPosition' => 3,
                        ],
                    ],
                    [
                        'id' => Uuid::fromStringToHex(self::ERROR_FIELD_ID),
                        'name' => self::ERROR_FIELD_NAME,
                        'type' => CustomFieldTypes::TEXT,
                        'config' => [
                            'label' => ['en-GB' => 'Printess production error', 'de-DE' => 'Printess Produktionsfehler'],
                            'customFieldPosition' => 4,
                        ],
                    ],
                ],
            ],
        ], $context);
    }
}
