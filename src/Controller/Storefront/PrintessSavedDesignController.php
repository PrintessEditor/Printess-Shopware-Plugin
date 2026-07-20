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

namespace PrintessShopwareIntegration\Controller\Storefront;

use PrintessShopwareIntegration\Service\Cart\PrintessCartLineItemService;
use PrintessShopwareIntegration\Service\Configuration\PrintessConfigService;
use PrintessShopwareIntegration\Service\Customer\PrintessCustomerShopUserIdResolver;
use PrintessShopwareIntegration\Service\Product\PrintessProductCustomFieldResolver;
use PrintessShopwareIntegration\Service\Product\ProductConfiguratorGroupNameResolver;
use PrintessShopwareIntegration\Service\Product\ProductOptionFormFieldService;
use PrintessShopwareIntegration\Service\SavedDesigns\PrintessSavedDesignsApiClient;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Resumes a design that was saved to the customer's account via the Printess editor's "Save" dialog
 * while they were still anonymous (see `shopLoginCallback` in `printess-design-now.plugin.js`):
 * the product-page state needed to recreate a basket item (parent/variant product id, configurator
 * selection, page count, price-relevant form fields, plus the save token/thumbnail/display name the
 * editor already handed back) is stashed in the session across the login/registration hand-off, then
 * picked back up here once the customer is actually logged in.
 *
 * Only reachable when `enableDesignSaving` is on - see `PrintessConfigService::isDesignSavingEnabled()`.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID]])]
class PrintessSavedDesignController extends StorefrontController
{
    private const SESSION_KEY = 'printess_pending_saved_design';

    public function __construct(
        private readonly CartService $cartService,
        private readonly PrintessCartLineItemService $cartLineItemService,
        private readonly PrintessConfigService $printessConfigService,
        private readonly PrintessProductCustomFieldResolver $productCustomFieldResolver,
        private readonly ProductConfiguratorGroupNameResolver $configuratorGroupNameResolver,
        private readonly ProductOptionFormFieldService $productOptionFormFieldService,
        private readonly EntityRepository $productRepository,
        private readonly PrintessCustomerShopUserIdResolver $customerShopUserIdResolver,
        private readonly PrintessSavedDesignsApiClient $savedDesignsApiClient,
    ) {
    }

