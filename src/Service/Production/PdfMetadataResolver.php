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

namespace PrintessShopwareIntegration\Service\Production;

use PrintessShopwareIntegration\Service\Configuration\PrintessConfigService;
use PrintessShopwareIntegration\Service\Product\PrintessProductCustomFieldResolver;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

/**
 * Builds the `PDF_Title`, `PDF_Subject` and `PDF_Author` form field values Printess writes into the
 * produced PDF's document properties.
 *
 * Each value is a text with `{{ variable }}` placeholders, configured plugin-wide (`pdfMetadata`
 * setting) and optionally per product (`PrintessPdfMetadata` custom field); a non-empty product value
 * wins over the plugin value for that one entry. The placeholders are filled in when production
 * starts. The admin component `printess-pdf-metadata-config` lists the same variables (keep
 * `VARIABLE_KEYS` and its `PDF_METADATA_VARIABLE_GROUPS` in sync).
 *
 * A placeholder the shop has no value for becomes empty; an entry that ends up empty is not sent, so
 * the template's own value for that form field applies.
 */
class PdfMetadataResolver
{
    public const PRODUCT_FIELD_NAME = 'PrintessPdfMetadata';

    /**
     * Setting key => form field name sent to Printess.
     */
    public const FORM_FIELDS = [
        'title' => 'PDF_Title',
        'subject' => 'PDF_Subject',
        'author' => 'PDF_Author',
    ];

    /**
     * `product.option.<group name>`: the value of one variant option, e.g. `product.option.Color`.
     */
    public const OPTION_VARIABLE_PREFIX = 'product.option.';

    public const VARIABLE_KEYS = [
        'order.number',
        'order.id',
        'order.date',
        'order.customerComment',
        'order.salesChannel',
        'lineItem.id',
        'lineItem.position',
        'lineItem.quantity',
        'product.name',
        'product.number',
        'product.variant',
        'product.options',
        'customer.name',
        'customer.firstName',
        'customer.lastName',
        'customer.company',
        'customer.email',
        'customer.number',
        'shipping.name',
        'shipping.company',
        'shipping.zip',
        'shipping.city',
        'shipping.country',
        'shipping.method',
        'production.date',
    ];

    private const PLACEHOLDER_PATTERN = '/\{\{\s*([^{}]*?)\s*\}\}/u';

    private const DATE_FORMAT = 'Y-m-d';

    public function __construct(
        private readonly PrintessConfigService $printessConfigService,
        private readonly PrintessProductCustomFieldResolver $productCustomFieldResolver,
        private readonly EntityRepository $productRepository,
    ) {
    }

    /**
     * @return array<string, string> form field name (`PDF_Title`, ...) => value, only non-empty values
     */
    public function resolve(OrderEntity $order, OrderLineItemEntity $lineItem, ?OrderDeliveryEntity $delivery, Context $context): array
    {
        $templates = $this->templates($order, $lineItem);

        if ($templates === []) {
            return [];
        }

        $variables = $this->variables($order, $lineItem, $delivery);
        $result = [];

        foreach ($templates as $key => $template) {
            $value = trim((string) preg_replace_callback(
                self::PLACEHOLDER_PATTERN,
                fn (array $match): string => $this->variableValue($match[1], $variables, $lineItem, $context),
                $template
            ));

            if ($value !== '') {
                $result[self::FORM_FIELDS[$key]] = $value;
            }
        }

        return $result;
    }

    /**
     * The text per entry: the product's own if it has one, otherwise the plugin's.
     *
     * @return array<string, string> setting key (`title`, ...) => text, only non-empty ones
     */
    private function templates(OrderEntity $order, OrderLineItemEntity $lineItem): array
    {
        $pluginValues = self::normalize($this->printessConfigService->getPdfMetadata($order->getSalesChannelId()));
        $productId = $lineItem->getProductId();
        $productValues = $productId !== null
            ? self::normalize($this->productCustomFieldResolver->resolve($lineItem->getProduct(), $productId, self::PRODUCT_FIELD_NAME))
            : [];

        return array_filter(
            $productValues + $pluginValues,
            static fn (string $value): bool => trim($value) !== ''
        );
    }

