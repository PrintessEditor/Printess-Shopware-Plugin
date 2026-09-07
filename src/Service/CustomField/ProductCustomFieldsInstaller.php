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

class ProductCustomFieldsInstaller
{
    /**
     * Kept identical to the custom field set/field/relation IDs used by the legacy
     * `printess-editor` plugin so existing product data (custom_fields.PrintessTemplateName)
     * keeps working without a data migration when switching plugins.
     */
    private const CUSTOM_FIELD_SET_ID = '3BBC9FBE127F46678224EBF67FA80A1F';
    private const CUSTOM_FIELD_SET_NAME = 'Printess';
    private const TEMPLATE_NAME_FIELD_ID = '097F87C5188D445EB711F7A16D65C8C3';
    private const TEMPLATE_NAME_FIELD_NAME = 'PrintessTemplateName';
    private const FORM_FIELDS_FIELD_ID = 'E7C994E262631A71409D47500C2AC8AA';
    private const FORM_FIELDS_FIELD_NAME = 'PrintessFormFields';

    /**
     * Also kept identical to the legacy `printess-editor` plugin's field id/name, so existing
     * `custom_fields.PrintessMergeTemplates` product data keeps working without a migration.
     */
    private const MERGE_TEMPLATES_FIELD_ID = 'AF75BE27E3144D8CBF5785992D1616FC';
    private const MERGE_TEMPLATES_FIELD_NAME = 'PrintessMergeTemplates';

    private const PRINT_SETTING_NAME_FIELD_ID = 'C39FBCC32D1EDFCFB34D91286488F4FE';
    private const PRINT_SETTING_NAME_FIELD_NAME = 'PrintessPrintSettingName';

    private const DROPSHIPPING_CONFIG_FIELD_ID = '9C318E31E6618F301ACAECF354C74CFE';
    private const DROPSHIPPING_CONFIG_FIELD_NAME = 'PrintessDropshippingConfig';

    private const PRICE_CONFIG_FIELD_ID = 'D2F19E42B3F14BFDBB44A6F0A87ED123';
    private const PRICE_CONFIG_FIELD_NAME = 'PrintessPriceConfig';

    private const BOOK_SETTINGS_FIELD_ID = '019F5F245C11724E9F4EF8E41265D7BE';
    private const BOOK_SETTINGS_FIELD_NAME = 'PrintessBookSettings';

    private const BOOK_INSIDE_PAGE_COUNT_FIELD_ID = '019F5F5294F070F9B74037F14BC09921';
    private const BOOK_INSIDE_PAGE_COUNT_FIELD_NAME = 'PrintessBookInsidePageCount';

    private const PHOTOBOOK_THEME_FIELD_ID = '019F5F5294F070F9B74037F14CB060CD';
    private const PHOTOBOOK_THEME_FIELD_NAME = 'PrintessPhotobookTheme';

    /**
     * Explicit "is this actually forwarded to the editor" switches for the two mutually exclusive
     * book-page-count settings (`PrintessBookInsidePageCount`/`PrintessBookSettings`'s
     * `initialFreestylePhotobookPages`) - without these, whether either setting is "active" had to be
     * inferred from whether its value happened to be non-null, which stayed true forever once a value
     * had ever been set (see `sw-product-detail-printess`'s admin UI). `buy-widget-form.html.twig`
     * only forwards a setting to the storefront editor when its own toggle here is true.
     */
    private const BOOK_INSIDE_PAGE_COUNT_ENABLED_FIELD_ID = '019F6FC5DC0F72749ECB6E683E833250';
    private const BOOK_INSIDE_PAGE_COUNT_ENABLED_FIELD_NAME = 'PrintessBookInsidePageCountEnabled';

    private const MAGIC_PHOTOBOOK_ENABLED_FIELD_ID = '019F6FC5DC0F72749ECB6E683F5D6444';
    private const MAGIC_PHOTOBOOK_ENABLED_FIELD_NAME = 'PrintessMagicPhotobookEnabled';

