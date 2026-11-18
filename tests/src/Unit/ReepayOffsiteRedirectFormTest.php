<?php

namespace Drupal\Tests\commerce_reepay_checkout\Unit;

use Drupal\address\AddressInterface;
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

  private function invokePrivateMethod(ReepayOffsiteRedirectForm $form, string $method, array $args) {
    $reflection = new \ReflectionMethod($form, $method);
    $reflection->setAccessible(TRUE);
    return $reflection->invokeArgs($form, $args);
  }

  private function emptyForm(): ReepayOffsiteRedirectForm {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    return $this->createForm($entityTypeManager);
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

  /**
   * Covers buildBillingAddress(), the FD-13 Klarna fix: Frisbii requires an
   * explicit billing_address on the order object for Klarna, since a
   * customer reference is not enough.
   */
  public function testBuildBillingAddressMapsEveryAddressField(): void {
    $address = $this->createMock(AddressInterface::class);
    $address->method('getGivenName')->willReturn('Jane');
    $address->method('getFamilyName')->willReturn('Doe');
    $address->method('getAddressLine1')->willReturn('Main Street 1');
    $address->method('getAddressLine2')->willReturn('');
    $address->method('getLocality')->willReturn('Copenhagen');
    $address->method('getCountryCode')->willReturn('DK');
    $address->method('getPostalCode')->willReturn('1000');

    $result = $this->invokePrivateMethod($this->emptyForm(), 'buildBillingAddress', [$address, 'jane@example.com']);

    $this->assertSame([
      'first_name' => 'Jane',
      'last_name' => 'Doe',
      'address' => 'Main Street 1',
      'city' => 'Copenhagen',
      'country' => 'DK',
      'postal_code' => '1000',
      'email' => 'jane@example.com',
    ], $result);
  }

  public function testBuildBillingAddressIncludesAddressLine2WhenPresent(): void {
    $address = $this->createMock(AddressInterface::class);
    $address->method('getGivenName')->willReturn('Jane');
    $address->method('getFamilyName')->willReturn('Doe');
    $address->method('getAddressLine1')->willReturn('Main Street 1');
    $address->method('getAddressLine2')->willReturn('Suite 2');
    $address->method('getLocality')->willReturn('Copenhagen');
    $address->method('getCountryCode')->willReturn('DK');
    $address->method('getPostalCode')->willReturn('1000');

    $result = $this->invokePrivateMethod($this->emptyForm(), 'buildBillingAddress', [$address, 'jane@example.com']);

    $this->assertSame('Suite 2', $result['address2']);
  }

  /**
   * Covers buildOrderLines(), the FD-13 Klarna fix: Frisbii requires
   * order_lines to be present for a Klarna payment, and accepts them only
   * INSTEAD of the order amount. One line for the whole order, so it can
   * never disagree with the total.
   */
  public function testBuildOrderLinesCoversTheWholeOrderInOneLine(): void {
    $result = $this->invokePrivateMethod($this->emptyForm(), 'buildOrderLines', [3999, 42]);

    $this->assertCount(1, $result);
    $this->assertSame(3999, $result[0]['amount']);
    $this->assertSame(1, $result[0]['quantity']);
    $this->assertStringContainsString('42', $result[0]['ordertext']);
  }

  public static function klarnaMethodsProvider(): array {
    return [
      'dedicated Klarna gateway' => [['klarna'], TRUE],
      'legacy Klarna option among others' => [['card', 'klarna_pay_later'], TRUE],
      'only a Klarna slice option' => [['klarna_slice_it'], TRUE],
      'cards and wallets only' => [['card', 'visa', 'mc', 'click_to_pay', 'mobilepay'], FALSE],
      'no methods' => [[], FALSE],
    ];
  }

  /**
   * Frisbii accepts order_lines OR amount, never both, so the form must know
   * when a session involves Klarna to choose which one to send.
   */
  #[\PHPUnit\Framework\Attributes\DataProvider('klarnaMethodsProvider')]
  public function testUsesKlarnaDetectsAnyKlarnaMethod(array $methods, bool $expected): void {
    $this->assertSame($expected, $this->invokePrivateMethod($this->emptyForm(), 'usesKlarna', [$methods]));
  }

}