    /**
     * Stashes the pre-login page state, called right before the storefront JS navigates the shopper
     * to the login/register page - see `shopLoginCallback`. Deliberately not login-required: the
     * whole point is to capture state while the shopper is still anonymous.
     */
    #[Route(
        path: '/printess/saved-design/pending',
        name: 'frontend.printess.saved_design.pending.store',
        defaults: ['XmlHttpRequest' => true],
        methods: ['POST'],
    )]
    public function pending(Request $request, SalesChannelContext $context): JsonResponse
    {
        if (!$this->printessConfigService->isDesignSavingEnabled($context->getSalesChannelId())) {
            return new JsonResponse(['error' => 'Design saving is not enabled.'], 400);
        }

        $payload = json_decode((string) $request->getContent(), true);
        $payload = \is_array($payload) ? $payload : [];

        $parentProductId = $payload['parentProductId'] ?? null;
        $variantId = $payload['variantId'] ?? null;
        $saveToken = $payload['saveToken'] ?? null;

        if (!\is_string($parentProductId) || $parentProductId === ''
            || !\is_string($variantId) || $variantId === ''
            || !\is_string($saveToken) || $saveToken === ''
        ) {
            return new JsonResponse(['error' => 'Missing required fields.'], 400);
        }

        $thumbnailUrl = \is_string($payload['thumbnailUrl'] ?? null) ? $payload['thumbnailUrl'] : null;
        $displayName = \is_string($payload['displayName'] ?? null) ? $payload['displayName'] : '';
        $options = \is_array($payload['options'] ?? null) ? array_filter($payload['options'], 'is_string') : [];
        $pageCount = (int) ($payload['pageCount'] ?? 0);
        $priceRelevantFormFields = \is_array($payload['priceRelevantFormFields'] ?? null)
            ? array_filter($payload['priceRelevantFormFields'], 'is_string')
            : [];
        $type = ($payload['type'] ?? null) === 'register' ? 'register' : 'login';

        $request->getSession()->set(self::SESSION_KEY, [
            'parentProductId' => $parentProductId,
            'variantId' => $variantId,
            'saveToken' => $saveToken,
            'thumbnailUrl' => $thumbnailUrl,
            'displayName' => $displayName,
            'options' => $options,
            'pageCount' => $pageCount,
            'priceRelevantFormFields' => $priceRelevantFormFields,
            'type' => $type,
        ]);

        return new JsonResponse(['success' => true]);
    }

    #[Route(
        path: '/account/printess/saved-design/resume',
        name: 'frontend.printess.saved_design.resume',
        defaults: [
            PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true,
        ],
        methods: ['GET'],
    )]
    public function resume(Request $request, SalesChannelContext $context): Response
    {
        $pending = $this->getPendingDesign($request);

        if ($pending === null || !$this->printessConfigService->isDesignSavingEnabled($context->getSalesChannelId())) {
            $this->addFlash(self::DANGER, $this->trans('printess.savedDesignResume.notFoundError'));

            return $this->redirectToRoute('frontend.home.page');
        }

        return $this->renderSavedDesignEditor($pending, $context);
    }

    /**
     * The customer-account listing of every design they've saved, grouped by product - see
     * `PrintessSavedDesignsApiClient` for the Printess API calls this reads from.
     */
    #[Route(
        path: '/account/printess/saved-designs',
        name: 'frontend.account.printess.saved_designs.page',
        defaults: [
            PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true,
        ],
        methods: ['GET'],
    )]
    public function savedDesignsList(SalesChannelContext $context): Response
    {
        $salesChannelId = $context->getSalesChannelId();
        $customer = $context->getCustomer();

        if ($customer === null || $customer->getGuest() || !$this->printessConfigService->isDesignSavingEnabled($salesChannelId)) {
            return $this->redirectToRoute('frontend.account.home.page');
        }

        // Support/debug tool: while an administrator is impersonating this customer ("Log in as
        // customer"), the saved-designs list additionally offers repointing a design's save token -
        // see the `shopData` addition below and `printess-saved-design-admin-editor.plugin.js`.
        $isImpersonating = $context->getImitatingUserId() !== null;

        $shopUserId = $this->customerShopUserIdResolver->resolve($customer, $context->getContext());
        $products = $this->savedDesignsApiClient->loadProducts($salesChannelId, $shopUserId, $salesChannelId);
        $productIds = array_column($products, 'id');
        $designsByProductId = $this->savedDesignsApiClient->loadDesignsForProducts($salesChannelId, $shopUserId, $productIds, $salesChannelId);

        $productGroups = [];

        foreach ($products as $product) {
            $designs = $designsByProductId[$product['id']] ?? [];

            if ($designs === []) {
                continue;
            }

            $productGroups[] = [
                'product' => $product,
                'designs' => array_map(function (array $design) use ($product, $isImpersonating): array {
                    $mapped = [
                        'saveToken' => $design['saveToken'] ?? '',
                        'sortKey' => $design['sortKey'] ?? '',
                        'thumbnailUrl' => $design['thumbnailUrl'] ?? ($product['thumbnailUrl'] ?? null),
                        'displayName' => $design['displayName'] ?? '',
                        'savedOn' => $design['savedOn'] ?? null,
                        'expiresOn' => $design['expiresOn'] ?? null,
                        'editUrl' => $this->generateUrl('frontend.account.printess.saved_designs.edit', [
                            'productId' => $product['id'],
                            'saveToken' => $design['saveToken'] ?? '',
                        ]),
                    ];

                    // Only needed by the admin-only "edit save token" tool - the raw `shopData`
                    // Printess returned for this entry (shopId/shopUserId/product/data), reused
                    // verbatim as the replacement `ISavedShopData.shopData` once repointed to a new
                    // save token (see `printess-saved-design-admin-editor.plugin.js`).
                    if ($isImpersonating) {
                        $mapped['shopData'] = \is_array($design['shopData'] ?? null) ? $design['shopData'] : [];
                    }

                    return $mapped;
                }, $designs),
            ];
        }

        return $this->renderStorefront('@PrintessShopwareIntegration/storefront/page/printess/saved-designs.html.twig', [
            'productGroups' => $productGroups,
            'isImpersonating' => $isImpersonating,
            'loaderScriptUrl' => $this->printessConfigService->getEditorLoaderScriptUrl($salesChannelId),
            'shopToken' => $this->printessConfigService->getShopToken($salesChannelId),
            'shopId' => $salesChannelId,
            'shopUserId' => $shopUserId,
            'deleteSavedDesignUrl' => $this->generateUrl('frontend.account.printess.saved_designs.delete'),
        ]);
    }

    /**
     * Reopens one specific saved design (picked from {@see savedDesignsList()}) in the same full-page editor used
     * by the anonymous-save-then-login hand-off ({@see resume()}) - stashes it under the same session
     * key so {@see resumeUpdate()} works identically for both flows.
     */
    #[Route(
        path: '/account/printess/saved-designs/edit',
        name: 'frontend.account.printess.saved_designs.edit',
        defaults: [
            PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true,
        ],
        methods: ['GET'],
    )]
    public function edit(Request $request, SalesChannelContext $context): Response
    {
        $salesChannelId = $context->getSalesChannelId();
        $customer = $context->getCustomer();
        $productId = (string) $request->query->get('productId', '');
        $saveToken = (string) $request->query->get('saveToken', '');

        if ($customer === null || $customer->getGuest() || $productId === '' || $saveToken === ''
            || !$this->printessConfigService->isDesignSavingEnabled($salesChannelId)
        ) {
            return $this->redirectToRoute('frontend.account.printess.saved_designs.page');
        }

        $shopUserId = $this->customerShopUserIdResolver->resolve($customer, $context->getContext());
        $designs = $this->savedDesignsApiClient->loadDesignsForProduct($salesChannelId, $shopUserId, $productId, $salesChannelId);

        $design = null;

        foreach ($designs as $candidate) {
            if (($candidate['saveToken'] ?? null) === $saveToken) {
                $design = $candidate;
                break;
            }
        }

        if ($design === null) {
            $this->addFlash(self::DANGER, $this->trans('printess.savedDesignResume.notFoundError'));

            return $this->redirectToRoute('frontend.account.printess.saved_designs.page');
        }

        $data = \is_array($design['shopData']['data'] ?? null) ? $design['shopData']['data'] : [];

        $pending = [
            'parentProductId' => \is_string($data['parentProductId'] ?? null) ? $data['parentProductId'] : $productId,
            'variantId' => \is_string($data['variantId'] ?? null) ? $data['variantId'] : $productId,
            'saveToken' => $saveToken,
            'sortKey' => \is_string($design['sortKey'] ?? null) ? $design['sortKey'] : '',
            'thumbnailUrl' => \is_string($design['thumbnailUrl'] ?? null) ? $design['thumbnailUrl'] : null,
            'displayName' => \is_string($design['displayName'] ?? null) ? $design['displayName'] : '',
            'options' => \is_array($data['options'] ?? null) ? array_filter($data['options'], 'is_string') : [],
            'pageCount' => (int) ($data['pageCount'] ?? 0),
            'priceRelevantFormFields' => \is_array($data['priceRelevantFormFields'] ?? null)
                ? array_filter($data['priceRelevantFormFields'], 'is_string')
                : [],
            'type' => 'account',
        ];

        $request->getSession()->set(self::SESSION_KEY, $pending);

        return $this->renderSavedDesignEditor($pending, $context, $this->generateUrl('frontend.account.printess.saved_designs.page'));
    }

    /**
     * Permanently deletes one saved design via Printess's `shop/data/delete` endpoint. That endpoint's
     * payload is just `{sortKey}` - it carries no shop/customer scoping of its own - so ownership is
     * verified here first, the same way {@see edit()} does, by re-fetching this product's saved
     * designs and confirming `sortKey` is actually one of this customer's own before deleting it.
     * Without that check, any logged-in customer could delete an arbitrary sortKey belonging to
     * someone else just by guessing/tampering with the form value.
     */
    #[Route(
        path: '/account/printess/saved-designs/delete',
        name: 'frontend.account.printess.saved_designs.delete',
        defaults: [
            'XmlHttpRequest' => true,
            PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true,
        ],
        methods: ['POST'],
    )]
    public function deleteDesign(Request $request, SalesChannelContext $context): Response
    {
        $salesChannelId = $context->getSalesChannelId();
        $customer = $context->getCustomer();
        $productId = (string) $request->request->get('productId', '');
        $sortKey = (string) $request->request->get('sortKey', '');

        if ($customer === null || $customer->getGuest() || $productId === '' || $sortKey === ''
            || !$this->printessConfigService->isDesignSavingEnabled($salesChannelId)
        ) {
            return $this->redirectToRoute('frontend.account.printess.saved_designs.page');
        }

        $shopUserId = $this->customerShopUserIdResolver->resolve($customer, $context->getContext());
        $belongsToCustomer = $this->designBelongsToCustomer($salesChannelId, $shopUserId, $productId, $sortKey);

        if (!$belongsToCustomer || !$this->savedDesignsApiClient->deleteDesign($sortKey, $salesChannelId)) {
            $this->addFlash(self::DANGER, $this->trans('printess.savedDesigns.deleteError'));

            return $this->redirectToRoute('frontend.account.printess.saved_designs.page');
        }

        $this->addFlash(self::SUCCESS, $this->trans('printess.savedDesigns.deleteSuccess'));

        return $this->redirectToRoute('frontend.account.printess.saved_designs.page');
    }

    /**
     * Renames one saved design via Printess's `shop/data/rename` endpoint - same ownership-verification
     * requirement and reasoning as {@see deleteDesign()}, since that endpoint's payload also carries no
     * shop/customer scoping of its own.
     */
    #[Route(
        path: '/account/printess/saved-designs/rename',
        name: 'frontend.account.printess.saved_designs.rename',
        defaults: [
            'XmlHttpRequest' => true,
            PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true,
        ],
        methods: ['POST'],
    )]
    public function renameDesign(Request $request, SalesChannelContext $context): Response
    {
        $salesChannelId = $context->getSalesChannelId();
        $customer = $context->getCustomer();
        $productId = (string) $request->request->get('productId', '');
        $sortKey = (string) $request->request->get('sortKey', '');
        $displayName = trim((string) $request->request->get('displayName', ''));

        if ($customer === null || $customer->getGuest() || $productId === '' || $sortKey === '' || $displayName === ''
            || !$this->printessConfigService->isDesignSavingEnabled($salesChannelId)
        ) {
            return $this->redirectToRoute('frontend.account.printess.saved_designs.page');
        }

        $shopUserId = $this->customerShopUserIdResolver->resolve($customer, $context->getContext());
        $belongsToCustomer = $this->designBelongsToCustomer($salesChannelId, $shopUserId, $productId, $sortKey);

        if (!$belongsToCustomer || !$this->savedDesignsApiClient->renameDesign($sortKey, $displayName, $salesChannelId)) {
            $this->addFlash(self::DANGER, $this->trans('printess.savedDesigns.renameError'));

            return $this->redirectToRoute('frontend.account.printess.saved_designs.page');
        }

        $this->addFlash(self::SUCCESS, $this->trans('printess.savedDesigns.renameSuccess'));

        return $this->redirectToRoute('frontend.account.printess.saved_designs.page');
    }

    /**
     * Neither `shop/data/delete` nor `shop/data/rename` are scoped to a shop/customer on their own -
     * both take just a bare `sortKey` - so before acting on one, re-fetch this product's saved designs
     * and confirm `sortKey` is actually one of this customer's own. Without this, any logged-in
     * customer could delete/rename an arbitrary sortKey belonging to someone else just by
     * guessing/tampering with the form value.
     */
    private function designBelongsToCustomer(string $salesChannelId, string $shopUserId, string $productId, string $sortKey): bool
    {
        $designs = $this->savedDesignsApiClient->loadDesignsForProduct($salesChannelId, $shopUserId, $productId, $salesChannelId);

        foreach ($designs as $candidate) {
            if (($candidate['sortKey'] ?? null) === $sortKey) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{parentProductId: string, variantId: string, saveToken: string, sortKey?: string, thumbnailUrl: ?string, displayName: string, options: array<string, string>, pageCount: int, priceRelevantFormFields: array<string, string>, type: string} $pending
     * @param string|null $backUrl Where the editor's back/close button navigates to - defaults to the
     * product page (the anonymous-save-then-login hand-off's only sensible "back"), but {@see edit()}
     * overrides this to the saved-designs list, since that's where that flow's customer actually came
     * from.
     */
    private function renderSavedDesignEditor(array $pending, SalesChannelContext $context, ?string $backUrl = null): Response
    {
        $salesChannelId = $context->getSalesChannelId();

        $optionIds = $this->configuratorGroupNameResolver->getConfiguratorOptionIds($pending['parentProductId'], $context->getContext());
        $configuratorOptions = $this->productOptionFormFieldService->getConfiguratorOptionsMapForOptionIds($optionIds);
        $product = $this->loadProduct($pending['parentProductId'], $context);

        $customer = $context->getCustomer();
        $productUrl = $this->generateUrl('frontend.detail.page', ['productId' => $pending['parentProductId']]);

        // Only the "My saved designs" edit flow (`type` is 'account') loaded an existing, already
        // saved design with a known `sortKey` - the anonymous-save-then-login hand-off never has one.
        // Used to offer "also save these changes to your saved design" when the customer adds to
        // basket - see `printess-saved-design-resume.plugin.js`'s `_onAddToBasketRequested()`.
        $canUpdateSavedDesign = ($pending['type'] ?? '') === 'account' && ($pending['sortKey'] ?? '') !== '';

        return $this->renderStorefront('@PrintessShopwareIntegration/storefront/page/printess/saved-design-resume.html.twig', [
            'saveToken' => $pending['saveToken'],
            'displayName' => $pending['displayName'],
            'thumbnailUrl' => $pending['thumbnailUrl'] ?? null,
            'parentProductId' => $pending['parentProductId'],
            'variantId' => $pending['variantId'],
            'configuratorOptions' => $configuratorOptions,
            'currentSelection' => $pending['options'],
            'pendingPageCount' => $pending['pageCount'] ?? 0,
            'pendingPriceRelevantFormFields' => $pending['priceRelevantFormFields'] ?? [],
            // The anonymous-save-then-login hand-off (`type` is 'login'/'register') auto-saved this
            // design to Printess *before* any `shopUserId` existed, so it isn't actually associated
            // with the customer's account yet - `printess-saved-design-resume.plugin.js` calls
            // `saveTemplateToShop` right after load to fix that. Not needed for the "My saved designs"
            // edit flow (`type` is 'account'): that design is already properly associated, or it
            // couldn't have been listed there in the first place.
            'autoSaveToShop' => ($pending['type'] ?? '') !== 'account',
            'canUpdateSavedDesign' => $canUpdateSavedDesign,
            'sortKey' => $pending['sortKey'] ?? '',
            'deleteSavedDesignUrl' => $this->generateUrl('frontend.account.printess.saved_designs.delete'),
            'loaderScriptUrl' => $this->printessConfigService->getEditorLoaderScriptUrl($salesChannelId),
            'shopToken' => $this->printessConfigService->getShopToken($salesChannelId),
            'defaultTheme' => $this->printessConfigService->getDefaultTheme($salesChannelId),
            'editorLanguage' => $this->printessConfigService->getEditorLanguage($salesChannelId),
            'basketThumbnailMaxWidth' => $this->printessConfigService->getBasketThumbnailMaxWidth($salesChannelId),
            'basketThumbnailMaxHeight' => $this->printessConfigService->getBasketThumbnailMaxHeight($salesChannelId),
            'photobookTheme' => $this->productCustomFieldResolver->resolveDefaultLanguage($pending['parentProductId'], 'PrintessPhotobookTheme'),
            'updateUrl' => $this->generateUrl('frontend.printess.saved_design.resume.update'),
            'priceUrl' => $this->generateUrl('frontend.printess.product.price', ['productId' => $pending['parentProductId']]),
            'backUrl' => $backUrl ?? $productUrl,
            'shopId' => $salesChannelId,
            'shopUserId' => $customer !== null && !$customer->getGuest()
                ? $this->customerShopUserIdResolver->resolve($customer, $context->getContext())
                : null,
            'shopUserDisplay' => $customer !== null ? trim($customer->getFirstName() . ' ' . $customer->getLastName()) : null,
            'productDisplayName' => $product?->getName() ?? $pending['displayName'],
            'productThumbnailUrl' => $product?->getCover()?->getMedia()?->getUrl(),
            'productShopUrl' => $productUrl,
        ]);
    }

    private function loadProduct(string $productId, SalesChannelContext $context): ?ProductEntity
    {
        $criteria = new Criteria([$productId]);
        $criteria->addAssociation('cover.media');

        $product = $this->productRepository->search($criteria, $context->getContext())->getEntities()->first();

        return $product instanceof ProductEntity ? $product : null;
    }

    #[Route(
        path: '/account/printess/saved-design/resume',
        name: 'frontend.printess.saved_design.resume.update',
        defaults: [
            'XmlHttpRequest' => true,
            PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true,
        ],
        methods: ['POST'],
    )]
    public function resumeUpdate(Request $request, SalesChannelContext $context): JsonResponse
    {
        $pending = $this->getPendingDesign($request);

        if ($pending === null) {
            return new JsonResponse(['error' => 'No pending design was found.'], 404);
        }

        $body = json_decode((string) $request->getContent(), true);
        $body = \is_array($body) ? $body : [];

        $saveToken = \is_string($body['saveToken'] ?? null) && $body['saveToken'] !== '' ? $body['saveToken'] : $pending['saveToken'];
        $thumbnailUrl = \is_string($body['thumbnailUrl'] ?? null) ? $body['thumbnailUrl'] : $pending['thumbnailUrl'];
        $options = \is_array($body['options'] ?? null) ? array_filter($body['options'], 'is_string') : $pending['options'];
        $pageCount = isset($body['pageCount']) ? (int) $body['pageCount'] : $pending['pageCount'];
        $priceRelevantFormFields = \is_array($body['priceRelevantFormFields'] ?? null)
            ? array_filter($body['priceRelevantFormFields'], 'is_string')
            : $pending['priceRelevantFormFields'];

        $cart = $this->cartService->getCart($context->getToken(), $context);

        $this->cartLineItemService->addLineItemFromPendingDesign(
            $cart,
            $pending['variantId'],
            $saveToken,
            $thumbnailUrl,
            $options,
            $pageCount,
            $priceRelevantFormFields,
            $context,
        );

        $request->getSession()->remove(self::SESSION_KEY);

        return new JsonResponse([
            'redirectUrl' => $this->generateUrl('frontend.checkout.cart.page'),
        ]);
    }

    /**
     * @return array{parentProductId: string, variantId: string, saveToken: string, sortKey?: string, thumbnailUrl: ?string, displayName: string, options: array<string, string>, pageCount: int, priceRelevantFormFields: array<string, string>, type: string}|null
     */
    private function getPendingDesign(Request $request): ?array
    {
        $pending = $request->getSession()->get(self::SESSION_KEY);

        return \is_array($pending) ? $pending : null;
    }
}
