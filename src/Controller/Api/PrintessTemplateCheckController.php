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

use PrintessShopwareIntegration\Service\TemplateCheck\PrintessFormFieldsClient;
use PrintessShopwareIntegration\Service\TemplateCheck\PrintessFormFieldsException;
use PrintessShopwareIntegration\Service\TemplateCheck\ProductVariantStateReader;
use PrintessShopwareIntegration\Service\TemplateCheck\TemplateCheckService;
use PrintessShopwareIntegration\Service\TemplateCheck\VariantSyncApplier;
use PrintessShopwareIntegration\Service\TemplateCheck\VariantSyncPlanner;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin endpoints of the product tab's "Template check" card and its "Build variants from the
 * template" dialog. Both always read the template past any cache and the product from what is
 * saved, since the merchant typically has just changed one of them.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
class PrintessTemplateCheckController extends AbstractController
{
    public function __construct(
        private readonly PrintessFormFieldsClient $formFieldsClient,
        private readonly ProductVariantStateReader $stateReader,
        private readonly TemplateCheckService $templateCheckService,
        private readonly VariantSyncPlanner $variantSyncPlanner,
        private readonly VariantSyncApplier $variantSyncApplier,
    ) {
    }

    #[Route(
        path: '/api/_action/printess/products/{productId}/template-check',
        name: 'api.action.printess.product.template_check',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['product:read']],
        methods: ['POST'],
    )]
    public function check(string $productId, Request $request): JsonResponse
    {
        $templateName = trim((string) $request->request->get('templateName', ''));

        if ($templateName === '') {
            return new JsonResponse(['error' => 'No template is set for this product.'], 400);
        }

        $state = $this->stateReader->read($productId);

        if ($state === null) {
            return new JsonResponse(['error' => 'This product no longer exists.'], 404);
        }

        try {
            $fields = $this->formFieldsClient->load($templateName);
        } catch (PrintessFormFieldsException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 502);
        }

        return new JsonResponse([
            'groups' => $this->templateCheckService->check($fields, $state['groups']),
            'isVariant' => $state['isVariant'],
            'checkedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ]);
    }

    /**
     * Preview (`apply: false`) and apply (`apply: true`) take the same choices and compute the
     * same plan; the browser never sends a list of changes.
     */
    #[Route(
        path: '/api/_action/printess/products/{productId}/variant-sync',
        name: 'api.action.printess.product.variant_sync',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['product:update']],
        methods: ['POST'],
    )]
    public function sync(string $productId, Request $request, Context $context): JsonResponse
    {
        $templateName = trim((string) $request->request->get('templateName', ''));
        $payload = $request->request->all();
        $selected = \is_array($payload['fields'] ?? null) ? array_values(array_map('strval', $payload['fields'])) : [];
        $keep = \is_array($payload['keep'] ?? null) ? $payload['keep'] : [];
        $apply = !empty($payload['apply']);

        if ($templateName === '') {
            return new JsonResponse(['error' => 'No template is set for this product.'], 400);
        }

        $state = $this->stateReader->read($productId);

        if ($state === null) {
            return new JsonResponse(['error' => 'This product no longer exists.'], 404);
        }

        if ($state['isVariant']) {
            return new JsonResponse(['error' => 'Variants are built on the parent product. Open the parent product to build its variants from the template.'], 400);
        }

        try {
            $fields = $this->formFieldsClient->load($templateName);
        } catch (PrintessFormFieldsException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 502);
        }

        $sharedGroups = $this->stateReader->findSharedGroups(array_merge(
            array_column($fields, 'name'),
            array_column($fields, 'label'),
        ));
        $plan = $this->variantSyncPlanner->plan($fields, $state, $sharedGroups, $selected, $keep);

        if (!$apply) {
            return new JsonResponse($plan['public']);
        }

        if ($selected === []) {
            return new JsonResponse(['error' => 'Choose at least one template field to build the variants from.'], 400);
        }

        try {
            $applied = $this->variantSyncApplier->apply($plan['internal'], $context);
        } catch (\Throwable $exception) {
            return new JsonResponse(['error' => 'Nothing was changed, because the variants could not be saved: ' . $exception->getMessage()], 500);
        }

        return new JsonResponse(['applied' => $applied]);
    }
}
