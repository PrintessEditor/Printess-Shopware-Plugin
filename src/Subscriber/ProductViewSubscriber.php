<?php declare(strict_types=1);

namespace PrintessEditor\Subscriber;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Content\Cms\SalesChannel\Struct\TextStruct;
use PrintessEditor\Core\Content\PrintessEditor\SalesChannel\PrintessProductInfoRoute;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Checkout\Offcanvas\OffcanvasCartPageLoadedEvent;

class ProductViewSubscriber implements EventSubscriberInterface
{
    private PrintessProductInfoRoute $productInfoRoute;

    public function __construct(PrintessProductInfoRoute $productInfoRoute)
    {
        $this->productInfoRoute = $productInfoRoute;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductPageLoadedEvent::class         => ['productPageLoaded'],
            CheckoutCartPageLoadedEvent::class    => ['cartPageLoaded'],
            OffcanvasCartPageLoadedEvent::class   => ['offCanvasCartLoaded'],
            CheckoutConfirmPageLoadedEvent::class => ['checkoutConfirmPageLoaded'],
        ];
    }

    public function productPageLoaded(ProductPageLoadedEvent $pEvent)
    {
        $product = $pEvent->getPage()->getProduct();
        $productId = $product->id;

        if ($product->parentId !== null && !empty($product->parentId)) {
            $productId = $product->parentId;
        }

        $productCountResponse = $this->productInfoRoute->load($productId, $pEvent->getContext());

        $pEvent->getPage()->addExtension('printess_product_info', $productCountResponse->getPrintessProductInfo());
    }

    public function checkoutConfirmPageLoaded(CheckoutConfirmPageLoadedEvent $event)
    {
        $productInfos = [];
        $addedProducts = [];
        foreach ($event->getPage()->getCart()->getLineItems() as $key => $lineItem) {
            $referenceId = $lineItem->getReferencedId();

            // Make sure we are only handling line items that reference products and no other stuff in the line
            if (!empty($referenceId) && $lineItem->getType() === "product") {
                if (array_key_exists($referenceId, $addedProducts)) {
                    $productInfos[$lineItem->getId()] = $addedProducts[$referenceId];
                } else {
                    $productInfo = $this->productInfoRoute->load($referenceId, $event->getContext());

                    if (isset($productInfo)) {
                        $productInfos[$lineItem->getId()] = $productInfo->getPrintessProductInfo();
                        $addedProducts[$referenceId] = $productInfo->getPrintessProductInfo();
                    }
                }
            }
        }

        $event->getPage()->addExtension('printess_product_infos', new ArrayStruct($productInfos));
    }

    public function offCanvasCartLoaded(OffcanvasCartPageLoadedEvent $pEvent) {
        $this->executeCartPageLoadedEvent($pEvent);
    }

    public function cartPageLoaded(CheckoutCartPageLoadedEvent $pEvent)
    {
        $this->executeCartPageLoadedEvent($pEvent);
    }

    private function executeCartPageLoadedEvent($pEvent) {
        $ret = [];
        $addedProducts = [];
        foreach($pEvent->getPage()->getCart()->getLineItems() as $key => $lineItem) {
            $referenceId = $lineItem->getReferencedId();

            if(!empty($referenceId) && $lineItem->getType() === "product")//Make sure we are only handling line items that reference products and no other stuff in the line
            {
                if(array_key_exists($referenceId, $addedProducts)) {
                    $ret[$lineItem->getId()] = $addedProducts[$referenceId];
                } else {
                    $productInfo = $this->productInfoRoute->load($referenceId, $pEvent->getContext());
    
                    if(isset($productInfo)) {
                        $ret[$lineItem->getId()] = $productInfo->getPrintessProductInfo();
                        $addedProducts[$referenceId] = $productInfo->getPrintessProductInfo();
                    }
                }
            }
        }

        $struct = new ArrayStruct($ret);

        $pEvent->getPage()->addExtension('printess_product_infos', $struct);
    }
}
