<?php

namespace Drupal\commerce_reepay_checkout\Plugin\Commerce\PaymentGateway;

/**
 * Frisbii Pay - Klarna Payment Gateway.
 *
 * A single payment method gateway: always sends the literal Frisbii API
 * payment method value "klarna" (customers choose how to pay - Pay Later,
 * Pay Now, Slice It, etc. - inside the Klarna environment itself). Unlike
 * the legacy Klarna* options in Reepay's payment_method multiselect, this
 * is its own selectable gateway, independent of the General payment method.
 *
 * Shares its private keys / checkout type / locale / instant settle
 * configuration form with Reepay via ReepayGatewayBase, but as its own
 * config entity: see docs/adr/0001-per-payment-method-gateway-credentials.md
 * (under debug/Frisbii_pay/task/FD-4/) for why credentials aren't shared
 * between gateway instances.
 *
 * @CommercePaymentGateway(
 *   id = "reepay_klarna",
 *   label = @Translation("Frisbii Pay - Klarna"),
 *   display_label = @Translation("Frisbii Pay - Klarna"),
 *   forms = {
 *     "offsite-payment" = "Drupal\commerce_reepay_checkout\PluginForm\ReepayOffsiteRedirectForm",
 *    },
 *   payment_method_types = {"credit_card"},
 * )
 */
final class Klarna extends ReepayGatewayBase
{

  /**
   * {@inheritdoc}
   */
  public function getReepayPaymentMethods(): array {
    return ['klarna'];
  }

}
