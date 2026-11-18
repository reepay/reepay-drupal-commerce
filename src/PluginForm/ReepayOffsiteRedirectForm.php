<?php

namespace Drupal\commerce_reepay_checkout\PluginForm;

use Drupal\address\AddressInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm;
use Drupal\commerce_reepay_checkout\Services\ReepayService;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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
   * @var EntityTypeManagerInterface
   */
  private $entityTypeManager;

  /**
   *
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *
   **/
  public function __construct(LanguageManagerInterface $language_manager, ReepayService $reepayService, EntityTypeManagerInterface $entity_type_manager) {
     $this->reepayService = $reepayService;
     $this->languageManager = $language_manager;
     $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('language_manager'),
      $container->get('commerce_reepay_checkout.reepay_service'),
      $container->get('entity_type.manager'),
    );
  }

  public function buildConfigurationForm(array $form, FormStateInterface $form_state)
  {
    $form = parent::buildConfigurationForm($form, $form_state);
    $payment = $this->entity;
    $order = $payment->getOrder();
    $configuration = $payment->getPaymentGateway()->getPlugin()->getConfiguration();

    // Reuse an existing, unconfirmed payment for this order/gateway instead
    // of saving a new one every time this page is built (every view,
    // reload, or "back" navigation while the customer is on the payment
    // step would otherwise leave behind one more "new" payment record).
    $payment = $this->getOpenPayment($payment);
    $this->entity = $payment;
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

    $paymentAmount = ReepayService::toMinorUnitsFor($payment->getAmount(), $this->entityTypeManager);

    $address = $order->getBillingProfile()->address->first();

    $paymentGatewayPlugin = $payment->getPaymentGateway()->getPlugin();
    $payment_methods = $paymentGatewayPlugin->getReepayPaymentMethods();

    // Frisbii requires an explicit billing_address on the order object for
    // Klarna (a customer reference is not enough) and requires order_lines
    // for a Klarna payment. Frisbii accepts EITHER amount OR order_lines on
    // the order, never both (verified against its API: both together give
    // 422 "either amount or order_lines must be defined"). So order_lines
    // replaces amount only when a Klarna method is offered; every other
    // payment keeps the plain amount it always used. billing_address is
    // accepted alongside either, so it is always sent.
    $billingAddress = $this->buildBillingAddress($address, $order->getEmail());

    $orderAmount = $this->usesKlarna($payment_methods)
      ? ['order_lines' => $this->buildOrderLines($paymentAmount, $order->id())]
      : ['amount' => $paymentAmount];

    $payload = ['order' =>
                 ['handle' => $invoiceHandle] + $orderAmount + [
                  'locale' => $configuration['locale'],
                  'currency' => $payment->getAmount()->getCurrencyCode(),
                  'billing_address' => $billingAddress,
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
        throw new PaymentGatewayException( t('Error occurred during payment with Frisbii Payments checkout'));
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

  /**
   * Whether any of the payment methods offered in this session is Klarna.
   *
   * Covers the dedicated gateway ("klarna") and the legacy options of the
   * general gateway ("klarna_pay_later", "klarna_slice_it", ...).
   *
   * @param string[] $paymentMethods
   *   The payment_methods values sent to Frisbii.
   */
  private function usesKlarna(array $paymentMethods): bool {
    foreach ($paymentMethods as $method) {
      if (str_starts_with((string) $method, 'klarna')) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Builds the order-level billing_address Frisbii requires for Klarna.
   *
   * Klarna requires this explicitly; referencing the customer object is
   * not enough (see the 'customer' sub-object built alongside this, which
   * other payment methods also use).
   */
  private function buildBillingAddress(AddressInterface $address, string $email): array {
    $billingAddress = [
      'first_name' => $address->getGivenName(),
      'last_name' => $address->getFamilyName(),
      'address' => $address->getAddressLine1(),
      'city' => $address->getLocality(),
      'country' => $address->getCountryCode(),
      'postal_code' => $address->getPostalCode(),
      'email' => $email,
    ];
    if ($address->getAddressLine2()) {
      $billingAddress['address2'] = $address->getAddressLine2();
    }
    return $billingAddress;
  }

  /**
   * Builds the order_lines Frisbii requires for a Klarna payment.
   *
   * One line covering the whole order amount, rather than one line per
   * order item: it satisfies Klarna's requirement that order_lines be
   * present without risking a line-sum mismatch against the order's own
   * adjustments (tax, shipping, discounts) that are already folded into
   * $paymentAmount.
   */
  private function buildOrderLines(int $paymentAmount, $orderId): array {
    // Plain string, not t(): this is API data sent to Frisbii, never
    // rendered through Drupal, so it does not need translation and must
    // not depend on the translation service being available.
    return [[
      'ordertext' => 'Order #' . $orderId,
      'amount' => $paymentAmount,
      'quantity' => 1,
    ]];
  }

  /**
   * Finds an existing, not-yet-confirmed payment for this order/gateway.
   *
   * Commerce's PaymentProcess::createPayment() hands this form a brand-new,
   * unsaved payment object on every page build. Saving that object every
   * time (as this form used to do unconditionally) leaves behind one more
   * "new" payment record per view/reload/back-navigation. Reuse the most
   * recent "new" payment for this order and gateway instead, so at most one
   * exists at a time.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $newPayment
   *   The freshly created, unsaved payment object for this build.
   *
   * @return \Drupal\commerce_payment\Entity\PaymentInterface
   *   Either an existing "new" payment (with its amount refreshed from the
   *   order), or $newPayment itself when none exists yet.
   */
  private function getOpenPayment(PaymentInterface $newPayment): PaymentInterface {
    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    $existing = $payment_storage->loadByProperties([
      'order_id' => $newPayment->getOrder()->id(),
      'payment_gateway' => $newPayment->getPaymentGatewayId(),
      'state' => 'new',
    ]);

    if (!$existing) {
      return $newPayment;
    }

    ksort($existing);
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = end($existing);
    $payment->set('amount', $newPayment->getAmount());
    return $payment;
  }

}