    /**
     * Activates the SlimUi storefront frontend for this product - a reduced editor that only
     * integrates into the product page and doesn't support books/multi-page templates. While
     * enabled, `PrintessMergeTemplates`, `PrintessBookInsidePageCountEnabled` and
     * `PrintessMagicPhotobookEnabled` are hidden and ignored (see `sw-product-detail-printess` and
     * `buy-widget-form.html.twig`), and page-relevant `PrintessPriceConfig` fields are hidden in the
     * admin UI, since none of those apply to a single-page SlimUi template.
     */
    private const SLIM_UI_ENABLED_FIELD_ID = 'D451BB1465B3AC091A7654BDDB9A0C3B';
    private const SLIM_UI_ENABLED_FIELD_NAME = 'PrintessSlimUiEnabled';

    /**
     * One additional template merged on top of the main one, pushed to SlimUi as its own `merge1`
     * load parameter. Deliberately NOT the same thing as `PrintessMergeTemplates`, which is the full
     * editor's list-shaped setting (several templates, each with its own merge mode) and is hidden
     * while SlimUi is active: `merge1` takes a single template name and no mode, so the two cannot
     * share one field. Only forwarded while `PrintessSlimUiEnabled` is true, mirroring how
     * `PrintessMergeTemplates` is only forwarded while it is false.
     */
    private const SLIM_UI_MERGE_TEMPLATE_FIELD_ID = '019F6FC5DC0F72749ECB6E6840A17B12';
    private const SLIM_UI_MERGE_TEMPLATE_FIELD_NAME = 'PrintessSlimUiMergeTemplate';

    /**
     * Opt-in SlimUi sub-features for this product, as a list of feature keys (currently only
     * `pageNavigation`) - deliberately ONE list field rather than a boolean field per feature, since
     * SlimUi keeps gaining these and each new one should cost a key in `SLIM_UI_FEATURE_KEYS` plus a
     * snippet, not another custom field and another plugin version bump. Mirrors the Shopify
     * integration's own `slimUiFeatures` product setting (see its `TSlimUiFeature`/
     * `parseSlimUiFeatures()`), so a merchant running both shops configures the same thing twice
     * rather than learning two models.
     */
    private const SLIM_UI_FEATURES_FIELD_ID = '019F6FC5DC0F72749ECB6E68417C2A05';
    private const SLIM_UI_FEATURES_FIELD_NAME = 'PrintessSlimUiFeatures';

    /**
     * Every feature key the storefront understands - kept here (rather than only in the admin JS) so
     * the help text below can name them and a future server-side consumer has one source of truth.
     * `pageNavigation`: SlimUi renders its own page-preview strip above the product image, replacing
     * the storefront gallery's carousel controls/thumbnails for switching between the pages of a
     * design (front/back of a postcard, ...).
     * `thumbnailNavigation`: the storefront gallery's OWN thumbnails/arrows drive SlimUi's current
     * preview page instead, so switching slides tells SlimUi which page the shopper is looking at.
     * Has no effect while `pageNavigation` is active, which hides that chrome and renders its own.
     * Opt-in rather than always-on to stay configured the same way as the Shopify integration's
     * identically-named feature, where it must be opt-in because live shops rely on those thumbnails
     * switching nothing but the theme's own image.
     */
    public const SLIM_UI_FEATURE_KEYS = [
        'pageNavigation',
        'thumbnailNavigation',
    ];

