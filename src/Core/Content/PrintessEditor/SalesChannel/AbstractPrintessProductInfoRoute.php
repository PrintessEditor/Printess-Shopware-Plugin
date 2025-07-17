<?php declare(strict_types=1);

namespace PrintessEditor\Core\Content\PrintessEditor\SalesChannel;

use Symfony\Component\Routing\Attribute\Route;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Context;

#[Route(defaults: ['_routeScope' => ['store-api']])]
abstract class AbstractPrintessProductInfoRoute
{
    abstract public function getDecorated(): AbstractPrintessProductInfoRoute;

    abstract public function load($productId, Context $context): PrintessProductInfoResponse;
}
