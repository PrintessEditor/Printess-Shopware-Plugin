<?php declare(strict_types=1);

namespace PrintessEditor\Core\Content\PrintessEditor\SalesChannel;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\Routing\Attribute\Route;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\CountAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\CountResult;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\StoreApiResponse;
use Shopware\Core\Framework\Context;
use Shopware\Core\Content\Cms\SalesChannel\Struct\TextStruct;
use Shopware\Core\System\SystemConfig\SystemConfigService;

#[Route(defaults: ['_routeScope' => ['store-api']])]
class PrintessProductInfoRoute extends AbstractPrintessProductInfoRoute
{
    protected EntityRepository $productRepository;
    protected EntityRepository $currencyRepository;
    protected SystemConfigService $systemConfigService;

    public function __construct(EntityRepository $productRepository, EntityRepository $currencyRepository, SystemConfigService $systemConfigService)
    {
        $this->productRepository = $productRepository;
        $this->systemConfigService = $systemConfigService;
        $this->currencyRepository = $currencyRepository;
    }

    public function getDecorated(): AbstractPrintessProductInfoRoute
    {
        throw new DecorationPatternException(self::class);
    }

    private function getProductById($id, Context $context) {
      $criteria = new Criteria();
      $criteria->addFilter(new EqualsFilter('id', $id));
      $criteria->addAssociations(['options', 'options.group', 'properties', 'properties.group']);
      $product = $this->productRepository->search($criteria, $context)->getElements();

      if($product !== null && count($product) > 0) {
        $product = $product[$id];
      }

      return $product;
    }

    private function getProductVariants($productId, Context $context) {
      $ret = array();
      $criteria = new Criteria();
      $criteria->addFilter(new EqualsFilter('parentId', $productId));
      $criteria->addAssociations(['options', 'options.group', 'properties', 'properties.group']);
      $product = $this->productRepository->search($criteria, $context)->getElements();

      if($product !== null && count($product) > 0) {
        foreach($product as $key => $value) {

          $ret[] = $value;
        }
      }

      return $ret;
    }

    private function convertProduct($product) {
      $ret = array();

      $ret["name"] = $product->name;
      $ret["id"] = $product->id;
      $ret["description"] = $product->description;
      $ret["productNumber"] = $product->productNumber;
      $ret["price"] = array();
      $ret["metaData"] = array();
      $ret["options"] = array();
      $ret["properties"] = array();
      
      if($product->translated !== null) {
        if(array_key_exists("name", $product->translated)) {
          $ret["name"] = $product->translated["name"];
        }

        if(array_key_exists("description", $product->translated)) {
          $ret["description"] = $product->translated["description"];
        }
      }

      if($product->price !== null) {
        foreach($product->price->getKeys() as $key) {
          $price = $product->price->get($key);

          $ret["price"][] = array(
            "currencyId" => $price->getCurrencyId(),
            "net" => $price->getNet(),
            "gross" => $price->getGross(),
            "linked" => $price->getLinked(),
            //"listPrice" => $price->getListPrice(),
            "percentage" => $price->getPercentage(),
            "regulationPrice" => $price->getRegulationPrice()
          );
        }
      }

      foreach($product->getCustomFields() as $key => $value) {
        $ret["metaData"][$key] = $value;
      }

      if(property_exists($product, "options")) {
        foreach($product->get('options')->getElements() as $key => $value) {
          $ret["options"][$value->group->name] = array("groupName" => $value->group->name, "groupId" => $value->group->id, "optionName" => $value->name, "optionId" => $value->id);
        }
      }

      if(property_exists($product, "properties")) {
        foreach($product->get('properties')->getElements() as $key => $value) {
          if(is_string($value)) {
            $ret["properties"][$key] = $value;
          } else {
            $ret["properties"][$value->group->name] = array("groupName" => $value->group->name, "groupId" => $value->group->id, "propertyName" => $value->name, "propertyId" => $value->id);
          }
        }
      }

      return $ret;
    }

