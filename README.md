## WooCommerce Quipu ##

WooCommerce Quipu integrates WooCommerce with Quipu accounting service via Quipu API.

Once the plugin is enabled it will connect to the Quipu API using the API key and secret and send all orders that are in "Completed" status to Quipu.  Any partial or full refund will also be sent to Quipu.

### Features

#### WooCommerce Orders ('Completed')

- Send orders to Quipu once set to "Completed"
- If Quipu Numbering Series is set then it will use that numbering series for invoices
- New contacts will be created for every order
- If using WooCommerce EU VAT number plugin, the plugin will verify if the contact exists using their VAT ID and only create the contact if they do not exist

#### WooCommerce Refunds

- Send partial or full refunds to Quipu
- If Quipu Refund Numbering Series is set then it will use that numbering series for refunds

#### WooCommerce Settings

- Quipu API key and secret fields
- Invoice Numbering Series prefix
- Refund Numbering Series prefix
- Debug logging

#### Multisite

- All features should work for each blog in multisite installations.

### Installation

1. Upload or extract the `woocommerce-quipu` folder to your site's `/wp-content/plugins/` directory. You can also use the *Add new* option found in the *Plugins* menu in WordPress.
2. Enable the plugin from the *Plugins* menu in WordPress.

### Usage

1. Go to WooCommerce > Settings > Integration > Quipu
2. Add Quipu API Key and Secret
3. Set Numbering Series for Invoices and Refunds
4. It is recommended to keep debugging on.
5. Click Save.

### Changelog

#### 1.0
* First public release