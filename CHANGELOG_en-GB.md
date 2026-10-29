# 1.2.7
- Completed Printess items in the order now have a "Show form field values" button. It lists every form field of the template with the value the design was produced with, and the images the customer uploaded. The values are stored with the order when production finishes, since Printess keeps the file for 30 days only. For items produced before, they are loaded from Printess the first time they are shown.

# 1.2.6
- SlimUi: preview documents hidden by their document condition are no longer shown in the product gallery. The gallery shows the visible pages only, an image whose page gets hidden shows the product photo again, and the gallery moves along when the SlimUi switches to another page.
- The configured Printess API and editor URLs are now used by the full editor and the SlimUi in the storefront; before, they always used the default Printess domains.

# 1.2.5
- Fixed the SlimUi adding the product's default variant to the cart instead of the variant selected in the editor.
- The full editor no longer adds the previously selected variant to the cart when "Add to basket" is clicked while a variant change is still loading, or when the variant change failed.
- The product definition list of the drop shipping setting is no longer cut off by the card around it, and opens upwards when there is not enough room below.
- Editing a cart item in the full editor now saves the variant whose price the editor shows.

# 1.2.4
- Production jobs now carry the shop's order number as the external order id, so orders are easier to find in Printess and at a dropshipper.
- Order, customer and address data is sent to the template's form fields (order number, order date, quantity, product name and number, shipping and billing address, shipping method, customer name and email), using the same field names as the previous Printess plugin.

# 1.2.3
- Dropshipped items of an order that ship to the same address are now produced as one dropship order, so they are shipped together.
- Approving or restarting a dropshipped item now covers all items of its shipment, after a dialog that shows every design with a preview and lets it be opened in the editor.
- Shipments reported by the dropshipper are written back to the order: the delivery becomes shipped or partially shipped, and the tracking code and link are shown in the administration and in the customer's account.
- Items produced again are sent under the order number with a suffix, for dropshippers that refuse an order number they have already received.
- Optionally, an order can be duplicated for a repeat production instead: the copy keeps its prices, is free of charge for the customer through a discount line, and carries a message explaining why it was created.
- A product set to "No drop shipping" is no longer dropshipped when a default is configured in the plugin settings. The new "Use plugin default" option takes the default.
- "Use dropshipping with template settings" now reaches Printess correctly; such products were not dropshipped before.
- New setting for a public shop URL, so Printess can reach a shop that is only available through a tunnel.
- The product page no longer shows an "Edit design" button on the checkout confirmation and order completed pages.
- The order view now states when production is waiting for the payment, and only offers a restart once an item has been in production.

# 1.2.0
- New "Template check" card on the product's Printess tab: it compares the product's variant properties with the template's form fields and reports what is missing.
- Variant properties and variants can be created from the template's form fields.

# 1.1.5
- Fixed a progress display that stayed on top of the SlimUi while text was being edited.

# 1.1.4
- Fixed the SlimUi on products with several preview images.

# 1.1.0
- Added support for the SlimUi, the compact buyer interface for simple personalizations.

# 1.0.0
- Complete rewrite of the Printess Shopware plugin for Shopware 6.7.
