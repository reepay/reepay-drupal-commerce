<?php

namespace Drupal\commerce_reepay_checkout\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_checkout\Event\CheckoutEvents;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Event\OrderEvent;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\InvalidRequestException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsAuthorizationsInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_reepay_checkout\Services\ReepayService;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared logic for all Frisbii Payments gateway plugins.
 *
 * Holds everything that isn't specific to *which* Frisbii payment method(s)
 * a gateway plugin offers: credentials, checkout-session return/notify
 * handling, and capture/refund/void. Subclasses only need to declare their
 * own payment method value(s) via getReepayPaymentMethods() and, for a
 * General payment method style gateway, their own multiselect configuration
 * field.
 */
abstract class ReepayGatewayBase extends OffsitePaymentGatewayBase implements SupportsRefundsInterface, SupportsAuthorizationsInterface
{
  /**
   * @var ConfigurableLanguageManagerInterface
   */
  private $languageManager;

  /**
   * @var ReepayService
   */
  protected $reepayService;
  /**
   * @var EventDispatcherInterface
   */
  private $eventDispatcher;

  /**
   * The Frisbii API payment method value(s) this gateway sends.
   *
   * @return string[]
   */
  abstract public function getReepayPaymentMethods(): array;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    /** @var static $instance */
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->reepayService = $container->get('commerce_reepay_checkout.reepay_service');
    $instance->eventDispatcher = $container->get('event_dispatcher');

