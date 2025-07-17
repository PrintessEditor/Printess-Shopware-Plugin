// Import all necessary Storefront plugins
import PrintessEditor from './PrintessEditor/PrintessEditor.plugin';
import PrintessCart from './PrintessCart/PrintessCart.plugin';

// Register your plugin via the existing PluginManager
const PluginManager = window.PluginManager;

PluginManager.register('PrintessEditor', PrintessEditor, "[data-printess-button]");
PluginManager.register('PrintessCart', PrintessCart, "[data-printess-line-item-id]");
