<?php declare(strict_types=1);

namespace PrintessEditor\Service;

use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\CustomField\CustomFieldTypes;
use Shopware\Core\Framework\Uuid\Uuid;

class CustomFieldsInstaller
{
    private const CUSTOM_FIELDSET_NAME = 'Printess';

    public static function GetCustomProductFieldSetId() {
        return Uuid::fromStringToHex("3BBC9FBE127F46678224EBF67FA80A1F");
    }

    private function getCustomProductFields(string $customFieldSetId): array
    {
        return [
            'name' => self::CUSTOM_FIELDSET_NAME,
            'id' => $customFieldSetId,
            'config' => [
                'label' => [
                    'de-DE' => 'Printess Produkt Einstellungen',
                    'en-GB' => 'Printess product settings',
                ],
                'customFieldPosition' => 0
            ],
            'customFields' => [
                [
                    'name' => self::CUSTOM_FIELDSET_NAME . "TemplateName",
                    'id' => Uuid::fromStringToHex("097F87C5188D445EB711F7A16D65C8C3"),
                    'type' => CustomFieldTypes::TEXT,
                    'config' => [
                        'label' => [
                            'de-DE' => 'Templatename',
                            'en-GB' => 'Template name',
                        ],
                        'helpText' => [
                            'de-DE' => 'Der Name ihres Printess Templates',
                            'en-GB' => 'The name of your printess template',
                        ],
                        'customFieldPosition' => 1
                    ]
                ],
                [
                    'name' => self::CUSTOM_FIELDSET_NAME . "DropshipId",
                    'id' => Uuid::fromStringToHex("40E3F40A52A34A0C9D42D575BC6F5E38"),
                    'type' => CustomFieldTypes::TEXT,
                    'config' => [
                        'label' => [
                            'de-DE' => 'Dropship Produktdefinitions Id',
                            'en-GB' => 'Dropship product definition id'
                        ],
                        'helpText' => [
                            'de-DE' => 'Die Id der zu verwendenden Drophsip- Produktdefinition (-1: Templatesetting benutzen; 0: Kein Dropshipping verwenden)',
                            'en-GB' => 'The dropship product definition id that should be used (-1: Use the template setting; 0: Do not use dropshipping)'
                        ],
                        'customFieldPosition' => 2
                    ]
                ],
                [
                    'name' => self::CUSTOM_FIELDSET_NAME . "MergeTemplates",
                    'id' => Uuid::fromStringToHex("AF75BE27E3144D8CBF5785992D1616FC"),
                    'type' => CustomFieldTypes::TEXT,
                    'config' => [
                        'label' => [
                            'de-DE' => 'Merge- Templates',
                            'en-GB' => 'Merge templates'
                        ],
                        'helpText' => [
                            'de-DE' => 'Name des zu mergenden Templates / Json configuritation für ein oder mehr templates.',
                            'en-GB' => 'Name of the template that should be merged / Json configuration for one or more templates.'
                        ],
                        'customFieldPosition' => 3
                    ]
                ],
                [
                    'name' => self::CUSTOM_FIELDSET_NAME . "OutputFileFormat",
                    'id' => Uuid::fromStringToHex("52E7CE44168311EF9D5E0FA27C34574D"),
                    'type' => CustomFieldTypes::TEXT,
                    'config' => [
                        'label' => [
                            'de-DE' => 'Ausgabe Dateiformat',
                            'en-GB' => 'Output file format'
                        ],
                        'helpText' => [
                            'de-DE' => 'Das Ausgabe Deitformat: PDF (Standard); PNG; TIFF; JPG',
                            'en-GB' => 'Output file format: PDF (default); PNG; TIFF; JPG'
                        ],
                        'customFieldPosition' => 4
                    ]
                ],
                [
                    'name' => self::CUSTOM_FIELDSET_NAME . "OutputResolution",
                    'id' => Uuid::fromStringToHex("72451F94168311EF9A3079A27C34574D"),
                    'type' => CustomFieldTypes::TEXT,
                    'config' => [
                        'label' => [
                            'de-DE' => 'Ausgabe Auflösung',
                            'en-GB' => 'Output resolution'
                        ],
                        'helpText' => [
                            'de-DE' => 'Ausgabe Auflösung (Standard 300 DPI)',
                            'en-GB' => 'Output resolution (default 300 DPI)'
                        ],
                        'customFieldPosition' => 5
                    ]
                ],
                [
                    'name' => self::CUSTOM_FIELDSET_NAME . "OutputJpgCompression",
                    'id' => Uuid::fromStringToHex("F30FE114168711EF97E4C9CF7C34574D"),
                    'type' => CustomFieldTypes::TEXT,
                    'config' => [
                        'label' => [
                            'de-DE' => 'JPG Kompression',
                            'en-GB' => 'JPG compression'
                        ],
                        'helpText' => [
                            'de-DE' => 'Ausgabe JPEG Compression',
                            'en-GB' => 'Output jpeg compression'
                        ],
                        'customFieldPosition' => 6
                    ]
                ],
                [
                    'name' => self::CUSTOM_FIELDSET_NAME . "FormFieldMappings",
                    'id' => Uuid::fromStringToHex("06A6DF5C168811EFB28C0DD07C34574D"),
                    'type' => CustomFieldTypes::TEXT,
                    'config' => [
                        'label' => [
                            'de-DE' => 'Formfeld Zuweisungen',
                            'en-GB' => 'Formfield mappings'
                        ],
                        'helpText' => [
                            'de-DE' => 'Json Konfiguration für die Formfeld zuweisungen zwischen Shopware und Printess.',
                            'en-GB' => 'Json configuration for the form field mappings between Shopware and Printess.'
                        ],
                        'customFieldPosition' => 7
                    ]
                ],
                [
                    'name' => self::CUSTOM_FIELDSET_NAME . "UIVersion",
                    'id' => Uuid::fromStringToHex("a65646bf0ec4468c8e0b9ef52b317839"),
                    'type' => CustomFieldTypes::SELECT,
                    'componentName' => 'sw-single-select',
                    'config' => [
                        'componentName' => 'sw-single-select',
                        'label' => [
                            'de-DE' => 'Benutzeroberflächenversion',
                            'en-GB' => 'Userinterface Version'
                        ],
                        'helpText' => [
                            'de-DE' => 'Wähle Klassich für die alte Benutzeroberfläche. Wähle Panel für die neue Version.',
                            'en-GB' => 'Choose Classic UI for the (old) legacy UI. Choose Panel UI for the new Panel UI.'
                        ],
                        'options' =>
                        [
                            [
                                'label' => [
                                    'en-GB' => 'Classic UI',
                                    'de-DE' => 'Klassisch'
                                ],
                                'value' => 'classic'
                            ],
                            [
                                'label' => [
                                    'en-GB' => 'Panel UI',
                                    'de-DE' => 'Panel',
                                ],
                                'value' => 'panel'
                            ]
                        ],
                        'customFieldPosition' => 8
                    ]
                ]

            ]
        ];
    }

