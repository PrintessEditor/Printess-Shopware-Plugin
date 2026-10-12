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

use PrintessShopwareIntegration\Service\CustomField\OrderCustomFieldsInstaller;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\AbsolutePriceDefinition;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRule;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryStates;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\OrderStates;
use Shopware\Core\Content\Product\Stock\AbstractStockStorage;
use Shopware\Core\Content\Product\Stock\StockAlteration;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Util\Random;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;
use Shopware\Core\System\StateMachine\Loader\InitialStateIdLoader;

/**
 * Duplicates an order to reproduce dropship items under a new order number, for dropshippers that
 * refuse a number they have received before (Siteflow, for example) - the alternative to sending
 * again under the order number with a suffix, see `dropshipReproductionMode`.
 *
 * The copy contains the chosen line items with their designs and **their prices**, plus the
 * original's customer, addresses, shipping and payment method. It is free for the customer:
 *
 * - a credit line, labelled with the merchant's message, offsets the items exactly - per tax rate,
 *   so net, tax and gross all come to 0 without rounding remainders;
 * - shipping is 0, the customer paid it with the original order;
 * - the payment is created as "paid".
 *
 * The customer sees the copy in their account, with the message on the credit line and as the
 * order comment. Creating it triggers no state transition, so no mails or flows. Shopware reduces
 * stock for the new line items; the merchant decides whether that stays. Both orders reference
 * each other (`PrintessReproductionOf` / `PrintessReproducedAs`).
 */
