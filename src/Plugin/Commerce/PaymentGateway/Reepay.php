<?php

namespace Drupal\commerce_reepay_checkout\Plugin\Commerce\PaymentGateway;

use Drupal\Core\Form\FormStateInterface;

/**
 * Frisbii Payments Payment Gateway.
 *
 * The General payment method: presents every payment type enabled in the
 * payment_method multiselect below through one Frisbii-hosted checkout
 * session. For a dedicated single-payment-method gateway, see Klarna.php.
 *
 * @CommercePaymentGateway(
 *   id = "reepay_checkout",
 *   label = @Translation("Frisbii Payments"),
 *   display_label = @Translation("Frisbii Payments"),
 *   forms = {
 *     "offsite-payment" = "Drupal\commerce_reepay_checkout\PluginForm\ReepayOffsiteRedirectForm",
 *    },
 *   payment_method_types = {"credit_card"},
 * )
 */
final class Reepay extends ReepayGatewayBase
{

  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {

    $form = parent::buildConfigurationForm($form, $form_state);

    $form['payment_method'] = array(
      '#title' => t('Payment method'),
      '#type' => 'select',
      '#default_value' => $this->configuration['payment_method'],
      '#multiple' => true,
      '#options' => array(
        'card'  => t('All available debit / credit cards'),
        'dankort' => t('Dankort'),
        'visa' => t('VISA'),
        'visa_elec' => t('VISA Electron'),
	'anyday'    => t('Anyday'),

        'mc'  => t('MasterCard'),
        'amex' => t('American Express'),
        'mobilepay' => t('MobilePay Online'),
        'viabill' => t('ViaBill'),


        'resurs'  => t('Resurs Bank'),
        'swish' => t('Swish'),
        'vipps' => t('Vipps'),
        'diners' => t('Diners Club'),

        'maestro'  => t('Maestro'),
        'laser' => t('Laser'),
        'discover' => t('Discover'),
        'jcb' => t('JCB'),

        'china_union_pay'  => t('China Union Pay'),
        'ffk' => t('Forbrugsforeningen'),
        'paypal' => t('paypal'),
        'applepay' => t('Apple Pay'),

        'googlepay'  => t('Google Pay'),
        'klarna' => t('Klarna'),
        'klarna_pay_later' => t('Klarna Pay Later'),
        'klarna_pay_now' => t('Klarna Pay Now'),
        'klarna_slice_it' => t('Klarna Slice It!'),

        'klarna_direct_bank_transfer'  => t('Klarna Direct Bank Transfer'),
        'klarna_direct_debit' => t('Klarna Direct Debit'),
        'ideal' => t('iDEAL'),
        'blik' => t('BLIK'),

        'p24'  => t('Przelewy24 (P24)'),
        'verkkopankki' => t('Verkkopankki'),
        'giropay' => t('giropay'),
        'sepa' => t('SEPA Direct Debit'),

      )
    );

    return $form;
  }

  public function submitConfigurationForm(array &$form, FormStateInterface $form_state)
  {
    parent::submitConfigurationForm($form, $form_state);
    $values = $form_state->getValue($form['#parents']);
    $this->configuration['payment_method'] = $values['payment_method'];
  }

  /**
   * {@inheritdoc}
   */
  public function getReepayPaymentMethods(): array {
    return array_keys(array_filter($this->configuration['payment_method'] ?? []));
  }

}
