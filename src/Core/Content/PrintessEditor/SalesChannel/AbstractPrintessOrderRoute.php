<?php declare(strict_types=1);

namespace PrintessEditor\Core\Content\PrintessEditor\SalesChannel;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Api\Response;
use Symfony\Component\HttpFoundation;
use Symfony\Component\HttpFoundation\Request;

abstract class AbstractPrintessOrderRoute
{
    abstract public function getDecorated(): AbstractPrintessOrderRoute;
    abstract public function load(Request $request);
}