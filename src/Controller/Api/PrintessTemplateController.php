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

namespace PrintessShopwareIntegration\Controller\Api;

use PrintessShopwareIntegration\Service\Configuration\PrintessConfigService;
use PrintessShopwareIntegration\Service\Product\PrintessProductCustomFieldResolver;
use PrintessShopwareIntegration\Service\Product\PrintessProductPropertyResolver;
use PrintessShopwareIntegration\Service\Product\ProductConfiguratorGroupNameResolver;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
class PrintessTemplateController extends AbstractController
{
    /**
     * This layout snippet's `uid` is known to be a global snippet even though Printess's API does not set
     * `igs` (is-global-snippet) for it. Forcing `isGlobal` for this uid ensures its merge template reference
     * gets the `~gid` suffix it needs.
     */
    private const FORCE_GLOBAL_LAYOUT_SNIPPET_UID = 'gy8z44EmLiZ0vrUKrXlWtiZY15D2';

    public function __construct(
        private readonly PrintessConfigService $printessConfigService,
        private readonly HttpClientInterface $httpClient,
        private readonly ProductConfiguratorGroupNameResolver $productConfiguratorGroupNameResolver,
        private readonly PrintessProductCustomFieldResolver $productCustomFieldResolver,
        private readonly PrintessProductPropertyResolver $productPropertyResolver,
    ) {
    }

    #[Route(path: '/api/_action/printess/config-status', name: 'api.action.printess.config_status', methods: ['GET'])]
    public function configStatus(): JsonResponse
    {
        return new JsonResponse([
            'hasServiceToken' => $this->printessConfigService->getServiceToken() !== '',
            'hasShopToken' => $this->printessConfigService->getShopToken() !== '',
            'perLanguageProductSettingsEnabled' => $this->printessConfigService->isPerLanguageProductSettingsEnabled(),
        ]);
    }

