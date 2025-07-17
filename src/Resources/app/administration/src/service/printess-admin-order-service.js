export default class PrintessAdminOrderService extends Shopware.Classes.ApiService {
  constructor(httpClient, loginService, apiEndpoint = 'printess') {
      super(httpClient, loginService, apiEndpoint);
      this.name = 'printessAdminOrderService';
  }

  getOrderPrintStatus(orderId) {
      return this.httpClient.post(
          `/printess/order/status`,
          orderId,
          { headers: this.basicHeaders(), version: 3 }
      );
  }

  basicHeaders(context = null) {
      const headers = {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          Authorization: `Bearer ${this.loginService.getToken()}`
      };

      if (context && context.languageId) {
          headers['sw-language-id'] = context.languageId;
      }

      return headers;
  }
}
