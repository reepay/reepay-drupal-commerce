<?php

namespace Drupal\commerce_reepay_checkout\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_checkout\Event\CheckoutEvents;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Event\OrderEvent;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\InvalidRequestException;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsAuthorizationsInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsNotificationsInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;
use Drupal\commerce_price\MinorUnitsConverterInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_reepay_checkout\Services\ReepayService;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Reepay Checkout Commerce Payment Gateway.
 *
 * @CommercePaymentGateway(
 *   id = "reepay_checkout",
 *   label = @Translation("Reepay Checkout"),
 *   display_label = @Translation("Reepay Checkout"),
 *   forms = {
 *     "offsite-payment" = "Drupal\commerce_reepay_checkout\PluginForm\ReepayOffsiteRedirectForm",
 *    },
 *   payment_method_types = {"credit_card"},
 * )
 */
final class Reepay extends OffsitePaymentGatewayBase implements SupportsRefundsInterface, SupportsAuthorizationsInterface
{
  /**
   * @var ConfigurableLanguageManagerInterface
   */
  private $languageManager;

  /**
   * @var ReepayService
   */
  private $reepayService;
  /**
   * @var EventDispatcherInterface
   */
  private $eventDispatcher;

  /**
   * Reepay constructor.
   *
   * @param array $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   Plugin id.
   * @param mixed $plugin_definition
   *   Plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Entity Type Manager Service.
   * @param \Drupal\commerce_payment\PaymentTypeManager $payment_type_manager
   *   Payment Type Manager.
   * @param \Drupal\commerce_payment\PaymentMethodTypeManager $payment_method_type_manager
   *   Payment Method Type Manager.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   Time Service.
   * @param \Drupal\Core\Language\LanguageManager $languageManager
   *   LanguageManager Service.
   * @param \Drupal\Core\Config\ConfigFactory $configFactory
   *   The Config Factory Service.
   */
  public function __construct(array $configuration,
                              $plugin_id, $plugin_definition,
                              EntityTypeManagerInterface $entity_type_manager,
                              PaymentTypeManager $payment_type_manager,
                              PaymentMethodTypeManager $payment_method_type_manager,
                              TimeInterface $time,
                              MinorUnitsConverterInterface $minor_units_converter = NULL,
                              ReepayService $reepayService, EventDispatcherInterface $event_dispatcher)
  {
    parent::__construct($configuration,
                        $plugin_id,
                        $plugin_definition,
                        $entity_type_manager,
                        $payment_type_manager,
                        $payment_method_type_manager,
                        $time,
                        $minor_units_converter);

    $this->reepayService = $reepayService;
    $this->eventDispatcher = $event_dispatcher;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.commerce_payment_type'),
      $container->get('plugin.manager.commerce_payment_method_type'),
      $container->get('datetime.time'),
      $container->get('commerce_price.minor_units_converter'),
      $container->get('commerce_reepay_checkout.reepay_service'),
      $container->get('event_dispatcher')
    );
  }

  public function defaultConfiguration() {
    return [
        'live_private_key' => '',
        'test_private_key' => '',
        'checkout_type' => 'window',
        'instant_settle' => 'no'
      ] + parent::defaultConfiguration();
  }

  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {

    $form = parent::buildConfigurationForm($form, $form_state);

    $form['live_private_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Live Private key'),
      '#description' => $this->t('This is the private key from the Reepay admin.'),
      '#default_value' => $this->configuration['live_private_key'],
      '#required' => TRUE,
    ];

    $form['test_private_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Test Private key'),
      '#description' => $this->t('This is the private key from the Reepay admin.'),
      '#default_value' => $this->configuration['test_private_key'],
      '#required' => TRUE,
    ];

    $form['checkout_type'] = array(
        '#title' => t('Checkout type'),
        '#type' => 'radios',
        '#default_value' =>  $this->configuration['checkout_type'],
        '#options' => array(
          'window' => t('Window'),
          'modal' => t('Modal'),
        ),
      );

    $form['locale'] = array(
      '#title' => t('Locale'),
      '#type' => 'select',
      '#default_value' =>  (array) $this->configuration['locale'],
      '#options' => array(
        'en_US' => t('English'),
        'da_DK' => t('Danish'),
        'sv_SE' => t('Swedish'),
        'fi_FI' => t('Finnish'),
      )
    );

    $form['instant_settle'] = array(
      '#title' => t('Instant settle'),
      '#type' => 'radios',
      '#default_value' => $this->configuration['instant_settle'],
      '#options' => array(
          'yes'  => t('Yes'),
          'no'  => t('No')
        )
    );

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

  public function onReturn(OrderInterface $order, Request $request)
  {
    parent::onReturn( $order, $request);
    $invoice = $request->get('invoice');
    if($pos = strpos($invoice, '-')) {
         $invoice =  substr($invoice, 0, $pos);
    }

    $payment = $this->entityTypeManager->getStorage('commerce_payment')->loadByProperties(['order_id' => $invoice]);

    if($payment) {
     $payment = current($payment);
     $configuration = $payment->getPaymentGateway()->getPlugin()->getConfiguration();

     if('test' == $configuration['mode']) {
      $key = $configuration['test_private_key'];
    } else {
      $key = $configuration['live_private_key'];
    }
     $this->reepayService->setPrivateKey($key);
     $invoice = $this->reepayService->getInvoice($request->get('invoice'));

     if($invoice['state'] == 'settled' || $invoice['state'] == 'authorized') {

           $authorize_transition = $payment->getState()->getWorkflow()->getTransition('authorize');
           $payment->getState()->applyTransition($authorize_transition);
           $state = ($invoice['state'] == 'authorized') ? 'authorization' : 'completed';
           $payment->set('state', $state);
           $payment->set('amount', $order->getTotalPrice());
           $payment->set('payment_gateway', 'reepay_checkout');
           $payment->set('order_id', $order->id());
           $payment->set('test', $configuration['mode'] == 'test');
           $payment->set('remote_id', $request->get('invoice'));
           $payment->set('remote_state', $state);
           $request_time = \Drupal::time()->getRequestTime();
           $payment->set('authorized', $request_time);
           $payment->save();
       }
    }

  }

  public function onNotify(Request $request)
  {
    // waiting for onReturn to place an order first
    sleep(15);
    $json_content = $request->getContent();
    $decoded_result = Json::decode($json_content);
    $invoice_handle = $decoded_result['invoice'];

    if($pos = strpos($invoice_handle, '-')) {
      $invoice_handle =  substr($invoice_handle, 0, $pos);
    }

    $payment = $this->entityTypeManager->getStorage('commerce_payment')->loadByProperties(['order_id' => $invoice_handle]);
    $order =  $this->entityTypeManager->getStorage('commerce_order')->load($invoice_handle);

    if($order->get('checkout_step')->value == 'payment' &&  $order->get('state')->value == 'draft') {
      $order->set('checkout_step', 'complete');
      $event = new OrderEvent($order);
      $this->eventDispatcher->dispatch($event, CheckoutEvents::COMPLETION);
      $order->getState()->applyTransitionById('place');
      $order->unlock();
      $order->save();

      if($payment) {
        $payment = current($payment);
        $configuration = $payment->getPaymentGateway()->getPlugin()->getConfiguration();

        if ('test' == $configuration['mode']) {
          $key = $configuration['test_private_key'];
        } else {
          $key = $configuration['live_private_key'];
        }

        $this->reepayService->setPrivateKey($key);
        $invoice = $this->reepayService->getInvoice($decoded_result['invoice']);

        if ($invoice['state'] == 'settled' || $invoice['state'] == 'authorized') {
          $authorize_transition = $payment->getState()->getWorkflow()->getTransition('authorize');
          $payment->getState()->applyTransition($authorize_transition);
          $state = ($invoice['state'] == 'authorized') ? 'authorization' : 'completed';
          $payment->set('state', $state);
          $payment->set('amount', $order->getTotalPrice());
          $payment->set('payment_gateway', 'reepay_checkout');
          $payment->set('order_id', $order->id());
          $payment->set('test', $configuration['mode'] == 'test');
          $payment->set('remote_id', $decoded_result['invoice']);
          $payment->set('remote_state', $state);
          $request_time = \Drupal::time()->getRequestTime();
          $payment->set('authorized', $request_time);
          $payment->save();
        }

      }
    }

    return new \Symfony\Component\HttpFoundation\Response();
  }

  public function test() {
    echo $this->getNotifyUrl()->toString();
  }

  public function submitConfigurationForm(array &$form, FormStateInterface $form_state)
  {
    parent::submitConfigurationForm($form, $form_state);
    $values = $form_state->getValue($form['#parents']);
    $this->configuration['live_private_key'] = trim($values['live_private_key']);
    $this->configuration['test_private_key'] = trim($values['test_private_key']);
    $this->configuration['checkout_type'] = $values['checkout_type'];
    $this->configuration['locale'] = $values['locale'];
    $this->configuration['payment_method'] = $values['payment_method'];
    $this->configuration['instant_settle'] = $values['instant_settle'];
    $notifyUrl = $this->getNotifyUrl()->toString();

    if('test' == $this->configuration['mode']) {
      $key = $this->configuration['test_private_key'];
    } else {
      $key = $this->configuration['live_private_key'];
    }

    $this->reepayService->setPrivateKey($key);
    $result = $this->reepayService->updateWebhook($notifyUrl);

    if($result['saved'] == 'yes' ) {
       $this->messenger()->addMessage( 'Webhook was successfully saved in Reepay admin' );
    }
  }

  public function capturePayment(PaymentInterface $payment, Price $amount = NULL)
  {
    $paymentAmount = (int) $amount->getNumber() * 100;
    $configuration = $payment->getPaymentGateway()->getPlugin()->getConfiguration();

    if('test' == $configuration['mode']) {
      $key = $configuration['test_private_key'];
    } else {
      $key = $configuration['live_private_key'];
    }

    $this->reepayService->setPrivateKey($key);
    $return = $this->reepayService->settle($payment->getRemoteId(), $paymentAmount);

    if('success' == $return['result']) {
      $payment->setState('completed');
      $payment->setAmount($amount);
      $payment->save();
    } else {
      throw new InvalidRequestException('Could not capture the payment.' . $return['message']);
    }
  }

  public function refundPayment(PaymentInterface $payment, Price $amount = NULL)
  {
    $this->assertPaymentState($payment, ['completed', 'partially_refunded']);

    $amount = $amount ?: $payment->getAmount();
    $this->assertRefundAmount($payment, $amount);

    $configuration = $payment->getPaymentGateway()->getPlugin()->getConfiguration();

    if('test' == $configuration['mode']) {
      $key = $configuration['test_private_key'];
    } else {
      $key = $configuration['live_private_key'];
    }

    $this->reepayService->setPrivateKey($key);
    $old_refunded_amount = $payment->getRefundedAmount();
    $new_refunded_amount = $old_refunded_amount->add($amount);

    if ($new_refunded_amount->lessThan($payment->getAmount())) {
      $payment->setState('partially_refunded');
    }
    else {
      $payment->setState('refunded');
    }

    $paymentAmount = (int) $amount->getNumber() * 100;
    $return = $this->reepayService->refund($payment->getRemoteId(), $paymentAmount);
    if('success' == $return['result'] ) {
      $payment->setRefundedAmount($new_refunded_amount);
      $payment->save();
    } else {
      throw new InvalidRequestException('Could not refund the payment.' . $return['message']);
    }

  }

  public function voidPayment(PaymentInterface $payment)
  {
    $this->assertPaymentState($payment, ['authorization']);
    // Perform the void request here, throw an exception if it fails.
    // See \Drupal\commerce_payment\Exception for the available exceptions.
    $remote_id = $payment->getRemoteId();

    $configuration = $payment->getPaymentGateway()->getPlugin()->getConfiguration();

    if('test' == $configuration['mode']) {
      $key = $configuration['test_private_key'];
    } else {
      $key = $configuration['live_private_key'];
    }

    $this->reepayService->setPrivateKey($key);
    $return = $this->reepayService->void($payment->getRemoteId());

    if('success' == $return['result']) {
        $payment->setState('authorization_voided');
        $payment->save();
    } else {
        throw new InvalidRequestException('Could not void the payment.' . $return['message']);
    }
  }

  public function getNotifyUrl() {
    return Url::fromRoute('commerce_payment.notify', [
      'commerce_payment_gateway' => 'reepay_checkout',
    ], ['absolute' => TRUE]);

  }

}
