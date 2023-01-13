<?php

namespace Drupal\commerce_reepay_checkout\PluginForm;

use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm;
use Drupal\commerce_reepay_checkout\Services\ReepayService;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ReepayOffsiteRedirectForm extends PaymentOffsiteForm implements ContainerInjectionInterface {
  /**
   * @var ReepayService
   */
  private $reepayService;
  private $languageManager;

  /**
   *
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *
   **/
  public function __construct(LanguageManagerInterface $language_manager, ReepayService $reepayService) {
     $this->reepayService = $reepayService;
     $this->languageManager = $language_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('language_manager'),
      $container->get('commerce_reepay_checkout.reepay_service'),
    );
  }

  public function buildConfigurationForm(array $form, FormStateInterface $form_state)
  {
    $form = parent::buildConfigurationForm($form, $form_state);
    $payment = $this->entity;
    $order = $payment->getOrder();
    $configuration = $payment->getPaymentGateway()->getPlugin()->getConfiguration();
    $payment->save();

    $privateKey = null;

    if('test' == $configuration['mode']) {
       $privateKey = $configuration['test_private_key'];
    }

    if('live' == $configuration['mode']) {
      $privateKey = $configuration['live_private_key'];
    }

    $invoiceHandle = $order->id();
    $this->reepayService->setPrivateKey($privateKey);
    $invoice = $this->reepayService->getInvoice($invoiceHandle);

    // if invoice has already been authorized of settled
    // we should create unique invoice handle in this case
    if(is_array($invoice) && 'created' !== $invoice['state']) {
        $invoiceHandle = $invoiceHandle  . '-' . time();
    }

    $paymentAmount = (int) $payment->getAmount()->getNumber() * 100;

    $address = $order->getBillingProfile()->address->first();

    $payment_methods = [];

    foreach($configuration['payment_method'] as $key => $value) {
      $payment_methods[] = $key;
    }

    $payload = ['order' =>
                 ['handle' => $invoiceHandle,
                  'amount' => $paymentAmount,
                  'locale' => $configuration['locale'],
                  'currency' => $payment->getAmount()->getCurrencyCode(),
                   'customer' => [
                    'first_name' => $address->getGivenName(),
                    'last_name' => $address->getFamilyName(),
                    'postal_code' => $address->getPostalCode(),
                    'email' => $order->getEmail(),
                    'address' => $address->getAddressLine1(),
                    'city' => $address->getLocality(),
                    'country' => $address->getCountryCode()]
                 ],
                'cancel_url' => $form['#cancel_url'],
                'accept_url' => $form['#return_url'],
                'settle' => ($configuration['instant_settle'] == 'yes') ? true : false,
                'payment_methods' => $payment_methods
    ];

    $result = $this->reepayService->createChargeSession($payload);

    if($result['result'] == 'error') {
        throw new PaymentGatewayException( t('Error occurred during payment with Reepay checkout'));
    }

    $checkout_type = $configuration['checkout_type'];

    // Attach library.
    $form['#attached']['library'][] = 'commerce_reepay_checkout/commerce_reepay_checkout.payment';
    $form['#attached']['drupalSettings']['commerce_reepay_checkout'] = array(
      'session_id' => $result['id'],
      'checkout_type' => $checkout_type,
      'return_url' => $form['#return_url'],
      'cancel_url' => $form['#cancel_url']
    );

    $order->save();

    return $form;
  }

}
