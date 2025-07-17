import './sw-order-detail-general/';
import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';
import PrintessAdminOrderService from './service/printess-admin-order-service';

Shopware.Component.register('sw-order-detail-printess', () => {import("./sw-order-detail-printess");});

Shopware.Application.addServiceProvider('printessAdminOrderService', () => {
    return new PrintessAdminOrderService(
        Shopware.Application.getContainer('init').httpClient,
        Shopware.Service('loginService')
    );
});

Shopware.Module.register('sw-new-order-tab-printess', {
  type: 'plugin',
  name: 'PrintessEditor',
  color: '#ff3d58',
  icon: 'default-shopping-paper-bag-product',

  routeMiddleware(next, currentRoute) {
      if (currentRoute.name === 'sw.order.detail') {
          currentRoute.children.push({
              name: 'sw.order.detail.printess_items',
              path: '/sw/order/detail/:id/printess',
              component: 'sw-order-detail-printess',
              meta: {
                  parentPath: "sw.order.index"
              }
          });
      }
      next(currentRoute);
  },
  snippets: {
      'de-DE': deDE,
      'en-GB': enGB
  }
});