    /**
     * Accepts the stored object or its JSON string (the custom field can be written through the API).
     *
     * @return array<string, string>
     */
    public static function normalize(mixed $value): array
    {
        if (\is_string($value) && $value !== '') {
            try {
                $value = json_decode($value, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return [];
            }
        }

        if (!\is_array($value)) {
            return [];
        }

        $result = [];

        foreach (array_keys(self::FORM_FIELDS) as $key) {
            if (\is_string($value[$key] ?? null) && trim($value[$key]) !== '') {
                $result[$key] = $value[$key];
            }
        }

        return $result;
    }

    /**
     * @return array<string, string|null>
     */
    private function variables(OrderEntity $order, OrderLineItemEntity $lineItem, ?OrderDeliveryEntity $delivery): array
    {
        $product = $lineItem->getProduct();
        $payload = $lineItem->getPayload() ?? [];
        $productNumber = $product?->getProductNumber() ?? ($payload['productNumber'] ?? null);
        $options = $this->payloadOptions($lineItem);
        $customer = $order->getOrderCustomer();
        $shippingAddress = $delivery?->getShippingOrderAddress();

        return [
            'order.number' => $order->getOrderNumber(),
            'order.id' => $order->getId(),
            'order.date' => $order->getOrderDateTime()->format(self::DATE_FORMAT),
            'order.customerComment' => $order->getCustomerComment(),
            'order.salesChannel' => $order->getSalesChannel()?->getTranslation('name'),
            'lineItem.id' => $lineItem->getId(),
            'lineItem.position' => (string) $lineItem->getPosition(),
            'lineItem.quantity' => (string) $lineItem->getQuantity(),
            'product.name' => $lineItem->getLabel(),
            'product.number' => \is_string($productNumber) ? $productNumber : null,
            'product.variant' => implode(' / ', array_column($options, 'option')),
            'product.options' => implode(', ', array_map(
                static fn (array $option): string => $option['group'] . ': ' . $option['option'],
                $options
            )),
            'customer.name' => $customer !== null ? trim($customer->getFirstName() . ' ' . $customer->getLastName()) : null,
            'customer.firstName' => $customer?->getFirstName(),
            'customer.lastName' => $customer?->getLastName(),
            'customer.company' => $customer?->getCompany(),
            'customer.email' => $customer?->getEmail(),
            'customer.number' => $customer?->getCustomerNumber(),
            'shipping.name' => $shippingAddress !== null ? trim($shippingAddress->getFirstName() . ' ' . $shippingAddress->getLastName()) : null,
            'shipping.company' => $shippingAddress?->getCompany(),
            'shipping.zip' => $shippingAddress?->getZipcode(),
            'shipping.city' => $shippingAddress?->getCity(),
            'shipping.country' => $shippingAddress?->getCountry()?->getTranslation('name'),
            'shipping.method' => $delivery?->getShippingMethod()?->getTranslation('name'),
            'production.date' => (new \DateTimeImmutable())->format(self::DATE_FORMAT),
        ];
    }

    /**
     * @param array<string, string|null> $variables
     */
    private function variableValue(string $name, array $variables, OrderLineItemEntity $lineItem, Context $context): string
    {
        if (str_starts_with($name, self::OPTION_VARIABLE_PREFIX)) {
            return $this->optionValue(substr($name, \strlen(self::OPTION_VARIABLE_PREFIX)), $lineItem, $context) ?? '';
        }

        $value = $variables[$name] ?? null;

        return \is_string($value) ? $value : '';
    }

    /**
     * The variant options as the customer saw them when ordering (Shopware keeps them, in the order's
     * language, in the line item payload).
     *
     * @return list<array{group: string, option: string}>
     */
    private function payloadOptions(OrderLineItemEntity $lineItem): array
    {
        $options = ($lineItem->getPayload() ?? [])['options'] ?? null;

        if (!\is_array($options)) {
            return [];
        }

        $result = [];

        foreach ($options as $option) {
            if (\is_array($option) && \is_string($option['group'] ?? null) && \is_string($option['option'] ?? null)) {
                $result[] = ['group' => $option['group'], 'option' => $option['option']];
            }
        }

        return $result;
    }

    /**
     * The ordered variant's value for an option group. The group name is usually typed (or picked
     * in the admin) in the shop's default language, while the line item holds the names in the
     * order's language - so when the name isn't found there, it is looked up across all
     * translations of the variant's option groups.
     */
    private function optionValue(string $groupName, OrderLineItemEntity $lineItem, Context $context): ?string
    {
        $wanted = mb_strtolower(trim($groupName));

        if ($wanted === '') {
            return null;
        }

        $options = $this->payloadOptions($lineItem);

        foreach ($options as $option) {
            if (mb_strtolower(trim($option['group'])) === $wanted) {
                return $option['option'];
            }
        }

        $productId = $lineItem->getProductId();

        if ($productId === null) {
            return null;
        }

        $criteria = new Criteria([$productId]);
        $criteria->addAssociation('options.translations');
        $criteria->addAssociation('options.group.translations');

        $product = $this->productRepository->search($criteria, $context)->getEntities()->first();

        if (!$product instanceof ProductEntity) {
            return null;
        }

        foreach ($product->getOptions() ?? [] as $option) {
            $groupNames = [];

            foreach ($option->getGroup()?->getTranslations() ?? [] as $translation) {
                if (\is_string($translation->getName())) {
                    $groupNames[] = mb_strtolower(trim($translation->getName()));
                }
            }

            if (!\in_array($wanted, $groupNames, true)) {
                continue;
            }

            // Prefer the value in the order's language, as stored with the line item.
            foreach ($options as $payloadOption) {
                if (\in_array(mb_strtolower(trim($payloadOption['group'])), $groupNames, true)) {
                    return $payloadOption['option'];
                }
            }

            $name = $option->getTranslation('name');

            return \is_string($name) ? $name : null;
        }

        return null;
    }
}