    return $instance;
  }

  public function defaultConfiguration() {
    return [
        'live_private_key' => '',
        'test_private_key' => '',
        'checkout_type' => 'window',
        'locale' => 'en_US',
        'instant_settle' => 'no'
      ] + parent::defaultConfiguration();
  }

  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {

    $form = parent::buildConfigurationForm($form, $form_state);

    $form['live_private_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Live Private key'),
      '#description' => $this->t('This is the private key from the Frisbii Payments admin.'),
      '#default_value' => $this->configuration['live_private_key'],
      '#required' => TRUE,
    ];

    $form['test_private_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Test Private key'),
      '#description' => $this->t('This is the private key from the Frisbii Payments admin.'),
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

    return $form;
  }

  public function onReturn(OrderInterface $order, Request $request)
  {
    parent::onReturn( $order, $request);
    $invoiceHandle = (string) $request->get('invoice');
    $orderHandle = $invoiceHandle;
    if($pos = strpos($orderHandle, '-')) {
         $orderHandle =  substr($orderHandle, 0, $pos);
    }

    // The invoice must belong to the order that is being checked out.
    if ($orderHandle === '' || $orderHandle !== (string) $order->id()) {
      $this->reepayService->logWebhookEvent('Return rejected: invoice=@invoice does not match order_id=@order_id.', [
        '@invoice' => $invoiceHandle,
        '@order_id' => $order->id(),
      ], 'warning');
      throw new PaymentGatewayException('The invoice does not belong to this order.');
    }

    $payments = $this->entityTypeManager->getStorage('commerce_payment')->loadByProperties(['order_id' => $orderHandle]);
    if (!$payments) {
      $this->reepayService->logWebhookEvent('Return rejected: no payment found for order_id=@order_id.', [
        '@order_id' => $order->id(),
      ], 'warning');
      throw new PaymentGatewayException('No payment was found for this order.');
    }

    $payment = current($payments);
    $configuration = $payment->getPaymentGateway()->getPlugin()->getConfiguration();

    if('test' == $configuration['mode']) {
      $key = $configuration['test_private_key'];
    } else {
      $key = $configuration['live_private_key'];
    }
    $this->reepayService->setPrivateKey($key);
    $invoice = $this->reepayService->getInvoice($invoiceHandle);

    // Fail closed: no data (API error) or an unpaid invoice must not place the order.
    $invoiceState = is_array($invoice) ? ($invoice['state'] ?? NULL) : NULL;
    if (!in_array($invoiceState, ['settled', 'authorized'], TRUE)) {
      $this->reepayService->logWebhookEvent('Return rejected: invoice=@invoice is not authorized/settled (state=@state).', [
        '@invoice' => $invoiceHandle,
        '@state' => $invoiceState ?? 'unknown',
      ], 'warning');
      throw new PaymentGatewayException('Payment was not confirmed by the payment provider.');
    }

    $authorize_transition = $payment->getState()->getWorkflow()->getTransition('authorize');
    $payment->getState()->applyTransition($authorize_transition);
    $state = ($invoiceState == 'authorized') ? 'authorization' : 'completed';
    $payment->set('state', $state);
    $payment->set('amount', $order->getTotalPrice());
    $payment->set('order_id', $order->id());
    $payment->set('test', $configuration['mode'] == 'test');
    $payment->set('remote_id', $invoiceHandle);
    $payment->set('remote_state', $state);
    $request_time = \Drupal::time()->getRequestTime();
    $payment->set('authorized', $request_time);
    $payment->save();
  }

  /**
   * Event types that are allowed to authorize/complete a payment.
   */
  private const NOTIFY_EVENT_TYPES = ['invoice_authorized', 'invoice_settled'];

  /**
   * Fields required on every incoming webhook payload.
   */
  private const NOTIFY_REQUIRED_FIELDS = ['timestamp', 'id', 'signature', 'event_type', 'invoice'];

  public function onNotify(Request $request)
  {
    $decoded_result = Json::decode($request->getContent());
    $remoteAddr = $request->getClientIp() ?? 'unknown';

    if (!$this->validateWebhookPayload($decoded_result)) {
      $this->reepayService->logWebhookEvent('Rejected: request body is not valid JSON or is missing required fields. remote=@remote', ['@remote' => $remoteAddr], 'warning');
      return new Response('', 400);
    }

    $eventType = $decoded_result['event_type'];
    $eventId = $decoded_result['id'];
    $invoice = $decoded_result['invoice'];

    $this->reepayService->logWebhookEvent('Received: event_type=@event_type, id=@id, invoice=@invoice, remote=@remote', [
      '@event_type' => $eventType,
      '@id' => $eventId,
      '@invoice' => $invoice,
      '@remote' => $remoteAddr,
    ]);

    $mode = $this->configuration['mode'] ?? 'test';
    $key = ('test' === $mode) ? $this->configuration['test_private_key'] : $this->configuration['live_private_key'];
    $this->reepayService->setPrivateKey($key);

    if (!$this->verifyWebhookSignature($decoded_result)) {
      $this->reepayService->logWebhookEvent('Rejected: signature verification failed. event_type=@event_type, id=@id, invoice=@invoice', [
        '@event_type' => $eventType,
        '@id' => $eventId,
        '@invoice' => $invoice,
      ], 'warning');
      return new Response('', 403);
    }

    $this->reepayService->logWebhookEvent('Verified: signature OK. event_type=@event_type, id=@id, invoice=@invoice', [
      '@event_type' => $eventType,
      '@id' => $eventId,
      '@invoice' => $invoice,
    ]);

    if (!in_array($eventType, self::NOTIFY_EVENT_TYPES, TRUE)) {
      $this->reepayService->logWebhookEvent('Skipped: event_type=@event_type is not handled. id=@id, invoice=@invoice', [
        '@event_type' => $eventType,
        '@id' => $eventId,
        '@invoice' => $invoice,
      ]);
      return new Response();
    }

    // Only a verified, handled event reaches this point. The delay lets
    // onReturn() place the order first; unauthenticated requests never wait.
    $this->sleepBeforeNotifyProcessing();

    $invoice_handle = $invoice;

    if($pos = strpos($invoice_handle, '-')) {
      $invoice_handle =  substr($invoice_handle, 0, $pos);
    }

    $order = $this->entityTypeManager->getStorage('commerce_order')->load($invoice_handle);
    if (!$order) {
      $this->reepayService->logWebhookEvent('Stopped: order not found. invoice=@invoice', ['@invoice' => $invoice], 'warning');
      return new Response();
    }

    if($order->get('checkout_step')->value != 'payment' || $order->get('state')->value != 'draft') {
      $this->reepayService->logWebhookEvent('Skipped: order_id=@order_id (invoice=@invoice) is not in checkout_step=payment/state=draft, no update needed.', [
        '@order_id' => $order->id(),
        '@invoice' => $invoice,
      ]);
      return new Response();
    }

    // Fail closed: confirm payment with Frisbii before the order is placed.
    $payments = $this->entityTypeManager->getStorage('commerce_payment')->loadByProperties(['order_id' => $invoice_handle]);
    if (!$payments) {
      $this->reepayService->logWebhookEvent('Stopped: no payment found for order_id=@order_id (invoice=@invoice).', [
        '@order_id' => $order->id(),
        '@invoice' => $invoice,
      ], 'warning');
      return new Response();
    }
    $payment = current($payments);

    $invoiceData = $this->reepayService->getInvoice($invoice);
    $invoiceState = is_array($invoiceData) ? ($invoiceData['state'] ?? NULL) : NULL;
    if (!in_array($invoiceState, ['settled', 'authorized'], TRUE)) {
      $this->reepayService->logWebhookEvent('Stopped: invoice=@invoice is not authorized/settled (state=@state). Order not placed.', [
        '@invoice' => $invoice,
        '@state' => $invoiceState ?? 'unknown',
      ], 'warning');
      return new Response();
    }

    $order->set('checkout_step', 'complete');
    $event = new OrderEvent($order);
    $this->eventDispatcher->dispatch($event, CheckoutEvents::COMPLETION);
    $order->getState()->applyTransitionById('place');
    $order->unlock();
    $order->save();

    $this->reepayService->logWebhookEvent('Placed: order_id=@order_id (invoice=@invoice) moved to complete/place.', [
      '@order_id' => $order->id(),
      '@invoice' => $invoice,
    ]);

    $authorize_transition = $payment->getState()->getWorkflow()->getTransition('authorize');
    $payment->getState()->applyTransition($authorize_transition);
    $state = ($invoiceState == 'authorized') ? 'authorization' : 'completed';
    $payment->set('state', $state);
    $payment->set('amount', $order->getTotalPrice());
    $payment->set('order_id', $order->id());
    $payment->set('test', $mode == 'test');
    $payment->set('remote_id', $invoice);
    $payment->set('remote_state', $state);
    $payment->set('authorized', $this->time->getRequestTime());
    $payment->save();

    $this->reepayService->logWebhookEvent('Updated: payment for order_id=@order_id (invoice=@invoice) set to state=@state.', [
      '@order_id' => $order->id(),
      '@invoice' => $invoice,
      '@state' => $state,
    ]);

    return new Response();
  }

  /**
   * Wraps the notify-processing delay so tests can bypass it.
   */
  protected function sleepBeforeNotifyProcessing(): void {
    // waiting for onReturn to place an order first
    sleep(15);
  }

  /**
   * Validates that the decoded webhook payload has all required fields.
   *
   * @param mixed $data
   *   The JSON-decoded request body.
   */
  private function validateWebhookPayload($data): bool {
    if (!is_array($data)) {
      return FALSE;
    }

    foreach (self::NOTIFY_REQUIRED_FIELDS as $field) {
      if (!isset($data[$field]) || $data[$field] === '') {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Verifies the HMAC-SHA256 signature of a webhook payload.
   *
   * Retries once against a freshly-fetched secret on mismatch, to tolerate
   * secret rotation.
   */
  private function verifyWebhookSignature(array $data): bool {
    $secret = $this->reepayService->getWebhookSecret();
    if ($this->signatureMatches($data, $secret)) {
      return TRUE;
    }

    $this->reepayService->invalidateWebhookSecret();
    $secret = $this->reepayService->getWebhookSecret(TRUE);

    return $this->signatureMatches($data, $secret);
  }

  /**
   * Compares the supplied signature against the expected HMAC in constant time.
   */
  private function signatureMatches(array $data, ?string $secret): bool {
    if (empty($secret)) {
      return FALSE;
    }

    $expected = hash_hmac('sha256', $data['timestamp'] . $data['id'], $secret);

    return hash_equals($expected, (string) $data['signature']);
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
    $this->configuration['instant_settle'] = $values['instant_settle'];

    $notifyUrl = $this->buildNotifyUrlFromFormState($form_state);
    if (!$notifyUrl) {
      $this->messenger()->addError($this->t('Could not determine the payment gateway ID; the webhook URL was not registered with Frisbii Payments. Save the gateway again to retry.'));
      return;
    }

    if('test' == $this->configuration['mode']) {
      $key = $this->configuration['test_private_key'];
    } else {
      $key = $this->configuration['live_private_key'];
    }

    $this->reepayService->setPrivateKey($key);
    $result = $this->reepayService->updateWebhook($notifyUrl);

    if($result['saved'] == 'yes' ) {
       $this->messenger()->addMessage( 'Webhook was successfully saved in Frisbii Payments admin' );
    }
  }

  /**
   * Builds the absolute notify URL using the entity ID from the gateway's
   * own edit form.
   *
   * getNotifyUrl()/$this->parentEntity is not available here: Commerce's
   * PluginConfiguration inline form creates a standalone plugin instance to
   * run submitConfigurationForm() (see
   * \Drupal\commerce\Plugin\Commerce\InlineForm\PluginConfiguration::submitInlineForm()),
   * without the parent commerce_payment_gateway entity context.
   */
  private function buildNotifyUrlFromFormState(FormStateInterface $form_state): ?string {
    $formObject = $form_state->getFormObject();
    if (!$formObject instanceof EntityFormInterface) {
      return NULL;
    }

    $gatewayId = $formObject->getEntity()->id();
    if (empty($gatewayId)) {
      return NULL;
    }

    return Url::fromRoute('commerce_payment.notify', [
      'commerce_payment_gateway' => $gatewayId,
    ], ['absolute' => TRUE])->toString();
  }

  public function capturePayment(PaymentInterface $payment, ?Price $amount = NULL)
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

  public function refundPayment(PaymentInterface $payment, ?Price $amount = NULL)
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

}