    public function __construct(
        private readonly EntityRepository $customFieldSetRepository,
        private readonly EntityRepository $customFieldSetRelationRepository
    ) {
    }

    public function install(Context $context): void
    {
        $this->customFieldSetRepository->upsert([
            $this->getCustomProductFields(CustomFieldsInstaller::GetCustomProductFieldSetId())
        ], $context);
    }

    public function update(Context $context): void
    {
        $this->customFieldSetRepository->upsert([
            $this->getCustomProductFields(CustomFieldsInstaller::GetCustomProductFieldSetId())
        ], $context);
    }

    public function addRelations(Context $context): void
    {
         $this->customFieldSetRelationRepository->upsert(array_map(function (string $customFieldSetId) {
             return [
                 'customFieldSetId' => $customFieldSetId,
                 'entityName' => 'product',
                 'id' => Uuid::fromStringToHex("7dd240e628084f3cb5dfab30b69abc4e"),
             ];
        }, $this->getCustomFieldSetIds($context)), $context);
    }

    /**
     * @return string[]
     */
    private function getCustomFieldSetIds(Context $context): array
    {
        $criteria = new Criteria();

        $criteria->addFilter(new EqualsFilter('name', self::CUSTOM_FIELDSET_NAME));

        return $this->customFieldSetRepository->searchIds($criteria, $context)->getIds();
    }
}
