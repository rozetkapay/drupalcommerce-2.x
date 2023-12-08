# drupalcommerce-2.x

INTRODUCTION
------------
​
This module provides a Drupal Commerce payment method to embed the payment
services provided by RozetkaPay.
​
REQUIREMENTS
------------
​
- Commerce Payment (from [Commerce](http://drupal.org/project/commerce) core)
​
INSTALLATION
------------
​
1. Install the Commerce RozetkaPay module by copying the sources to a modules
directory, such as `/modules/contrib` or `sites/[yoursite]/modules`.
2. In your Drupal site, enable the module.
​
CONFIGURATION
-------------
​
- Create a new Payment gateway RozetkaPay:</br>
Go to the all payment gateways configuration page:
- Commerce -> Configuration -> Payment -> Payment gateways</br>
+ Add payment gateway;
- Fill the settings:</br>
+ Name: RozetkaPay (or custom)
+ Select plugin: RozetkaPay (redirect to payment page)
+ Display name: RozetkaPay (or custom)
+ Mode: Test or Live
+ Login: your merchant login
+ Password: your merchant password
+ Status: Enabled;
- Complete: Save payment;
