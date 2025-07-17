<?php declare(strict_types=1);

namespace PrintessEditor\Subscriber;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Checkout\Cart\Event\BeforeLineItemAddedEvent;
use Symfony\Component\HttpFoundation\RequestStack;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Checkout\Cart\Order\CartConvertedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class OrderPlacedSubscriber implements EventSubscriberInterface
{
    private RequestStack $requestStack;
    private EntityRepository $productGateway;
    private EntityRepository $salesChannelRepository;
    private EntityRepository $countryRepository;
    private EntityRepository $countryStateRepository;
    private EntityRepository $shippingMethodRepository;
    private SystemConfigService $systemConfigService;
    private $customerRepo;

    public function __construct(RequestStack $requestStack,
    EntityRepository $productGateway,
    EntityRepository $salesChannelRepository,
    EntityRepository $countryRepository,
    EntityRepository $countryStateRepository,
    EntityRepository $shippingMethodRepository,
    SystemConfigService $systemConfigService,
    $customerRepo)
    {
        $this->requestStack = $requestStack;
        $this->productGateway = $productGateway;
        $this->salesChannelRepository = $salesChannelRepository;
        $this->countryRepository = $countryRepository;
        $this->countryStateRepository = $countryStateRepository;
        $this->shippingMethodRepository = $shippingMethodRepository;
        $this->systemConfigService = $systemConfigService;
        $this->customerRepo = $customerRepo;
    }

    public static function getSubscribedEvents(): array
    {
        return [CartConvertedEvent::class => 'onCartConverted'];
    }

    public function onCartConverted(CartConvertedEvent &$event): void {
        $convertedCart = $event->getConvertedCart();
        $shopUrl = $this->getShopUrl($event->getContext(), $convertedCart["salesChannelId"]);
        $productionResults = [];

        //Handle dropship items; A dropship order / address has to be created for each delivery
        foreach($this->getDropshipLineItemsByDeliveries($event->getContext(), $convertedCart) as $deliveryId => &$deliveryItem )
        {
            $printessDropshipLineItems = $this->convertLineItemArray($event->getContext(), $deliveryItem["items"]);
            $dropshipId = null;

            if(count($printessDropshipLineItems) > 0) {
                $dropshipId = $this->createDropshipAddress($event->getContext(), $convertedCart, $deliveryItem["delivery"], $shopUrl);
    
                //produce dropship items
                $productionResults = array_merge($productionResults, $this->produceItems($event->getContext(), $printessDropshipLineItems, $convertedCart, $shopUrl, $dropshipId, $convertedCart));
            }

        }

        //Handle nondropship items
        $printessLineItems = [];
        foreach($convertedCart["lineItems"] as $key => &$item) {
            if(!array_key_exists('_printessSaveToken', $item["payload"])) {
                continue;
            }

            $product = $this->getProductById($item["productId"], $event->getContext());
            $metaData = $product->getCustomFields();
            $isDropshipItem = isset($metaData) && array_key_exists("PrintessDropshipId", $metaData) && !empty($metaData["PrintessDropshipId"]) && is_numeric($metaData["PrintessDropshipId"]) && intval($metaData["PrintessDropshipId"]) > -1;

            if($isDropshipItem)
            {
                continue;
            }

            $printessLineItems[] = $item;
        }

        $printessLineItems = $this->convertLineItemArray($event->getContext(), $printessLineItems);

        $productionResults = array_merge($productionResults, $this->produceItems($event->getContext(), $printessLineItems, $convertedCart, $shopUrl, 0, $convertedCart));

        //set production results
        foreach($convertedCart["lineItems"] as $key => &$item) {
            if(array_key_exists($item["id"], $productionResults)) {
                $item["payload"]["_printessProduction"] = json_encode($productionResults[$item["id"]]);
            }
        }

        $event->setConvertedCart($convertedCart);

        return;
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

    private function getProductById($id, Context $context) {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $id));
        $criteria->addAssociations(['options', 'options.group', 'properties', 'properties.group']);
        $product = $this->productGateway->search($criteria, $context)->getElements();
  
        if($product !== null && count($product) > 0) {
          $product = $product[$id];
        }
  
        return $product;
    }

    private function getCustomerById($context, $id)
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $id));
        $criteria->addAssociation('addresses');
        $ret = $this->customerRepo->search($criteria, $context);

        if(isset($ret))
        {
            $ret = $ret->getElements();

            if(count($ret) > 0)
            {
                return $ret[$id];
            }
        }

        return null;
    }

      
    private function createDropshipAddress($eventContext, $convertedCart, $delivery, $shopUrl) {
        $printessShippingAddress = [];

        if($delivery != null && array_key_exists("shippingOrderAddress", $delivery)) {
            $this->addAddressToArray($printessShippingAddress, $delivery["shippingOrderAddress"], "", $eventContext);
    
            $printessShippingAddress["shippingDateEarliest"] = $this->getAddressValue($delivery, "shippingDateEarliest");
            $printessShippingAddress["shippingDateLatest"] = $this->getAddressValue($delivery, "shippingDateLatest");
    
            if(array_key_exists("shippingMethodId", $delivery)) {
                $shippingMethod = $this->getEntityById($this->shippingMethodRepository, $delivery["shippingMethodId"], $eventContext);
    
                if(isset($shippingMethod)) {
                    $printessShippingAddress["shipping"] = $shippingMethod->getName();
                }
            }
        }

        if(isset($convertedCart["orderCustomer"])) {
            $printessShippingAddress["email"] = $this->getAddressValue($convertedCart["orderCustomer"], "email");
            $printessShippingAddress["customerId"] = $this->getAddressValue($convertedCart["orderCustomer"], "customerId");
        }

        return intval($this->sendPostRequest("https://" . $this->systemConfigService->get('PrintessEditor.config.ApiHost') . "/dropshipData/save", [
            "userId" => $printessShippingAddress["customerId"],
            "type" => "printess-shipping",
            "json" => json_encode($printessShippingAddress)
        ], $shopUrl));
    }

    private function getEntityById($entityRepository, $id, $eventContext, array $assoziations = null) {
        $criteria = new Criteria([$id]);

        if(isset($assoziations)) {
            foreach($assoziations as $key => $value) {
                $criteria->addAssociation($value);
            }
        }

        $entity = $entityRepository->search($criteria, $eventContext)->first();

        if (!isset($entity)) {
             return null;
        }

        return $entity;
    }

    private function getAddressValue($address, $key) {
        if(array_key_exists($key, $address) && isset($address[$key]) && $address[$key] != "") {
            return $address[$key];
        }

        return null;
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

    private function produceItems($context, &$items, $order, $shopUrl, $dropshipId, $convertedCart)
    {
        $result = [];
        $errors = [];

        foreach($items as $key => &$item)
        {            
            try {
                $nonce = password_hash(Uuid::randomHex() . date("D M d, Y G:i") . $item["lineItem"]["id"] .  Uuid::randomHex(), PASSWORD_BCRYPT);
                $dpi = 300;
                $outputFormat = "pdf";
                $jpegCompression = 90;

                if(array_key_exists("metaData", $item) && array_key_exists("PrintessOutputFileFormat", $item["metaData"]) && !empty($item["metaData"]["PrintessOutputFileFormat"]) && in_array(strtolower($item["metaData"]["PrintessOutputFileFormat"]) ,["pdf", "png", "tiff", "jpg"]) ) {
                    $outputFormat = strtolower($item["metaData"]["PrintessOutputFileFormat"]);
                }

                if(array_key_exists("metaData", $item) && array_key_exists("PrintessOutputResolution", $item["metaData"]) && !empty($item["metaData"]["PrintessOutputResolution"]) ) {
                    $dpi = intval($item["metaData"]["PrintessOutputResolution"]);
                }

                if(array_key_exists("metaData", $item) && array_key_exists("PrintessOutputJpgCompression", $item["metaData"]) && !empty($item["metaData"]["PrintessOutputJpgCompression"]) ) {
                    $jpegCompression = intval($item["metaData"]["PrintessOutputJpgCompression"]);
                }

                $vdpData = array("form" => array());

                $delivery = $this->getDeliveryForOrderLineItem($convertedCart, $item["lineItem"]["id"]);
                $shippingOrderAddressId = null;

                if($delivery !== null)
                {
                    $vdpData["form"]["shippingDateEarliest"] = $delivery["shippingDateEarliest"] !== null && !empty($delivery["shippingDateEarliest"]) ? $delivery["shippingDateEarliest"] : "";
                    $vdpData["form"]["shippingDateLatest"] = $delivery["shippingDateLatest"] !== null && !empty($delivery["shippingDateLatest"]) ? $delivery["shippingDateLatest"] : "";

                    if(array_key_exists("shippingOrderAddress", $delivery) && $delivery["shippingOrderAddress"] !== null)
                    {
                        $shippingOrderAddressId = $delivery["shippingOrderAddress"]["id"];

                        $this->addAddressToArray($vdpData["form"], $delivery["shippingOrderAddress"], "Shipping", $context);

                        if(array_key_exists("shippingMethodId", $delivery)) {
                            $shippingMethod = $this->getEntityById($this->shippingMethodRepository, $delivery["shippingMethodId"], $context);
                
                            if(isset($shippingMethod)) {
                                $vdpData["form"]["ShippingMethod"] = $shippingMethod->getName();
                            }
                        }
                    }
                }

                $vdpData["form"]["orderId"] = strval($order["id"]);
                $vdpData["form"]["orderName"] = strval($convertedCart["orderNumber"]);
                $vdpData["form"]["lineItemId"] = "" . $item["lineItem"]["id"];
                $vdpData["form"]["itemQuantity"] = "" . $item["lineItem"]["quantity"];
                $vdpData["form"]["saveToken"] = "" . $item["lineItem"]["payload"]["_printessSaveToken"];
                $vdpData["form"]["productName"] = $item["variant"] !== null && $item["variant"]->getName() !== null ? $item["variant"]->getName() :  $item["product"]->getName();
                $vdpData["form"]["itemSku"] = $item["variant"] !== null ? $item["variant"]->getProductNumber() : $item["product"]->getProductNumber();
                $vdpData["form"]["orderDate"] = $item["product"]->getCreatedAt();
                $vdpData["form"]["variantId"] =  $item["variant"] !== null ? $item["variant"]->getId() : null;

                // vdpValues.AddValue("productType", lineItem.ProductType, formFieldMappings);
                
                if(array_key_exists("orderCustomer", $convertedCart) && $convertedCart["orderCustomer"])
                {
                    $vdpData["form"]["customerEmail"] = $convertedCart["orderCustomer"]["email"];
                    $vdpData["form"]["customerFirstName"] = $convertedCart["orderCustomer"]["firstName"];
                    $vdpData["form"]["customerLastName"] = $convertedCart["orderCustomer"]["lastName"];
                    $vdpData["form"]["customerName"] = $convertedCart["orderCustomer"]["firstName"] . " " . $convertedCart["orderCustomer"]["lastName"];


                    if(array_key_exists("customerId", $convertedCart["orderCustomer"]))
                    {
                        $customer = $this->getCustomerById($context, $convertedCart["orderCustomer"]["customerId"]);
                    }
                    else
                    {
                        $customer = $this->getCustomerById($context, $convertedCart["orderCustomer"]["customer"]["id"]);
                    }

                    $addresses = $customer->getAddresses();

                    if(isset($addresses))
                    {
                        $addresses = $addresses->getElements();

                        if($shippingOrderAddressId !== null && $shippingOrderAddressId === $order["billingAddressId"])
                        {
                            $this->addAddressToArray($vdpData["form"], $delivery["shippingOrderAddress"], "Billing", $context);
                        }
                        else
                        {
                            if(isset($order["addresses"]))
                            {
                                foreach($order["addresses"] as $key => $value)
                                {
                                    if(array_key_exists("id", $value) && $value["id"] && $order["billingAddressId"] === $value["id"])
                                    {
                                        $this->addAddressToArray($vdpData["form"], $value, "Billing", $context);
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }


                //Send the order to production
                $produceParams = [
                    "externalOrderId"       => strval($order["id"]),
                    "templateName"          => $item["lineItem"]["payload"]["_printessSaveToken"],
                    "orderLineItemCount"    => strval(count($items)),
                    "origin"                => "SW: $shopUrl",
                    'meta'                  => json_encode(
                        array(
                            'orderId'    => strval($order["id"]),
                            'lineItemId' => strval($item["lineItem"]["id"]),
                            "nonce"      => $nonce
                        )
                    ),
                    'callbackUrl'     => $shopUrl . "/printess/produceresult",
                    'outputSettings'  => array(
                        'dpi' => $dpi,
                        "jpegCompression" => $jpegCompression
                    ),
                    "outputType" => $outputFormat,
                    "vdp" => $vdpData
                ];
    
                if($dropshipId > 0) {
                    $produceParams["dropship"] = [
                        "dropshipDataId" => $dropshipId,
                        "productDefinitionId" => intval($item["metaData"]["PrintessDropshipId"]),
                        "linkedOrderLineItems" => count($items),
                        "linkedOrderLineItemsId" => strval($order["id"])
                    ];
                }
    
                $productionResult = json_decode($this->sendPostRequest("https://" . $this->systemConfigService->get('PrintessEditor.config.ApiHost') . ($dropshipId <= 0 ? "/production/produce" : "/dropship/produce"), $produceParams, $shopUrl), true);
                $productionResult["nonce"] = $nonce;
                $productionResult["productionId"] = Uuid::randomHex();
    
                $item["lineItem"]["payload"]["_printessProduction"] = $productionResult;
    
                $result[$item["lineItem"]["id"]] = $productionResult;
            } catch(\Exception $e) {
                $errors[] = $e->__toString();
            }
        }

        if(count($errors) > 0) {
            throw new \Exception(implode("; ", $errors));
        }

        return $result;
    }

    private function addAddressToArray(array &$array, array $address, string $prefix, $context)
    {
        $array[$prefix . "CompanyName"] = $this->getAddressValue($address, "company");
        $array[$prefix . "DepartmentName"] = $this->getAddressValue($address, "department");
        $array[$prefix . "FirstName"] = $this->getAddressValue($address, "firstName");
        $array[$prefix . "LastName"] = $this->getAddressValue($address, "lastName");
        $array[$prefix . "Address1"] = $this->getAddressValue($address, "street");
        $array[$prefix . "Address2"] = $this->getAddressValue($address, "additionalAddressLine1");
        $array[$prefix . "Address3"] = $this->getAddressValue($address, "additionalAddressLine2");
        $array[$prefix . "Zip"] = $this->getAddressValue($address, "zipcode");
        $array[$prefix . "City"] = $this->getAddressValue($address, "city");
        $array[$prefix . "Phone"] = $this->getAddressValue($address, "phoneNumber");

        if(array_key_exists("countryId", $address) && isset($address["countryId"])) {
            $country = $this->getEntityById($this->countryRepository, $address["countryId"], $context);

            if(isset($country)) {
                $array[$prefix . "Country"] = $country->getIso3();
            }
        }

        if(array_key_exists("countryStateId", $address) && isset($address["countryStateId"])) {
            $state = $this->getEntityById($this->countryStateRepository, $address["countryStateId"], $context);

            if(isset($state)) {
                $array[$prefix . "CountryState"] = $state->getShortCode();
            }
        }
    }

    private function getDeliveryForOrderLineItem(array $convertedCart, string $lineItemId)
    {
        if(array_key_exists("deliveries", $convertedCart) && $convertedCart["deliveries"] !== null)
        {
            foreach($convertedCart["deliveries"] as $index => &$delivery)
            {
                if(!array_key_exists("id", $delivery))
                {
                    $delivery["id"] = $index;
                }

                if(array_key_exists("positions", $delivery) && $delivery["positions"] !== null)
                {
                    foreach($delivery["positions"] as $position)
                    {
                        if(array_key_exists("orderLineItemId", $position) && $position["orderLineItemId"] === $lineItemId)
                        {
                            return $delivery;
                        }
                    }
                }
            }
        }

        return null;
    }

    private function getDropshipLineItemsByDeliveries($context,  array &$convertedCart)
    {
        $ret = array();
        
        foreach($convertedCart["lineItems"] as $key => &$item)
        {
            if(!array_key_exists('_printessSaveToken', $item["payload"])) {
                continue;
            }

            $product = $this->getProductById($item["productId"], $context);
            $parentId = $product->getParentId();
            $metaData = $product->getCustomFields();
            $isDropshipItem = isset($metaData) && array_key_exists("PrintessDropshipId", $metaData) && !empty($metaData["PrintessDropshipId"]) && is_numeric($metaData["PrintessDropshipId"]) && intval($metaData["PrintessDropshipId"]) > -1;

            if(!$isDropshipItem)
            {
                continue;
            }

            $delivery = $this->getDeliveryForOrderLineItem($convertedCart, $item["id"]);

            if($delivery !== null)
            {
                if(!array_key_exists($delivery["id"], $ret))
                {
                    $ret[$delivery["id"]] = array("delivery" => $delivery, "items" => array($item));
                }
                else
                {
                    $ret[$delivery["id"]]["items"][] = $item;
                }
            }
        }
        
        return $ret;
    }

    private function convertLineItemToArray($context, $lineItem) {
        $printessItem = array();

        $product = $this->getProductById($lineItem["productId"], $context);
        $parentId = $product->getParentId();

        if($parentId !== null && !empty($parentId)) {
            $printessItem["product"] = $this->getProductById($product->getParentId(), $context);
            $printessItem["variant"] = $product;
            $printessItem["lineItem"] = $lineItem;
        } else {
            $printessItem["product"] = $product;
            $printessItem["variant"] = null;
            $printessItem["lineItem"] = $lineItem;
        }

        $printessItem["saveToken"] = $lineItem["payload"]['_printessSaveToken'];
        $printessItem["metaData"] = $printessItem["product"]->getCustomFields();

        if(isset($printessItem["variant"])) {
            $printessItem["variantMetaData"] = $printessItem["variant"]->getCustomFields();
        }

        $ret["isDropshipItem"] = isset($printessItem["metaData"]) && array_key_exists("PrintessDropshipId", $printessItem["metaData"]) && !empty($printessItem["metaData"]["PrintessDropshipId"]) && is_numeric($printessItem["metaData"]["PrintessDropshipId"]) && intval($printessItem["metaData"]["PrintessDropshipId"]) > -1;

        return $printessItem;
    }

    private function convertLineItemArray($context, array &$lineItems)
    {
        $ret = array();

        foreach($lineItems as &$item)
        {
            $ret[] = $this->convertLineItemToArray($context, $item);
        }

        return $ret;
    }
}
