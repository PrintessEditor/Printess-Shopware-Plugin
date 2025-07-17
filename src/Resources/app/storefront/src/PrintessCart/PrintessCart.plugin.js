import Plugin from 'src/plugin-system/plugin.class';
import HttpClient from 'src/service/http-client.service';
import { PrintessShopwareIntegration as PrintessShopware } from "../PrintessEditor/printessShopware";

export default class PrintessCartPlugin extends Plugin {
  init() {
      this.infos = null;

      this.lineItemId = this.el.getAttribute("data-printess-line-item-id");
      this.lineItem = JSON.parse(this.el.getAttribute("data-printess-line-item"));

      try {
        const json = JSON.parse(this.el.getAttribute("data-printess-product-infos"))

        if(json) {
          this.infos = json;
        }
      } catch(ex) {
        console.error(ex);
      }

      this.el.addEventListener('click', this.editProductClicked.bind(this));
  }

  editProductClicked() {
    if(this.infos.hasOwnProperty(this.lineItemId)) {
      let info = this.infos[this.lineItemId];

      if(info && info.content && typeof info.content === "string") {
        info = JSON.parse(info.content);
      }

      info.product.variants = info.variants;
      delete info.variants;

      const showSettings = {
        lineItemId: this.lineItemId,
        displayMode: "cart",
        basketItemOptions: this.lineItem && this.lineItem.payload && this.lineItem.payload["_printessProductOptions"] ? (typeof this.lineItem.payload["_printessProductOptions"] === "string" ? JSON.parse(this.lineItem.payload["_printessProductOptions"]) : this.lineItem.payload["_printessProductOptions"]) : {},
        saveToken: this.lineItem && this.lineItem.payload && this.lineItem.payload["_printessSaveToken"] ? this.lineItem.payload["_printessSaveToken"] : "",
        updateBasketItem: this.updateCartItem,
        lineItem: this.lineItem,
        priceFormatOptions: info.priceSettings
      };

      const printessEditor = new PrintessShopware(info.settings, info.product);
      printessEditor.show(showSettings);
    } else {
      console.error("Can not open editor");
    }
  }

  updateCartItem(lineItem) {
    const client = new HttpClient();

    const data = {
      "items": [lineItem]
    };

    xhr = client.post("/store-api/checkout/cart/line-item", JSON.stringify(data) , (response) => {
      alert(JSON.stringify(response));
    });
  }
}