class PrintessOrderReproductionService
{
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $stateMachineStateRepository,
        private readonly NumberRangeValueGeneratorInterface $numberRangeValueGenerator,
        private readonly InitialStateIdLoader $initialStateIdLoader,
        private readonly AbstractStockStorage $stockStorage,
        private readonly bool $stockManagementEnabled,
    ) {
    }

    /**
     * @param list<string> $lineItemIds the original's line items to copy
     *
     * @throws PrintessProductionActionException when there is nothing to copy
     *
     * @return array{id: string, orderNumber: string}
     */
    public function copy(string $originalOrderId, array $lineItemIds, string $message, bool $reduceStock, Context $context): array
    {
        $original = $this->loadOrder($originalOrderId, $context);

        if ($original === null) {
            throw new PrintessProductionActionException('The order to copy does not exist anymore.');
        }

        $lineItems = array_values(array_filter(
            $original->getLineItems()?->getElements() ?? [],
            static fn (OrderLineItemEntity $lineItem): bool => \in_array($lineItem->getId(), $lineItemIds, true),
        ));
        usort($lineItems, static fn (OrderLineItemEntity $a, OrderLineItemEntity $b): int => $a->getPosition() <=> $b->getPosition());

        if ($lineItems === []) {
            throw new PrintessProductionActionException('There are no items to copy.');
        }

        $message = trim($message);
        $orderId = Uuid::randomHex();
        $orderNumber = $this->numberRangeValueGenerator->getValue(OrderDefinition::ENTITY_NAME, $context, $original->getSalesChannelId());
        $decimals = $original->getItemRounding()?->getDecimals() ?? 2;

        // Every address of the order once, under a new id - a delivery's shipping address and the
        // billing address may be the same row.
        $addressIds = [];
        $addresses = [];

        foreach ($original->getAddresses() ?? [] as $address) {
            $addressIds[$address->getId()] = Uuid::randomHex();
            $addresses[] = $this->copyAddress($address, $addressIds[$address->getId()]);
        }

        $lineItemData = [];
        $newLineItemIds = [];
        /** @var array<string, array{delivery: OrderDeliveryEntity, positions: list<array<string, mixed>>}> $deliveries */
        $deliveries = [];
        $total = 0.0;
        /** @var array<string, array{rate: float, tax: float, price: float}> $taxes */
        $taxes = [];
        /** @var array<string, float> $taxRates */
        $taxRates = [];

        foreach ($lineItems as $index => $lineItem) {
            $lineItemId = Uuid::randomHex();
            $newLineItemIds[$lineItem->getId()] = $lineItemId;
            $price = $lineItem->getPrice();
            $lineItemData[] = [
                'id' => $lineItemId,
                'identifier' => $lineItem->getIdentifier(),
                'productId' => $lineItem->getProductId(),
                'referencedId' => $lineItem->getReferencedId(),
                'quantity' => $lineItem->getQuantity(),
                'type' => $lineItem->getType(),
                'label' => $lineItem->getLabel(),
                'description' => $lineItem->getDescription(),
                'good' => $lineItem->getGood(),
                'removable' => $lineItem->getRemovable(),
                'stackable' => $lineItem->getStackable(),
                'states' => $lineItem->getStates(),
                'position' => $index + 1,
                'coverId' => $lineItem->getCoverId(),
                'price' => $price,
                'priceDefinition' => $lineItem->getPriceDefinition(),
                // The design (save token, thumbnail, ...) - but no production state: the copy's
                // items are produced on their own.
                'payload' => $lineItem->getPayload() ?? [],
            ];

            $delivery = $this->deliveryOf($original, $lineItem);

            if ($delivery !== null) {
                $deliveries[$delivery->getId()]['delivery'] = $delivery;
                $deliveries[$delivery->getId()]['positions'][] = [
                    'id' => Uuid::randomHex(),
                    'orderLineItemId' => $lineItemId,
                    'orderLineItemVersionId' => Defaults::LIVE_VERSION,
                    'price' => $price,
                ];
            }

            if ($price === null) {
                continue;
            }

            $total += $price->getTotalPrice();

            foreach ($price->getCalculatedTaxes() as $tax) {
                $key = (string) $tax->getTaxRate();
                $taxes[$key] ??= ['rate' => $tax->getTaxRate(), 'tax' => 0.0, 'price' => 0.0];
                $taxes[$key]['tax'] += $tax->getTax();
                $taxes[$key]['price'] += $tax->getPrice();
            }

            foreach ($price->getTaxRules() as $rule) {
                $taxRates[(string) $rule->getTaxRate()] = $rule->getTaxRate();
            }
        }

        // The exact negative of the items, tax rate by tax rate.
        $creditTaxes = new CalculatedTaxCollection(array_values(array_map(
            static fn (array $tax): CalculatedTax => new CalculatedTax(round(-$tax['tax'], $decimals), $tax['rate'], round(-$tax['price'], $decimals)),
            $taxes,
        )));
        $creditRules = new TaxRuleCollection(array_values(array_map(
            static fn (float $rate): TaxRule => new TaxRule($rate),
            $taxRates,
        )));
        $creditTotal = round(-$total, $decimals);
        $lineItemData[] = [
            'id' => Uuid::randomHex(),
            'identifier' => Uuid::randomHex(),
            'quantity' => 1,
            'type' => LineItem::CREDIT_LINE_ITEM_TYPE,
            'label' => $message,
            'good' => false,
            'removable' => true,
            'stackable' => false,
            'position' => \count($lineItems) + 1,
            'price' => new CalculatedPrice($creditTotal, $creditTotal, $creditTaxes, $creditRules, 1),
            'priceDefinition' => new AbsolutePriceDefinition($creditTotal),
            'payload' => ['_printessReproductionCredit' => true],
        ];

        $deliveryData = [];

        foreach ($deliveries as $entry) {
            $delivery = $entry['delivery'];
            $deliveryData[] = [
                'id' => Uuid::randomHex(),
                'shippingOrderAddressId' => $addressIds[$delivery->getShippingOrderAddressId()] ?? null,
                'shippingOrderAddressVersionId' => Defaults::LIVE_VERSION,
                'shippingMethodId' => $delivery->getShippingMethodId(),
                'shippingDateEarliest' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                'shippingDateLatest' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                'shippingCosts' => self::zeroPrice(1),
                'stateId' => $this->initialStateIdLoader->get(OrderDeliveryStates::STATE_MACHINE),
                'positions' => $entry['positions'],
            ];
        }

        $transaction = $original->getTransactions()?->get((string) $original->getPrimaryOrderTransactionId()) ?? $original->getTransactions()?->first();
        $transactionData = [];

        if ($transaction !== null) {
            $transactionData[] = [
                'id' => Uuid::randomHex(),
                'paymentMethodId' => $transaction->getPaymentMethodId(),
                'amount' => self::zeroPrice(1),
                'stateId' => $this->paidStateId($context),
            ];
        }

        $customer = $original->getOrderCustomer();
        $data = [
            'id' => $orderId,
            'orderNumber' => $orderNumber,
            'salesChannelId' => $original->getSalesChannelId(),
            'languageId' => $original->getLanguageId(),
            'currencyId' => $original->getCurrencyId(),
            'currencyFactor' => $original->getCurrencyFactor(),
            'orderDateTime' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            'stateId' => $this->initialStateIdLoader->get(OrderStates::STATE_MACHINE),
            'price' => new CartPrice(0.0, 0.0, 0.0, new CalculatedTaxCollection(), new TaxRuleCollection(), $original->getPrice()->getTaxStatus(), 0.0),
            'shippingCosts' => self::zeroPrice(1),
            'itemRounding' => json_decode(json_encode($original->getItemRounding(), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR),
            'totalRounding' => json_decode(json_encode($original->getTotalRounding(), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR),
            'deepLinkCode' => Random::getBase64UrlString(32),
            'billingAddressId' => $addressIds[$original->getBillingAddressId()] ?? null,
            'billingAddressVersionId' => Defaults::LIVE_VERSION,
            'addresses' => $addresses,
            'lineItems' => $lineItemData,
            'deliveries' => $deliveryData,
            'transactions' => $transactionData,
            'primaryOrderDeliveryId' => $deliveryData[0]['id'] ?? null,
            'primaryOrderTransactionId' => $transactionData[0]['id'] ?? null,
            // Shown to the customer in their account. The original's own comment travels on as
            // the dispatch notice, see `PrintessProductionService::dropshipAddressPayload()`.
            'customerComment' => $message,
            'customFields' => [
                OrderCustomFieldsInstaller::REPRODUCTION_OF_FIELD_NAME => json_encode([
                    'id' => $original->getId(),
                    'orderNumber' => $original->getOrderNumber(),
                    'customerComment' => $original->getCustomerComment(),
                    'lineItemIds' => $newLineItemIds,
                ], \JSON_THROW_ON_ERROR),
            ],
        ];

        if ($customer !== null) {
            $data['orderCustomer'] = [
                'id' => Uuid::randomHex(),
                'customerId' => $customer->getCustomerId(),
                'email' => $customer->getEmail(),
                'firstName' => $customer->getFirstName(),
                'lastName' => $customer->getLastName(),
                'salutationId' => $customer->getSalutationId(),
                'title' => $customer->getTitle(),
                'company' => $customer->getCompany(),
                'customerNumber' => $customer->getCustomerNumber(),
                'vatIds' => $customer->getVatIds(),
                'remoteAddress' => $customer->getRemoteAddress(),
                'customFields' => $customer->getCustomFields(),
            ];
        }

        $source = $context->getSource();

        if ($source instanceof AdminApiSource && $source->getUserId() !== null) {
            $data['createdById'] = $source->getUserId();
        }

        $this->orderRepository->create([$data], $context);

        // Shopware reduced stock for the new line items, as for any order.
        if (!$reduceStock && $this->stockManagementEnabled) {
            $changes = [];

            foreach ($lineItems as $lineItem) {
                if ($lineItem->getType() === LineItem::PRODUCT_LINE_ITEM_TYPE && $lineItem->getReferencedId() !== null) {
                    $changes[] = new StockAlteration($newLineItemIds[$lineItem->getId()], $lineItem->getReferencedId(), $lineItem->getQuantity(), 0);
                }
            }

            $this->stockStorage->alter($changes, $context);
        }

        $reproducedAs = json_decode((string) ($original->getCustomFieldsValue(OrderCustomFieldsInstaller::REPRODUCED_AS_FIELD_NAME) ?? '[]'), true);
        $reproducedAs = \is_array($reproducedAs) ? $reproducedAs : [];
        $reproducedAs[] = [
            'id' => $orderId,
            'orderNumber' => $orderNumber,
            'lineItemIds' => array_keys($newLineItemIds),
            'createdAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ];

        $this->orderRepository->update([[
            'id' => $original->getId(),
            'customFields' => [OrderCustomFieldsInstaller::REPRODUCED_AS_FIELD_NAME => json_encode($reproducedAs, \JSON_THROW_ON_ERROR)],
        ]], $context);

        return ['id' => $orderId, 'orderNumber' => $orderNumber];
    }

    /**
     * The delivery that ships a line item: the one listing it, otherwise the first.
     */
    private function deliveryOf(OrderEntity $order, OrderLineItemEntity $lineItem): ?OrderDeliveryEntity
    {
        foreach ($order->getDeliveries() ?? [] as $delivery) {
            foreach ($delivery->getPositions() ?? [] as $position) {
                if ($position->getOrderLineItemId() === $lineItem->getId()) {
                    return $delivery;
                }
            }
        }

        return $order->getDeliveries()?->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function copyAddress(OrderAddressEntity $address, string $id): array
    {
        return array_filter([
            'id' => $id,
            'countryId' => $address->getCountryId(),
            'countryStateId' => $address->getCountryStateId(),
            'salutationId' => $address->getSalutationId(),
            'title' => $address->getTitle(),
            'firstName' => $address->getFirstName(),
            'lastName' => $address->getLastName(),
            'company' => $address->getCompany(),
            'department' => $address->getDepartment(),
            'street' => $address->getStreet(),
            'zipcode' => $address->getZipcode(),
            'city' => $address->getCity(),
            'phoneNumber' => $address->getPhoneNumber(),
            'additionalAddressLine1' => $address->getAdditionalAddressLine1(),
            'additionalAddressLine2' => $address->getAdditionalAddressLine2(),
            'vatId' => $address->getVatId(),
            'customFields' => $address->getCustomFields(),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private static function zeroPrice(int $quantity): CalculatedPrice
    {
        return new CalculatedPrice(0.0, 0.0, new CalculatedTaxCollection(), new TaxRuleCollection(), $quantity);
    }

    private function paidStateId(Context $context): string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('technicalName', OrderTransactionStates::STATE_PAID));
        $criteria->addFilter(new EqualsFilter('stateMachine.technicalName', OrderTransactionStates::STATE_MACHINE));

        $id = $this->stateMachineStateRepository->searchIds($criteria, $context)->firstId();

        if ($id === null) {
            throw new PrintessProductionActionException('The "paid" payment state is missing in this shop.');
        }

        return $id;
    }

    private function loadOrder(string $orderId, Context $context): ?OrderEntity
    {
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('addresses');
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('deliveries.positions');
        $criteria->addAssociation('transactions');
        $criteria->addAssociation('orderCustomer');

        $order = $this->orderRepository->search($criteria, $context)->first();

        return $order instanceof OrderEntity ? $order : null;
    }
}