    private const PRODUCT_RELATION_ID = '7dd240e628084f3cb5dfab30b69abc4e';

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
                'id' => Uuid::fromStringToHex(self::PRODUCT_RELATION_ID),
                'customFieldSetId' => Uuid::fromStringToHex(self::CUSTOM_FIELD_SET_ID),
                'entityName' => 'product',
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
                        'en-GB' => 'Printess product settings',
                        'de-DE' => 'Printess Produkt Einstellungen',
                    ],
                    'customFieldPosition' => 0,
                ],
                'customFields' => [
                    [
                        'id' => Uuid::fromStringToHex(self::TEMPLATE_NAME_FIELD_ID),
                        'name' => self::TEMPLATE_NAME_FIELD_NAME,
                        'type' => CustomFieldTypes::TEXT,
                        'config' => [
                            'label' => [
                                'en-GB' => 'Template name',
                                'de-DE' => 'Templatename',
                            ],
                            'helpText' => [
                                'en-GB' => 'The name of your Printess template. Products with a template name are personalizable and show the Printess editor on the product page.',
                                'de-DE' => 'Der Name Ihres Printess-Templates. Produkte mit einem Templatenamen sind personalisierbar und zeigen den Printess Editor auf der Produktseite an.',
                            ],
                            'customFieldPosition' => 1,
                        ],
                    ],
                    [
                        'id' => Uuid::fromStringToHex(self::FORM_FIELDS_FIELD_ID),
                        'name' => self::FORM_FIELDS_FIELD_NAME,
                        'type' => CustomFieldTypes::JSON,
                        'config' => [
                            'label' => [
                                'en-GB' => 'Preselected form field values',
                                'de-DE' => 'Vorausgewählte Formularfeldwerte',
                            ],
                            'helpText' => [
                                'en-GB' => 'Additional name/value pairs pushed to the Printess editor as form field values, on top of the ones derived from the selected product variant.',
                                'de-DE' => 'Zusätzliche Name/Wert-Paare, die als Formularfeldwerte an den Printess-Editor übergeben werden, zusätzlich zu denen der ausgewählten Produktvariante.',
                            ],
                            'customFieldPosition' => 2,
                        ],
                    ],
                    [
                        'id' => Uuid::fromStringToHex(self::MERGE_TEMPLATES_FIELD_ID),
                        'name' => self::MERGE_TEMPLATES_FIELD_NAME,
                        'type' => CustomFieldTypes::TEXT,
                        'config' => [
                            'label' => [
                                'en-GB' => 'Merge templates',
                                'de-DE' => 'Merge-Templates',
                            ],
                            'helpText' => [
                                'en-GB' => 'Additional Printess templates merged onto the main template when the editor opens, stored as a JSON string. Managed via the Printess tab; advanced users can edit the raw JSON here directly.',
                                'de-DE' => 'Zusätzliche Printess-Templates, die beim Öffnen des Editors in das Haupttemplate gemergt werden, gespeichert als JSON-String. Wird über den Printess-Tab verwaltet; erfahrene Benutzer können das JSON hier auch direkt bearbeiten.',
                            ],
                            'customFieldPosition' => 3,
                        ],
                    ],
                    [
                        'id' => Uuid::fromStringToHex(self::PRINT_SETTING_NAME_FIELD_ID),
                        'name' => self::PRINT_SETTING_NAME_FIELD_NAME,
                        'type' => CustomFieldTypes::TEXT,
                        'config' => [
                            'label' => [
                                'en-GB' => 'Print setting',
                                'de-DE' => 'Druckeinstellung',
                            ],
                            'helpText' => [
                                'en-GB' => 'Output/print setting used for production, overriding the plugin-wide default configured in the Printess settings.',
                                'de-DE' => 'Ausgabe-/Druckeinstellung für die Produktion, überschreibt den in den Printess-Einstellungen konfigurierten Standard.',
                            ],
                            'customFieldPosition' => 4,
                        ],
                    ],
                    [
                        'id' => Uuid::fromStringToHex(self::DROPSHIPPING_CONFIG_FIELD_ID),
                        'name' => self::DROPSHIPPING_CONFIG_FIELD_NAME,
                        'type' => CustomFieldTypes::TEXT,
                        'config' => [
                            'label' => [
                                'en-GB' => 'Dropshipping',
                                'de-DE' => 'Dropshipping',
                            ],
                            'helpText' => [
                                'en-GB' => 'Dropshipping mode and product definition used for production, stored as a JSON string, overriding the plugin-wide default configured in the Printess settings.',
                                'de-DE' => 'Dropshipping-Modus und Produktdefinition für die Produktion, gespeichert als JSON-String, überschreibt den in den Printess-Einstellungen konfigurierten Standard.',
                            ],
                            'customFieldPosition' => 5,
                        ],
                    ],
                    [
                        'id' => Uuid::fromStringToHex(self::PRICE_CONFIG_FIELD_ID),
                        'name' => self::PRICE_CONFIG_FIELD_NAME,
                        'type' => CustomFieldTypes::TEXT,
                        'config' => [
                            'label' => [
                                'en-GB' => 'Custom pricing',
                                'de-DE' => 'Individuelle Preisgestaltung',
                            ],
                            'helpText' => [
                                'en-GB' => 'Page-count-based and form-field-based pricing surcharges (e.g. for photo books), stored as a JSON string. Managed via the Printess tab.',
                                'de-DE' => 'Seitenzahl- und formularfeldbasierte Preiszuschläge (z. B. für Fotobücher), gespeichert als JSON-String. Wird über den Printess-Tab verwaltet.',
                            ],
                            'customFieldPosition' => 6,
                        ],
                    ],
                    [
                        'id' => Uuid::fromStringToHex(self::BOOK_SETTINGS_FIELD_ID),
                        'name' => self::BOOK_SETTINGS_FIELD_NAME,
                        'type' => CustomFieldTypes::TEXT,
                        'config' => [
                            'label' => [
                                'en-GB' => 'Magic Photobook settings',
                                'de-DE' => 'Magic-Fotobuch-Einstellungen',
                            ],
                            'helpText' => [
                                'en-GB' => 'Magic Photobook layout settings (spine, hinge, edge/bleed, min/max pages, layflat, ...) passed to the editor via its adjustBook API, stored as a JSON string. Managed via the Printess tab.',
                                'de-DE' => 'Layout-Einstellungen für Magic-Fotobücher (Falz, Scharnier, Rand/Beschnitt, Min./Max.-Seitenzahl, Layflat, ...), die über die adjustBook-API an den Editor übergeben werden, gespeichert als JSON-String. Wird über den Printess-Tab verwaltet.',
                            ],
                            'customFieldPosition' => 7,
                        ],
                    ],
                    [
                        'id' => Uuid::fromStringToHex(self::PHOTOBOOK_THEME_FIELD_ID),
                        'name' => self::PHOTOBOOK_THEME_FIELD_NAME,
                        'type' => CustomFieldTypes::TEXT,
                        'config' => [
                            'label' => [
                                'en-GB' => 'Photobook theme',
                                'de-DE' => 'Fotobuch-Theme',
                            ],
                            'helpText' => [
                                'en-GB' => 'Name of the Printess photobook theme, pushed to the editor as a "PHOTOBOOK_THEME" form field on every page that loads it. Managed via the Printess tab.',
                                'de-DE' => 'Name des Printess-Fotobuch-Themes, wird dem Editor auf jeder Seite, die ihn lädt, als Formularfeld "PHOTOBOOK_THEME" übergeben. Wird über den Printess-Tab verwaltet.',
                            ],
                            'customFieldPosition' => 8,
                        ],
                    ],
                    [
                        'id' => Uuid::fromStringToHex(self::BOOK_INSIDE_PAGE_COUNT_FIELD_ID),
                        'name' => self::BOOK_INSIDE_PAGE_COUNT_FIELD_NAME,
                        'type' => CustomFieldTypes::INT,
                        'config' => [
                            'label' => [
                                'en-GB' => 'Book inside page count',
                                'de-DE' => 'Buch-Innenseitenzahl',
                            ],
                            'helpText' => [
                                'en-GB' => 'Number of inside pages set via the editor\'s setBookInsidePages API on the product page. Never applies to Magic Photobooks (products with "Initial freestyle photobook pages" configured). Managed via the Printess tab.',
                                'de-DE' => 'Anzahl der Innenseiten, die über die setBookInsidePages-API des Editors auf der Produktseite gesetzt wird. Gilt niemals für Magic-Fotobücher (Produkte mit konfigurierter "Initiale Seitenzahl (Freestyle-Fotobuch)"). Wird über den Printess-Tab verwaltet.',
                            ],
                            'customFieldPosition' => 9,
                        ],
                    ],
                    [
                        'id' => Uuid::fromStringToHex(self::BOOK_INSIDE_PAGE_COUNT_ENABLED_FIELD_ID),
                        'name' => self::BOOK_INSIDE_PAGE_COUNT_ENABLED_FIELD_NAME,
                        'type' => CustomFieldTypes::BOOL,
                        'config' => [
                            'label' => [
                                'en-GB' => 'Book inside page count enabled',
                                'de-DE' => 'Buch-Innenseitenzahl aktiviert',
                            ],
                            'helpText' => [
                                'en-GB' => 'Whether "Book inside page count" is actually forwarded to the editor on the product page. Managed via the Printess tab.',
                                'de-DE' => 'Ob die "Buch-Innenseitenzahl" tatsächlich an den Editor auf der Produktseite übergeben wird. Wird über den Printess-Tab verwaltet.',
                            ],
                            'customFieldPosition' => 10,
                        ],
                    ],
                    [
                        'id' => Uuid::fromStringToHex(self::MAGIC_PHOTOBOOK_ENABLED_FIELD_ID),
                        'name' => self::MAGIC_PHOTOBOOK_ENABLED_FIELD_NAME,
                        'type' => CustomFieldTypes::BOOL,
                        'config' => [
                            'label' => [
                                'en-GB' => 'Magic Photobook settings enabled',
                                'de-DE' => 'Magic-Fotobuch-Einstellungen aktiviert',
                            ],
                            'helpText' => [
                                'en-GB' => 'Whether "Magic Photobook settings" are actually forwarded to the editor on the product page. Managed via the Printess tab.',
                                'de-DE' => 'Ob die "Magic-Fotobuch-Einstellungen" tatsächlich an den Editor auf der Produktseite übergeben werden. Wird über den Printess-Tab verwaltet.',
                            ],
                            'customFieldPosition' => 11,
                        ],
                    ],
                    [
                        'id' => Uuid::fromStringToHex(self::SLIM_UI_ENABLED_FIELD_ID),
                        'name' => self::SLIM_UI_ENABLED_FIELD_NAME,
                        'type' => CustomFieldTypes::BOOL,
                        'config' => [
                            'label' => [
                                'en-GB' => 'Activate SlimUi editor',
                                'de-DE' => 'SlimUi-Editor aktivieren',
                            ],
                            'helpText' => [
                                'en-GB' => 'Uses the reduced SlimUi editor, which integrates directly into the product page instead of opening the full Printess editor. Not compatible with the full editor\'s merge template list, books or multi-page pricing - those settings are hidden and ignored while this is active, and SlimUi\'s own single merge template setting takes their place. Managed via the Printess tab.',
                                'de-DE' => 'Verwendet den reduzierten SlimUi-Editor, der direkt in die Produktseite eingebunden wird, statt den vollständigen Printess-Editor zu öffnen. Nicht kompatibel mit der Merge-Template-Liste des vollständigen Editors, Büchern oder mehrseitiger Preisgestaltung - diese Einstellungen werden ausgeblendet und ignoriert, solange dies aktiv ist; stattdessen greift die eigene Einstellung für ein einzelnes SlimUi-Merge-Template. Wird über den Printess-Tab verwaltet.',
                            ],
                            'customFieldPosition' => 12,
                        ],
                    ],
                    [
                        'id' => Uuid::fromStringToHex(self::SLIM_UI_MERGE_TEMPLATE_FIELD_ID),
                        'name' => self::SLIM_UI_MERGE_TEMPLATE_FIELD_NAME,
                        'type' => CustomFieldTypes::TEXT,
                        'config' => [
                            'label' => [
                                'en-GB' => 'SlimUi merge template',
                                'de-DE' => 'SlimUi-Merge-Template',
                            ],
                            'helpText' => [
                                'en-GB' => 'Name of one additional Printess template merged on top of the main template. Only applies while the SlimUi editor is activated. Managed via the Printess tab.',
                                'de-DE' => 'Name eines zusätzlichen Printess-Templates, das über das Haupttemplate gemergt wird. Gilt nur, solange der SlimUi-Editor aktiviert ist. Wird über den Printess-Tab verwaltet.',
                            ],
                            'customFieldPosition' => 13,
                        ],
                    ],
                    [
                        'id' => Uuid::fromStringToHex(self::SLIM_UI_FEATURES_FIELD_ID),
                        'name' => self::SLIM_UI_FEATURES_FIELD_NAME,
                        'type' => CustomFieldTypes::JSON,
                        'config' => [
                            'label' => [
                                'en-GB' => 'SlimUi features',
                                'de-DE' => 'SlimUi-Funktionen',
                            ],
                            'helpText' => [
                                'en-GB' => 'Optional SlimUi sub-features activated for this product, as a list of feature keys (available: "pageNavigation", "thumbnailNavigation"). Only applies while the SlimUi editor is activated. Managed via the Printess tab.',
                                'de-DE' => 'Optionale SlimUi-Zusatzfunktionen für dieses Produkt, als Liste von Funktionsschlüsseln (verfügbar: "pageNavigation", "thumbnailNavigation"). Gilt nur, solange der SlimUi-Editor aktiviert ist. Wird über den Printess-Tab verwaltet.',
                            ],
                            'customFieldPosition' => 14,
                        ],
                    ],
                ],
            ],
        ], $context);
    }
}
