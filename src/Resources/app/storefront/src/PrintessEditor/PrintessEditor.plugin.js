import Plugin from 'src/plugin-system/plugin.class';
import { PrintessShopwareIntegration as PrintessShopware  } from "./printessShopware"

export default class PrintessEditorPlugin extends Plugin {
    init() {
        this.BUY_BTN_SELECTOR = "button.btn.btn-primary.btn-buy";
        this.printessEditor = null;
        this.info = null;

        //Move the printess button inside html right next to the buy button.
        const parentForm = this.el.closest('form');
        let buyButton = null;

        if(parentForm) {
            buyButton = parentForm.querySelector(this.BUY_BTN_SELECTOR);

            if(buyButton) {
                buyButton.parentNode.insertBefore(this.el, buyButton.nextSibling);
            }   
        }

        //Read printess and product information
        const productId = this.el.getAttribute("data-product-id");

        if(productId && typeof window["getPrintessInfo" + productId] === "function") {
            const info = this.info = window["getPrintessInfo" + productId]();

            info.product.variants = info.variants;
            delete info.variants;

            info.settings.designNowButtonInstance = this.el;
            info.settings.addToBasketButtonInstance = buyButton;

            this.printessEditor = new PrintessShopware(info.settings, info.product);
            this.printessEditor.initProductPage();
        }

        this.el.addEventListener('click', this.editProductClicked.bind(this));
    }

    editProductClicked() {
        if(this.printessEditor) {
            this.printessEditor.show({
                priceFormatOptions: this.info.priceSettings
            });
        }
    }
}
