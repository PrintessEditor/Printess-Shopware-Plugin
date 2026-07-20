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

namespace PrintessShopwareIntegration\Service\SavedDesigns;

use PrintessShopwareIntegration\Service\Configuration\PrintessConfigService;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Reads back the designs a customer has saved to their account from Printess's own storage (see
 * `getShopDataCallback` in `printess-design-now.plugin.js`, which is what originally wrote them there).
 * Shop-scoped endpoints, authenticated with the shop token - not the service token used by
 * `PrintessProductionService`/`PrintessTemplateController` for unrelated production/template endpoints.
 */
class PrintessSavedDesignsApiClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly PrintessConfigService $printessConfigService,
    ) {
    }

    /**
     * The list of products this customer has at least one saved design for.
     *
     * @return list<array{id: string, displayName: string, thumbnailUrl: string, shopUrl: string}>
     */
    public function loadProducts(string $shopId, string $shopUserId, ?string $salesChannelId = null): array
    {
        $response = $this->sendRequest('shop/products/load', [
            'shopId' => $shopId,
            'shopUserId' => $shopUserId,
        ], $salesChannelId);

        return $this->readEntries($response);
    }

    /**
     * Every saved design for a single product. `sortKey` is this saved-list row's own deletion id
     * (see {@see deleteDesign()}) - not to be confused with `saveToken`, which identifies the design's
     * content/version.
     *
     * @return list<array{shopData: array{shopId: string, shopUserId: string, product: array<string, mixed>, data: array<string, mixed>}, displayName: string, saveToken: string, sortKey: string, thumbnailUrl: string, savedOn: ?string, expiresOn: ?string}>
     */
    public function loadDesignsForProduct(string $shopId, string $shopUserId, string $productId, ?string $salesChannelId = null): array
    {
        $response = $this->sendRequest('shop/data/load', [
            'shopId' => $shopId,
            'shopUserId' => $shopUserId,
            'productId' => $productId,
        ], $salesChannelId);

        return $this->readEntries($response);
    }

    /**
     * Kicks off `loadDesignsForProduct()` for several products without waiting for each response in
     * turn - Symfony's `HttpClientInterface` multiplexes requests as long as none of them is read
     * (`toArray()`) before the rest have been dispatched, so this fetches all of them concurrently
     * rather than one round trip at a time.
     *
     * @param list<string> $productIds
     * @return array<string, list<array<string, mixed>>> keyed by product id
     */
    public function loadDesignsForProducts(string $shopId, string $shopUserId, array $productIds, ?string $salesChannelId = null): array
    {
        $responses = [];

        foreach ($productIds as $productId) {
            $responses[$productId] = $this->sendRequest('shop/data/load', [
                'shopId' => $shopId,
                'shopUserId' => $shopUserId,
                'productId' => $productId,
            ], $salesChannelId);
        }

        $designsByProductId = [];

        foreach ($responses as $productId => $response) {
            $designsByProductId[$productId] = $this->readEntries($response);
        }

        return $designsByProductId;
    }

    /**
     * Permanently deletes one saved design. `sortKey` (returned alongside every entry from
     * {@see loadDesignsForProduct()}) is Printess's own deletion id for that entry - distinct from
     * `saveToken`, which identifies the design's content/version, not this particular saved-list row.
     */
    public function deleteDesign(string $sortKey, ?string $salesChannelId = null): bool
    {
        $response = $this->sendRequest('shop/data/delete', [
            'sortKey' => $sortKey,
        ], $salesChannelId);

        if ($response === null) {
            return false;
        }

        try {
            return $response->getStatusCode() < 300;
        } catch (TransportExceptionInterface) {
            return false;
        }
    }

    /**
     * Renames one saved design - only its display name, not any of its actual design content.
     */
    public function renameDesign(string $sortKey, string $displayName, ?string $salesChannelId = null): bool
    {
        $response = $this->sendRequest('shop/data/rename', [
            'sortKey' => $sortKey,
            'displayName' => $displayName,
        ], $salesChannelId);

        if ($response === null) {
            return false;
        }

        try {
            return $response->getStatusCode() < 300;
        } catch (TransportExceptionInterface) {
            return false;
        }
    }

    private function sendRequest(string $path, array $body, ?string $salesChannelId): ?ResponseInterface
    {
        $shopToken = $this->printessConfigService->getShopToken($salesChannelId);

        if ($shopToken === '') {
            return null;
        }

        try {
            return $this->httpClient->request(
                'POST',
                $this->printessConfigService->getApiUrl($salesChannelId) . '/' . $path,
                [
                    'auth_bearer' => $shopToken,
                    'json' => $body,
                ]
            );
        } catch (TransportExceptionInterface) {
            return null;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readEntries(?ResponseInterface $response): array
    {
        if ($response === null) {
            return [];
        }

        try {
            $data = $response->toArray(false);
        } catch (TransportExceptionInterface|\JsonException) {
            return [];
        }

        return \is_array($data['entries'] ?? null) ? array_values($data['entries']) : [];
    }
}
