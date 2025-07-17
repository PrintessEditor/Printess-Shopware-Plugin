<?php declare(strict_types=1);

namespace PrintessEditor\Core\Content\PrintessEditor\SalesChannel;

use Shopware\Core\Framework\Api\Response;
use Symfony\Component\HttpFoundation;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\Routing\Attribute\Route;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Symfony\Component\HttpFoundation\JsonResponse;

#[Route(defaults: ['_routeScope' => ['api']])]
class PrintessOrderRoute extends AbstractPrintessOrderRoute
{
  protected EntityRepository $orderRepository;
  protected SystemConfigService $systemConfiguration;

  public function __construct(EntityRepository $orderRepository, SystemConfigService $systemConfiguration)
  {
    $this->orderRepository = $orderRepository;
    $this->systemConfiguration = $systemConfiguration;
  }

  public function getDecorated(): AbstractPrintessOrderRoute
  {
    throw new DecorationPatternException(self::class);
  }

  #[Route(path: '/api/printess2/order/status', name: 'api.printess2.order.status', methods: ['POST'])]
  public function load(Request $request)
  {
    $orderId = $request->getContent();

    return new JsonResponse(["abc" => "test"]);
  }
}