    /**
     * Used by the product's Printess tab when the `perLanguageProductSettings` setting is off, to
     * show/edit "the" value for these fields regardless of which admin language tab is active. See
     * `PrintessProductCustomFieldResolver`.
     */
    #[Route(
        path: '/api/_action/printess/products/{productId}/default-language-settings',
        name: 'api.action.printess.product.default_language_settings.get',
        methods: ['GET'],
    )]
    public function getDefaultLanguageSettings(string $productId): JsonResponse
    {
        $values = [];

        foreach (PrintessProductCustomFieldResolver::MANAGED_FIELD_NAMES as $fieldName) {
            $values[$fieldName] = $this->productCustomFieldResolver->resolveDefaultLanguage($productId, $fieldName);
        }

        return new JsonResponse($values);
    }

    #[Route(
        path: '/api/_action/printess/products/{productId}/default-language-settings',
        name: 'api.action.printess.product.default_language_settings.set',
        methods: ['POST'],
    )]
    public function setDefaultLanguageSettings(string $productId, Request $request): JsonResponse
    {
        $payload = json_decode((string) $request->getContent(), true);
        $payload = \is_array($payload) ? $payload : [];

        $values = array_intersect_key($payload, array_flip(PrintessProductCustomFieldResolver::MANAGED_FIELD_NAMES));

        if ($values === []) {
            return new JsonResponse(['error' => 'No recognized fields provided.'], 400);
        }

        $this->productCustomFieldResolver->saveDefaultLanguage($productId, $values);

        return new JsonResponse(['success' => true]);
    }

    /**
     * Everything the admin's Printess editor integration (`sw-order-line-item-printess-editor`)
     * needs to load the buyer-facing editor loader script itself - the same values already exposed
     * to storefront templates via `PrintessExtension`, just as JSON for a Vue component instead of
     * Twig globals. The shop token is safe to expose here: it's the same token already embedded in
     * public storefront HTML for the buyer-facing editor, as opposed to the service token, which is
     * never sent to any frontend.
     *
     * `productId` is optional (a line item being edited always has one, but nothing else here
     * requires it) - when given, `photobookTheme` is resolved for it, to push as a "PHOTOBOOK_THEME"
     * form field on this page too, same as every other page that loads the editor.
     */
    #[Route(path: '/api/_action/printess/editor-config', name: 'api.action.printess.editor_config', methods: ['GET'])]
    public function editorConfig(Request $request): JsonResponse
    {
        $salesChannelId = $request->query->get('salesChannelId');
        $salesChannelId = \is_string($salesChannelId) && $salesChannelId !== '' ? $salesChannelId : null;

        $productId = $request->query->get('productId');
        $productId = \is_string($productId) && $productId !== '' ? $productId : null;

        return new JsonResponse([
            'loaderScriptUrl' => $this->printessConfigService->getEditorLoaderScriptUrl($salesChannelId),
            'shopToken' => $this->printessConfigService->getShopToken($salesChannelId),
            'defaultTheme' => $this->printessConfigService->getDefaultTheme($salesChannelId),
            'editorLanguage' => $this->printessConfigService->getEditorLanguage($salesChannelId),
            'basketThumbnailMaxWidth' => $this->printessConfigService->getBasketThumbnailMaxWidth($salesChannelId),
            'basketThumbnailMaxHeight' => $this->printessConfigService->getBasketThumbnailMaxHeight($salesChannelId),
            'photobookTheme' => $productId !== null
                ? $this->productCustomFieldResolver->resolveDefaultLanguage($productId, 'PrintessPhotobookTheme')
                : null,
        ]);
    }

    #[Route(
        path: '/api/_action/printess/products/{productId}/configurator-group-names',
        name: 'api.action.printess.product.configurator_group_names',
        methods: ['GET'],
    )]
    public function configuratorGroupNames(string $productId, Context $context): JsonResponse
    {
        return new JsonResponse([
            'groupNames' => $this->productConfiguratorGroupNameResolver->getGroupNames($productId, $context),
        ]);
    }

    #[Route(
        path: '/api/_action/printess/products/{productId}/property-group-options',
        name: 'api.action.printess.product.property_group_options',
        methods: ['GET'],
    )]
    public function propertyGroupOptions(string $productId, Context $context): JsonResponse
    {
        return new JsonResponse([
            'propertyGroups' => $this->productPropertyResolver->getPropertyGroupOptions($productId, $context),
        ]);
    }

    #[Route(path: '/api/_action/printess/templates', name: 'api.action.printess.templates', methods: ['POST'])]
    public function list(): JsonResponse
    {
        $serviceToken = $this->printessConfigService->getServiceToken();

        if ($serviceToken === '') {
            return new JsonResponse([
                'templates' => [],
                'error' => 'The Printess service token is not configured.',
            ], 400);
        }

        try {
            $response = $this->httpClient->request(
                'POST',
                $this->printessConfigService->getApiUrl() . '/templates/list',
                [
                    'auth_bearer' => $serviceToken,
                    'json' => [],
                ]
            );

            $data = $response->toArray(false);
        } catch (TransportExceptionInterface|\JsonException $exception) {
            return new JsonResponse([
                'templates' => [],
                'error' => 'Could not load templates from Printess: ' . $exception->getMessage(),
            ], 502);
        }

        return new JsonResponse([
            'templates' => $this->normalizeTemplates($data),
        ]);
    }

    #[Route(path: '/api/_action/printess/print-settings', name: 'api.action.printess.print_settings', methods: ['GET'])]
    public function printSettings(): JsonResponse
    {
        $serviceToken = $this->printessConfigService->getServiceToken();

        if ($serviceToken === '') {
            return new JsonResponse([
                'printSettings' => [],
                'error' => 'The Printess service token is not configured.',
            ], 400);
        }

        try {
            $response = $this->httpClient->request(
                'POST',
                $this->printessConfigService->getApiUrl() . '/user/settings/read',
                [
                    'auth_bearer' => $serviceToken,
                    'json' => ['keys' => ['print-settings-list']],
                ]
            );

            $data = $response->toArray(false);
        } catch (TransportExceptionInterface|\JsonException $exception) {
            return new JsonResponse([
                'printSettings' => [],
                'error' => 'Could not load print settings from Printess: ' . $exception->getMessage(),
            ], 502);
        }

        return new JsonResponse([
            'printSettings' => $this->normalizePrintSettingsList($data),
        ]);
    }

    #[Route(path: '/api/_action/printess/dropship-product-definitions', name: 'api.action.printess.dropship_product_definitions', methods: ['GET'])]
    public function dropshipProductDefinitions(): JsonResponse
    {
        $serviceToken = $this->printessConfigService->getServiceToken();

        if ($serviceToken === '') {
            return new JsonResponse([
                'productDefinitions' => [],
                'error' => 'The Printess service token is not configured.',
            ], 400);
        }

        try {
            $response = $this->httpClient->request(
                'POST',
                $this->printessConfigService->getApiUrl() . '/productDefinitions/load',
                [
                    'auth_bearer' => $serviceToken,
                    // An empty PHP array would json_encode to `[]`, but this endpoint requires a JSON
                    // object body (`{}`) and 400s on an array - verified empirically.
                    'json' => (object) [],
                ]
            );

            $data = $response->toArray(false);
        } catch (TransportExceptionInterface|\JsonException $exception) {
            return new JsonResponse([
                'productDefinitions' => [],
                'error' => 'Could not load dropship product definitions from Printess: ' . $exception->getMessage(),
            ], 502);
        }

        return new JsonResponse([
            'productDefinitions' => $this->normalizeDropshipProductDefinitions($data),
        ]);
    }

    #[Route(path: '/api/_action/printess/photobook-themes', name: 'api.action.printess.photobook_themes', methods: ['GET'])]
    public function photobookThemes(): JsonResponse
    {
        $serviceToken = $this->printessConfigService->getServiceToken();

        if ($serviceToken === '') {
            return new JsonResponse([
                'themes' => [],
                'error' => 'The Printess service token is not configured.',
            ], 400);
        }

        try {
            $response = $this->httpClient->request(
                'POST',
                $this->printessConfigService->getApiUrl() . '/user/settings/read',
                [
                    'auth_bearer' => $serviceToken,
                    'json' => ['keys' => ['magicPhotobookThemes']],
                ]
            );

            $data = $response->toArray(false);
        } catch (TransportExceptionInterface|\JsonException $exception) {
            return new JsonResponse([
                'themes' => [],
                'error' => 'Could not load photobook themes from Printess: ' . $exception->getMessage(),
            ], 502);
        }

        return new JsonResponse([
            'themes' => $this->normalizePhotobookThemes($data),
        ]);
    }

    #[Route(path: '/api/_action/printess/impositions', name: 'api.action.printess.impositions', methods: ['GET'])]
    public function impositions(): JsonResponse
    {
        $serviceToken = $this->printessConfigService->getServiceToken();

        if ($serviceToken === '') {
            return new JsonResponse([
                'impositions' => [],
                'error' => 'The Printess service token is not configured.',
            ], 400);
        }

        try {
            $response = $this->httpClient->request(
                'POST',
                $this->printessConfigService->getApiUrl() . '/user/settings/read',
                [
                    'auth_bearer' => $serviceToken,
                    'json' => ['keys' => ['impositions']],
                ]
            );

            $data = $response->toArray(false);
        } catch (TransportExceptionInterface|\JsonException $exception) {
            return new JsonResponse([
                'impositions' => [],
                'error' => 'Could not load impositions from Printess: ' . $exception->getMessage(),
            ], 502);
        }

        return new JsonResponse([
            'impositions' => $this->normalizeImpositions($data),
        ]);
    }

    #[Route(path: '/api/_action/printess/layout-snippet-tags', name: 'api.action.printess.layout_snippet_tags', methods: ['GET'])]
    public function layoutSnippetTags(): JsonResponse
    {
        $serviceToken = $this->printessConfigService->getServiceToken();

        if ($serviceToken === '') {
            return new JsonResponse([
                'tags' => [],
                'error' => 'The Printess service token is not configured.',
            ], 400);
        }

        try {
            $response = $this->httpClient->request(
                'POST',
                $this->printessConfigService->getApiUrl() . '/snippets/tags/load',
                [
                    'auth_bearer' => $serviceToken,
                    'json' => ['includeGlobal' => true],
                ]
            );

            $data = $response->toArray(false);
        } catch (TransportExceptionInterface|\JsonException $exception) {
            return new JsonResponse([
                'tags' => [],
                'error' => 'Could not load layout snippet tags from Printess: ' . $exception->getMessage(),
            ], 502);
        }

        return new JsonResponse([
            'tags' => $this->normalizeLayoutSnippetTags($data),
        ]);
    }

    #[Route(path: '/api/_action/printess/layout-snippets/search', name: 'api.action.printess.layout_snippets.search', methods: ['POST'])]
    public function searchLayoutSnippets(Request $request): JsonResponse
    {
        $serviceToken = $this->printessConfigService->getServiceToken();

        if ($serviceToken === '') {
            return new JsonResponse([
                'layoutSnippets' => [],
                'error' => 'The Printess service token is not configured.',
            ], 400);
        }

        $payload = json_decode((string) $request->getContent(), true) ?? [];
        $tags = \is_array($payload['tags'] ?? null) ? array_values(array_filter($payload['tags'], 'is_string')) : [];
        $keywords = \is_array($payload['keywords'] ?? null) ? array_values(array_filter($payload['keywords'], 'is_string')) : [];

        if ($tags === []) {
            return new JsonResponse(['layoutSnippets' => []]);
        }

        try {
            $response = $this->httpClient->request(
                'POST',
                $this->printessConfigService->getApiUrl() . '/layoutSnippets/load',
                [
                    'auth_bearer' => $serviceToken,
                    'json' => [
                        'tags' => $tags,
                        'keywords' => $keywords,
                        'includeGlobal' => true,
                        'productType' => '*',
                    ],
                ]
            );

            $data = $response->toArray(false);
        } catch (TransportExceptionInterface|\JsonException $exception) {
            return new JsonResponse([
                'layoutSnippets' => [],
                'error' => 'Could not search layout snippets on Printess: ' . $exception->getMessage(),
            ], 502);
        }

        return new JsonResponse([
            'layoutSnippets' => $this->normalizeLayoutSnippets($data),
        ]);
    }

    #[Route(path: '/api/_action/printess/layout-snippets/by-id', name: 'api.action.printess.layout_snippets.by_id', methods: ['POST'])]
    public function layoutSnippetsById(Request $request): JsonResponse
    {
        $serviceToken = $this->printessConfigService->getServiceToken();

        if ($serviceToken === '') {
            return new JsonResponse([
                'layoutSnippets' => [],
                'error' => 'The Printess service token is not configured.',
            ], 400);
        }

        $payload = json_decode((string) $request->getContent(), true) ?? [];
        $ids = \is_array($payload['ids'] ?? null) ? array_values(array_filter($payload['ids'], 'is_string')) : [];

        if ($ids === []) {
            return new JsonResponse(['layoutSnippets' => []]);
        }

        try {
            $response = $this->httpClient->request(
                'POST',
                $this->printessConfigService->getApiUrl() . '/layoutSnippets/loadbyid',
                [
                    'auth_bearer' => $serviceToken,
                    'json' => ['ids' => $ids],
                ]
            );

            $data = $response->toArray(false);
        } catch (TransportExceptionInterface|\JsonException $exception) {
            return new JsonResponse([
                'layoutSnippets' => [],
                'error' => 'Could not resolve layout snippets on Printess: ' . $exception->getMessage(),
            ], 502);
        }

        return new JsonResponse([
            'layoutSnippets' => $this->normalizeLayoutSnippets($data),
        ]);
    }

    /**
     * `/user/settings/read` returns a map keyed by requested key, where each value is itself a JSON-encoded
     * string (not a nested JSON structure) - e.g. `{"print-settings-list": "[\"Setting A\",\"Setting B\"]"}`.
     *
     * @param array<mixed> $data
     *
     * @return list<string>
     */
    private function normalizePrintSettingsList(array $data): array
    {
        $raw = $data['print-settings-list'] ?? null;

        if (!\is_string($raw) || $raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    /**
     * `/user/settings/read` returns a map keyed by requested key, where each value is itself a JSON-encoded
     * string - here a list of `{n: name, t: thumbnailUrl}` entries.
     *
     * @param array<mixed> $data
     *
     * @return list<array{name: string, thumbnailUrl: string|null}>
     */
    private function normalizePhotobookThemes(array $data): array
    {
        $raw = $data['magicPhotobookThemes'] ?? null;

        if (!\is_string($raw) || $raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!\is_array($decoded)) {
            return [];
        }

        $themes = [];

        foreach ($decoded as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $name = $item['n'] ?? null;

            if (!\is_string($name) || $name === '') {
                continue;
            }

            $thumbnailUrl = $item['t'] ?? null;

            $themes[] = [
                'name' => $name,
                'thumbnailUrl' => \is_string($thumbnailUrl) && $thumbnailUrl !== '' ? $thumbnailUrl : null,
            ];
        }

        return $themes;
    }

    /**
     * `/user/settings/read` returns a map keyed by requested key, where each value is itself a JSON-encoded
     * string - here a list of `{name, svg}` entries. `svg` is raw inline SVG markup (not a URL), so it's
     * converted into a data URI here rather than passed through as-is: rendered via an `<img>` tag on the
     * admin side, a data URI is safe (an `<img>` never executes scripts/event handlers embedded in the SVG,
     * unlike e.g. `v-html`).
     *
     * @param array<mixed> $data
     *
     * @return list<array{name: string, thumbnailUrl: string|null}>
     */
    private function normalizeImpositions(array $data): array
    {
        $raw = $data['impositions'] ?? null;

        if (!\is_string($raw) || $raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!\is_array($decoded)) {
            return [];
        }

        $impositions = [];

        foreach ($decoded as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $name = $item['name'] ?? null;

            if (!\is_string($name) || $name === '') {
                continue;
            }

            $svg = $item['svg'] ?? null;

            $impositions[] = [
                'name' => $name,
                'thumbnailUrl' => \is_string($svg) && $svg !== ''
                    ? 'data:image/svg+xml;base64,' . base64_encode($svg)
                    : null,
            ];
        }

        return $impositions;
    }

    /**
     * `/productDefinitions/load` returns both the product definitions and the full dropshippers list they
     * belong to in one response (verified empirically to be identical to what `/dropshippers/load` alone
     * returns), so that single call is enough - no separate dropshippers request is needed. The dropshipper
     * name is kept as its own field (rather than merged into the definition's label) so the admin can group
     * definitions by dropshipper instead of just prefixing the label with it.
     *
     * @param array<mixed> $data
     *
     * @return list<array{id: int, label: string, dropshipperName: string|null}>
     */
    private function normalizeDropshipProductDefinitions(array $data): array
    {
        $dropshipperNamesById = [];

        if (\is_array($data['dropshippers'] ?? null)) {
            foreach ($data['dropshippers'] as $dropshipper) {
                if (\is_array($dropshipper) && isset($dropshipper['id'], $dropshipper['display'])) {
                    $dropshipperNamesById[(int) $dropshipper['id']] = (string) $dropshipper['display'];
                }
            }
        }

        $definitions = [];

        if (\is_array($data['productDefinitions'] ?? null)) {
            foreach ($data['productDefinitions'] as $definition) {
                if (!\is_array($definition) || !isset($definition['id'])) {
                    continue;
                }

                $dropshipperName = $dropshipperNamesById[(int) ($definition['dropshipperId'] ?? 0)] ?? null;

                $definitions[] = [
                    'id' => (int) $definition['id'],
                    'label' => (string) ($definition['display'] ?? $definition['id']),
                    'dropshipperName' => $dropshipperName,
                ];
            }
        }

        return $definitions;
    }

    /**
     * `/snippets/tags/load` returns a bare JSON array of `{tag, layout, group}`, where `layout` is the number
     * of layout snippets carrying that tag. Only tags with at least one layout snippet are worth offering in
     * the layout-snippet picker (a tag with `layout: 0` only tags other snippet types and would always yield
     * an empty result from `/layoutSnippets/load`). `isGlobal` mirrors the client convention: a tag name
     * prefixed with "printess-" is one of Printess's own shared/global tags.
     *
     * @param array<mixed> $data
     *
     * @return list<array{tag: string, isGlobal: bool}>
     */
    private function normalizeLayoutSnippetTags(array $data): array
    {
        $items = array_is_list($data) ? $data : [];

        $tags = [];

        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $tag = $item['tag'] ?? null;

            if (!\is_string($tag) || $tag === '') {
                continue;
            }

            if ((int) ($item['layout'] ?? 0) <= 0) {
                continue;
            }

            $tags[] = [
                'tag' => $tag,
                'isGlobal' => str_starts_with($tag, 'printess-'),
            ];
        }

        return $tags;
    }

    /**
     * `/layoutSnippets/load` and `/layoutSnippets/loadbyid` both return a bare JSON array of snippet DTOs
     * (unlike `/templates/list`, which nests its results). Relevant fields: `title` = name, `iurl` = thumbnail
     * url, `id`, `igs` = is-global-snippet flag, `layoutKeywords` = the snippet's own keywords (merged with any
     * `meta.keywords`, since `meta` is a JSON-encoded string that historically carried keywords too).
     *
     * @param array<mixed> $data
     *
     * @return list<array{id: string, name: string, thumbnailUrl: string|null, isGlobal: bool, keywords: list<string>}>
     */
    private function normalizeLayoutSnippets(array $data): array
    {
        $items = array_is_list($data) ? $data : [];

        $snippets = [];

        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $id = $item['id'] ?? null;

            if (!\is_string($id) || $id === '') {
                continue;
            }

            $name = $item['title'] ?? $item['name'] ?? null;
            $thumbnailUrl = $item['iurl'] ?? $item['thumbnailUrl'] ?? null;
            $uid = $item['uid'] ?? null;
            $isGlobal = (bool) ($item['igs'] ?? false);

            if (\is_string($uid) && $uid === self::FORCE_GLOBAL_LAYOUT_SNIPPET_UID) {
                $isGlobal = true;
            }

            $snippets[] = [
                'id' => $id,
                'name' => \is_string($name) && $name !== '' ? $name : $id,
                'thumbnailUrl' => \is_string($thumbnailUrl) && $thumbnailUrl !== '' ? $thumbnailUrl : null,
                'isGlobal' => $isGlobal,
                'keywords' => $this->extractLayoutSnippetKeywords($item),
            ];
        }

        return $snippets;
    }

    /**
     * @param array<mixed> $item
     *
     * @return list<string>
     */
    private function extractLayoutSnippetKeywords(array $item): array
    {
        $keywords = [];

        if (\is_array($item['layoutKeywords'] ?? null)) {
            array_push($keywords, ...array_filter($item['layoutKeywords'], 'is_string'));
        }

        if (\is_string($item['meta'] ?? null)) {
            $meta = json_decode($item['meta'], true);

            if (\is_array($meta) && \is_array($meta['keywords'] ?? null)) {
                array_push($keywords, ...array_filter($meta['keywords'], 'is_string'));
            }
        }

        return array_values(array_unique($keywords));
    }

    /**
     * The `/templates/list` endpoint is undocumented in the Printess Swagger spec. Its actual response nests
     * the template list per user: `{ uts: [ { uid, e, ts: [TemplateDto, ...] }, ... ] }`, where `TemplateDto`
     * has `n` = name and `turl` = thumbnail url. This flattens `ts` across all `uts` entries, with a few
     * defensive fallbacks in case a differently-shaped response is ever returned.
     *
     * @param array<mixed> $data
     *
     * @return list<array{name: string, thumbnailUrl: string|null, directoryId: int|null}>
     */
    private function normalizeTemplates(array $data): array
    {
        $items = [];

        if (\is_array($data['uts'] ?? null)) {
            foreach ($data['uts'] as $userTemplates) {
                if (\is_array($userTemplates) && \is_array($userTemplates['ts'] ?? null)) {
                    array_push($items, ...$userTemplates['ts']);
                }
            }
        } elseif (\is_array($data['ts'] ?? null)) {
            $items = $data['ts'];
        } elseif (\is_array($data['templates'] ?? null)) {
            $items = $data['templates'];
        } elseif (\is_array($data['items'] ?? null)) {
            $items = $data['items'];
        } elseif (array_is_list($data)) {
            $items = $data;
        }

        $templates = [];

        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $name = $item['n'] ?? $item['name'] ?? null;

            if (!\is_string($name) || $name === '') {
                continue;
            }

            $thumbnailUrl = $item['turl'] ?? $item['thumbnailUrl'] ?? null;

            $templates[] = [
                'name' => $name,
                'thumbnailUrl' => \is_string($thumbnailUrl) && $thumbnailUrl !== '' ? $thumbnailUrl : null,
                'directoryId' => \is_int($item['directoryId'] ?? null) ? $item['directoryId'] : null,
            ];
        }

        return $templates;
    }
}
