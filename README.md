# Frisbii Payments payment module for Drupal Commerce
This module is aimed to provide online payments with Frisbii Payments payment gateway

# Installation
To install the module with composer go to Drupal repository : https://www.drupal.org/project/commerce_reepay

# Requirements
The module works with Drupal versions >=8

# Configuring payment gateways
This module registers its payment methods as plugins under **Commerce > Configuration > Payment gateways**. There are two different kinds of plugin, and Drupal Commerce treats them as fully independent gateways — each one is added and configured separately, with its own credentials.

If you've just updated the module and a plugin you expect isn't listed on the "Add payment gateway" form, clear the site's caches first (**Configuration > Development > Performance > Clear all caches**) so Drupal picks up the new plugin definition.

## General payment method — "Frisbii Payments"
The `reepay_checkout` plugin. Presents every payment method you enable through one Frisbii-hosted checkout session; the customer then picks how to pay inside Frisbii's checkout itself.

To add or edit it: **Commerce > Configuration > Payment gateways > Add payment gateway**, choose **Frisbii Payments** as the Plugin, then fill in:
- Live/Test Private key (from the Frisbii Payments admin)
- Checkout type (Window or Modal)
- Locale
- Instant settle
- Payment method — the multiselect list of payment methods to offer through this gateway (e.g. card, MobilePay, Klarna, Vipps, ...)

## Single payment method gateways — e.g. "Frisbii Pay - Klarna"
A dedicated gateway for exactly one Frisbii payment method (currently: Klarna, plugin id `reepay_klarna`). It is its own selectable option at checkout, independent of whatever is enabled on the General payment method above — enabling both at once is fine, a customer just sees two Frisbii-related choices.

To add it: **Commerce > Configuration > Payment gateways > Add payment gateway**, choose **Frisbii Pay - Klarna** as the Plugin, then fill in the same Private key / Checkout type / Locale / Instant settle fields as above. There's no payment method selector, since it always uses Klarna.

Note that credentials are **not shared** between separate payment gateway entities — if you already filled in your Frisbii Private keys on "Frisbii Payments", you need to enter them again here. Each payment gateway added from **Add payment gateway** is its own independent configuration, even when pointed at the same Frisbii account.

## Changelog (Latest Release)
v 1.1.0

- [Fix] - Payment improvements.
- [Fix] - Frisbii webhook authentication with HMAC-SHA256
- [Feature] - Supports Visa & Mastercard Click-to-pay.
- [Feature] - Support new Klarna payment option for all previous Klarna options.