     #[Route(path: '/store-api/printess_get_product_info', name: 'store-api.printess_get_product_info.get', methods: ['GET', 'POST'], defaults: ['_entity' => 'product'])]
    public function load($productId, Context $context): PrintessProductInfoResponse
    {
      $product = array();
      $variants = array();
      $settings = array();


      $productObj = $this->getProductById($productId, $context);
      $variantsObj = $this->getProductVariants($productId, $context);

      if(isset($productObj)) {
        $product = $this->convertProduct($productObj);
      }

      $product["variantOptions"] = array();

      if(isset($variantsObj)) {
        foreach($variantsObj as $variant) {
          $converted = $this->convertProduct($variant);

          $variants[] = $converted;

          foreach($converted["options"] as $option) {
            if(!array_key_exists($option["groupId"], $product["variantOptions"])) {
              $optionGroup = array("name" => $option["groupName"], "id" => $option["groupId"], "values" => array());
              $optionGroup["values"][$option["optionId"]] = array("name" => $option["optionName"], "id" => $option["optionId"]);
              $product["variantOptions"][$option["groupId"]] = $optionGroup;
            }

            if(!array_key_exists($option["optionId"], $product["variantOptions"][$option["groupId"]]["values"])) {
              $product["variantOptions"][$option["groupId"]]["values"][$option["optionId"]] = array("name" => $option["optionName"], "id" => $option["optionId"]);
            }
          }
        }
      }

      $getStringValue = function($value, string $defaultValue = "") {
        if(isset($value) && !empty($value)) {
          return $value;
        } else {
          return $defaultValue;
        }
      };

      $getBoolValue = function($value, bool $defaultValue = false) {
        if(isset($value)) {
          return $value;
        } else {
          return $defaultValue;
        }
      };

      $uiVersion = "classic";

      if(array_key_exists("metaData", $product) && array_key_exists("PrintessUIVersion", $product["metaData"])) {
        if($product["metaData"]["PrintessUIVersion"] != null && !empty($product["metaData"]["PrintessUIVersion"]) && strtolower($product["metaData"]["PrintessUIVersion"]) === "panel") {
          $uiVersion = "bcui";
        }
      }

      $settings["uiSettings"] = array(
        "uiVersion" => $uiVersion,
        "startupLogoUrl" => $getStringValue($this->systemConfigService->get('PrintessEditor.config.StartupLogoUrl', null)),
        "showStartupAnimation" => $getBoolValue($this->systemConfigService->get('PrintessEditor.config.ShowStartupAnimation', null), true),
        "startupBackgroundColor" => "#000000",
        "theme" => $getStringValue($this->systemConfigService->get('PrintessEditor.config.Theme', null))
      );

      $settings["shopToken"] = $getStringValue($this->systemConfigService->get('PrintessEditor.config.ShopToken', null));
      $settings["editorUrl"] = $getStringValue($this->systemConfigService->get('PrintessEditor.config.EditorHost', null), "editor.printess.com");
      $settings["apiHost"] = $getStringValue($this->systemConfigService->get('PrintessEditor.config.ApiHost', null), "api.printess.com");
      $settings["hidePricesInEditor"] = $getBoolValue(!$this->systemConfigService->get('PrintessEditor.config.ShowPricesInEditor', null), false);
      $settings["displayProductName"] = $getBoolValue($this->systemConfigService->get('PrintessEditor.config.ShowProductNameInEditor', null), true);

      $criteria = new Criteria();
      $criteria->addFilter(new EqualsFilter('id', $context->getCurrencyId()));
      //$criteria->addAssociations(['options', 'options.group', 'properties', 'properties.group']);
      $currency = $this->currencyRepository->search($criteria, $context)->getElements();

      if(isset($currency) && count($currency) > 0) {
        $currency = $currency[$context->getCurrencyId()];
      }

      $priceSettings = array(
        "grossPrice" => $context->getTaxState() === "gross",
        "currencyId" => $context->getCurrencyId(),
        "currencyFactor" => $context->getCurrencyFactor(),
        "currencySymbol" => $currency->getSymbol(),
        "currencyPosition" => $currency->getPosition(),
        "currencyIsoCode" => $currency->getIsoCode(),
        "rounding" => array(
          "decimal" => $currency->itemRounding->getDecimals(),
          "interval" => $currency->itemRounding->getInterval(),
          "roundNetPrice" => $currency->itemRounding->roundForNet() === true
        )
      );

      $legalText = "";

      if($context->getTaxState() === "gross")
      {
        $legalText = $getStringValue($this->systemConfigService->get('PrintessEditor.config.LegalGrossPriceInfo', null));
      }
      else
      {
        $legalText = $getStringValue($this->systemConfigService->get('PrintessEditor.config.LegalNetPriceInfo', null));
      }

      if(isset($legalText) && !empty($legalText))
      {
        $priceSettings["legalText"] = $legalText;
      }
            
      return new PrintessProductInfoResponse($product, $variants, $settings, $priceSettings);
  }
}

class PrintessProductInfoResponse extends StoreApiResponse
{
    public function __construct(array $product, array $variants, array $printessInfo, array $priceSettings)
    {
        $json = json_encode(array(
          "product" => $product,
          "variants" => $variants,
          "settings" => $printessInfo,
          "priceSettings" => $priceSettings
        ));

        $struct = new TextStruct($json);
        $struct->setContent($json);

        parent::__construct($struct);
    }

    public function getPrintessProductInfo(): TextStruct
    {
        return $this->object;
    }
}
