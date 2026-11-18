<?php

namespace Drupal\Tests\commerce_reepay_checkout\Unit;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_reepay_checkout\PluginForm\ReepayOffsiteRedirectForm;
use Drupal\commerce_reepay_checkout\Services\ReepayService;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Covers ReepayOffsiteRedirectForm::getOpenPayment(), the FD-11 fix.
 *
 * Before this fix, buildConfigurationForm() saved a brand-new
 * commerce_payment entity every time the payment page was built (every
 * view/reload/back navigation), leaving duplicates behind. getOpenPayment()
 * must now reuse an existing "new" payment for the order/gateway instead.
 */
#[CoversClass(ReepayOffsiteRedirectForm::class)]
#[Group('commerce_reepay_checkout')]
class ReepayOffsiteRedirectFormTest extends UnitTestCase {

  private function createForm(EntityTypeManagerInterface $entityTypeManager): ReepayOffsiteRedirectForm {
    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $reepayService = $this->createMock(ReepayService::class);
    return new ReepayOffsiteRedirectForm($languageManager, $reepayService, $entityTypeManager);
  }

  private function invokeGetOpenPayment(ReepayOffsiteRedirectForm $form, PaymentInterface $newPayment): PaymentInterface {
    $reflection = new \ReflectionMethod($form, 'getOpenPayment');
    $reflection->setAccessible(TRUE);
    return $reflection->invoke($form, $newPayment);
  }

  private function newUnsavedPayment(int $orderId = 26, string $gatewayId = 'frisbii_pay') {
    $order = $this->createMock(OrderInterface::class);
    $order->method('id')->willReturn($orderId);

    // Price is a final value object and cannot be mocked; use a real one.
    $amount = new Price('10.00', 'USD');

    $payment = $this->createMock(PaymentInterface::class);
    $payment->method('getOrder')->willReturn($order);
    $payment->method('getPaymentGatewayId')->willReturn($gatewayId);
    $payment->method('getAmount')->willReturn($amount);

    return $payment;
  }

  public function testNoExistingPaymentReturnsTheNewOneUnchanged(): void {
    $newPayment = $this->newUnsavedPayment();

    $paymentStorage = $this->createMock(EntityStorageInterface::class);
    $paymentStorage->expects($this->once())->method('loadByProperties')->with([
      'order_id' => 26,
      'payment_gateway' => 'frisbii_pay',
      'state' => 'new',
    ])->willReturn([]);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('commerce_payment')->willReturn($paymentStorage);

    $form = $this->createForm($entityTypeManager);
    $result = $this->invokeGetOpenPayment($form, $newPayment);

    $this->assertSame($newPayment, $result);
  }

  public function testExistingNewPaymentIsReusedInsteadOfTheFreshOne(): void {
    $newPayment = $this->newUnsavedPayment();
    $newAmount = $newPayment->getAmount();

    $existingPayment = $this->createMock(PaymentInterface::class);
    $existingPayment->expects($this->once())->method('set')->with('amount', $newAmount);

    $paymentStorage = $this->createMock(EntityStorageInterface::class);
    $paymentStorage->method('loadByProperties')->willReturn([3 => $existingPayment]);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('commerce_payment')->willReturn($paymentStorage);

    $form = $this->createForm($entityTypeManager);
    $result = $this->invokeGetOpenPayment($form, $newPayment);

    $this->assertSame($existingPayment, $result);
  }

  public function testMultipleExistingNewPaymentsReusesTheMostRecentOne(): void {
    $newPayment = $this->newUnsavedPayment();

    // Simulates two duplicate "new" payments left behind before this fix
    // (one per payment-page build). The one with the higher entity id was
    // created later and must be the one reused.
    $olderPayment = $this->createMock(PaymentInterface::class);
    $olderPayment->expects($this->never())->method('set');

    $newerPayment = $this->createMock(PaymentInterface::class);
    $newerPayment->expects($this->once())->method('set');

    $paymentStorage = $this->createMock(EntityStorageInterface::class);
    // Deliberately returned with the newer (higher-id) payment first and
    // keyed out of order, to prove the selection sorts by entity id rather
    // than trusting array order.
    $paymentStorage->method('loadByProperties')->willReturn([11 => $newerPayment, 4 => $olderPayment]);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('commerce_payment')->willReturn($paymentStorage);

    $form = $this->createForm($entityTypeManager);
    $result = $this->invokeGetOpenPayment($form, $newPayment);

    $this->assertSame($newerPayment, $result);
  }

}
