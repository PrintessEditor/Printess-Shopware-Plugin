<?php declare(strict_types=1);

namespace PrintessEditor\Core\Content\PrintessEditor\SalesChannel;

use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route(defaults: ['_routeScope' => ['api']])]
class PrintessOrderController extends AbstractController
{
  protected EntityRepository $orderRepository;
  protected SystemConfigService $systemConfigService;
  protected EntityRepository $salesChannelRepository;

  public function __construct(EntityRepository $orderRepository, SystemConfigService $systemConfigService, EntityRepository $salesChannelRepository)
  {
    $this->orderRepository = $orderRepository;
    $this->systemConfigService = $systemConfigService;
    $this->salesChannelRepository = $salesChannelRepository;
  }

  #[Route(path: '/api/printess/order/status', name: 'api.printess.order.status', methods: ['POST'])]
  public function clone(Context $context, Request $request): JsonResponse
  {
    $orderId = json_decode($request->getContent());
    $criteria = new Criteria();
    $criteria->addFilter(new EqualsFilter('id', $orderId));
    $criteria->addAssociations(["lineItems"]);
    $order = $this->orderRepository->search($criteria, $context)->getElements();

    if($order !== null && count($order) > 0) {
      $order = $order[$orderId];
    } else {
      return new JsonResponse([]);
    }

    $lineItems = $order->getLineItems();
    $result = [];
    $shopUrl = $this->getShopUrl($context, $order->getSalesChannelId());

    foreach($lineItems as $itemId => $item) {
      if(array_key_exists("_printessProduction", $item->payload) && !empty($item->payload["_printessProduction"])) {
        $printessJobInfo = json_decode($item->payload["_printessProduction"], true);

        if(is_array($printessJobInfo) && array_key_exists("jobId", $printessJobInfo) && !empty($printessJobInfo["jobId"])) {
          $result[$itemId] = json_decode($this->sendPostRequest("https://" . $this->systemConfigService->get('PrintessEditor.config.ApiHost') . "/production/status/get", ["jobId" => $printessJobInfo["jobId"]], $shopUrl), true);
        }
      }
    }
    
    return new JsonResponse($result);
  }

  private function getShopUrl($context, $salesChannelId) {
    $criteria = new Criteria([$salesChannelId]);
    $criteria->addAssociation('domains');
    $salesChannelIds = $this->salesChannelRepository->search($criteria, $context);

    foreach($salesChannelIds->getEntities()->getElements() as $key => $salesChannel){
        foreach($salesChannel->getDomains()->getElements() as $element){
            return $element->getUrl();
        }
    }

    return "";
  }

  private function sendPostRequest($url, $data, $shopUrl) {
    $ch = curl_init( $url );

    curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode($data) );
    curl_setopt( $ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json', 'Authorization: Bearer ' . $this->systemConfigService->get('PrintessEditor.config.ServiceToken'), 'Origin: ' . $shopUrl));
    curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
    curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt( $ch, CURLOPT_FAILONERROR, true);

    if(str_contains($url, "://localhost") || str_contains($url, "://127.0.0.1")) {
        curl_setopt( $ch, CURLINFO_HEADER_OUT, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);   
    }

    $result = curl_exec($ch);

    if(curl_errno($ch) || curl_error($ch) != "") {
        $error = curl_error($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        throw new \Exception("Unable to post data to: " . $url . "; Payload: " . json_encode($data) . "; Error message: ".  $error . "; Details: " . $result . "; info: " . json_encode($info));
    }

    curl_close($ch);

    return $result;
  }
}
