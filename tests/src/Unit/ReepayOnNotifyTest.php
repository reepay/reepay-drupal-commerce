<?php

namespace Drupal\Tests\commerce_reepay_checkout\Unit;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_price\Entity\CurrencyInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_reepay_checkout\Plugin\Commerce\PaymentGateway\ReepayGatewayBase;
use Drupal\commerce_reepay_checkout\Services\ReepayService;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\state_machine\Plugin\Field\FieldType\StateItemInterface;
use Drupal\state_machine\Plugin\Workflow\WorkflowInterface;
use Drupal\state_machine\Plugin\Workflow\WorkflowTransition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(ReepayGatewayBase::class)]
#[Group('commerce_reepay_checkout')]
class ReepayOnNotifyTest extends UnitTestCase {

  private const SECRET = 'whsec_test_secret';

  /**
   * The payment gateway config entity ID used by createGateway()'s mock
   * $this->parentEntity, and expected in the 'payment_gateway' property of
   * every commerce_payment loaded by onNotify().
   */
  private const GATEWAY_ID = 'frisbii_pay';

  /**
   * Builds a Reepay gateway plugin instance with mocked collaborators.
   *
   * The 15-second delay at the top of onNotify() is stubbed out so tests
   * run instantly; every other collaborator is injected via reflection
   * since the plugin has no public setters for them.
   */
  private function createGateway(ReepayService $reepayService, ?EntityTypeManagerInterface $entityTypeManager = NULL, ?EventDispatcherInterface $eventDispatcher = NULL, ?string $gatewayId = self::GATEWAY_ID): ReepayGatewayBase {
    $configuration = [
      'mode' => 'test',
      'test_private_key' => 'test-key',
      'live_private_key' => 'live-key',
    ];

    /** @var \Drupal\commerce_reepay_checkout\Plugin\Commerce\PaymentGateway\Reepay $gateway */
    $gateway = $this->getMockBuilder(ReepayGatewayBase::class)
      ->setConstructorArgs([$configuration, 'reepay_checkout', []])
      ->onlyMethods(['sleepBeforeNotifyProcessing', 'getReepayPaymentMethods'])
      ->getMock();

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1700000000);

    $this->setInaccessibleProperty($gateway, 'reepayService', $reepayService);
    $this->setInaccessibleProperty($gateway, 'entityTypeManager', $entityTypeManager ?? $this->createMock(EntityTypeManagerInterface::class));
    $this->setInaccessibleProperty($gateway, 'eventDispatcher', $eventDispatcher ?? $this->createMock(EventDispatcherInterface::class));
    $this->setInaccessibleProperty($gateway, 'time', $time);

    // Leaving $gatewayId NULL mimics the one call path where parentEntity
    // is genuinely unavailable (see buildNotifyUrlFromFormState()'s
    // docblock) so the fail-closed branch can be tested.
    if ($gatewayId !== NULL) {
      $parentEntity = $this->createMock(PaymentGatewayInterface::class);
      $parentEntity->method('id')->willReturn($gatewayId);
      $this->setInaccessibleProperty($gateway, 'parentEntity', $parentEntity);
    }

    return $gateway;
  }

  private function setInaccessibleProperty(object $object, string $property, $value): void {
    $reflection = new \ReflectionClass($object);
    while (!$reflection->hasProperty($property)) {
      $reflection = $reflection->getParentClass();
    }
    $prop = $reflection->getProperty($property);
    $prop->setAccessible(TRUE);
    $prop->setValue($object, $value);
  }

  private function createReepayServiceMock(): ReepayService {
    return $this->createMock(ReepayService::class);
  }

  private function signedPayload(array $overrides = []): array {
    $timestamp = '1700000000';
    $id = 'evt_123';
    $signature = hash_hmac('sha256', $timestamp . $id, self::SECRET);

    return array_merge([
      'timestamp' => $timestamp,
      'id' => $id,
      'signature' => $signature,
      'event_type' => 'invoice_authorized',
      'invoice' => '17',
    ], $overrides);
  }

  private function request(array $payload): Request {
    return new Request([], [], [], [], [], [], json_encode($payload));
  }

  /**
   * An authorized Frisbii invoice for the standard 39.00 USD test order.
   */
  private function paidInvoice(array $overrides = []): array {
    return array_merge(['state' => 'authorized', 'amount' => 3900, 'currency' => 'USD'], $overrides);
  }

  public function testMissingFieldIsRejectedWithoutTouchingSecretOrEntities(): void {
    $payload = $this->signedPayload();
    unset($payload['signature']);

    $reepayService = $this->createReepayServiceMock();
    $reepayService->expects($this->never())->method('getWebhookSecret');

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects($this->never())->method('getStorage');

    $gateway = $this->createGateway($reepayService, $entityTypeManager);
    $response = $gateway->onNotify($this->request($payload));

    $this->assertFalse($response->isSuccessful());
    $this->assertSame(400, $response->getStatusCode());
  }

  public function testInvalidSignatureIsRejectedWithoutTouchingEntities(): void {
    $payload = $this->signedPayload(['signature' => 'not-the-right-signature']);

    $reepayService = $this->createReepayServiceMock();
    $reepayService->method('getWebhookSecret')->willReturn(self::SECRET);
    $reepayService->expects($this->once())->method('refreshWebhookSecretOncePerCooldown')->willReturn(NULL);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects($this->never())->method('getStorage');

    $gateway = $this->createGateway($reepayService, $entityTypeManager);
    $response = $gateway->onNotify($this->request($payload));

    $this->assertFalse($response->isSuccessful());
    $this->assertSame(403, $response->getStatusCode());
  }

  public function testUnavailableWebhookSecretFailsClosed(): void {
    $payload = $this->signedPayload();

    $reepayService = $this->createReepayServiceMock();
    $reepayService->method('getWebhookSecret')->willReturn(NULL);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects($this->never())->method('getStorage');

    $gateway = $this->createGateway($reepayService, $entityTypeManager);
    $response = $gateway->onNotify($this->request($payload));

    $this->assertFalse($response->isSuccessful());
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('', $response->getContent());
  }

  public function testSecretRotationRetriesOnceThenSucceeds(): void {
    $rotatedSecret = 'whsec_rotated_secret';
    $payload = $this->signedPayload([
      'signature' => hash_hmac('sha256', '1700000000evt_123', $rotatedSecret),
      'event_type' => 'invoice_refund',
    ]);

    $reepayService = $this->createReepayServiceMock();
    $reepayService->expects($this->once())->method('getWebhookSecret')->willReturn(self::SECRET);
    $reepayService->expects($this->once())->method('refreshWebhookSecretOncePerCooldown')->willReturn($rotatedSecret);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects($this->never())->method('getStorage');

    $gateway = $this->createGateway($reepayService, $entityTypeManager);
    $response = $gateway->onNotify($this->request($payload));

    $this->assertTrue($response->isSuccessful());
  }

  public function testUnrecognizedEventTypeIsAcknowledgedWithoutMutatingOrder(): void {
    $payload = $this->signedPayload(['event_type' => 'invoice_cancelled']);

    $reepayService = $this->createReepayServiceMock();
    $reepayService->method('getWebhookSecret')->willReturn(self::SECRET);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects($this->never())->method('getStorage');

    $gateway = $this->createGateway($reepayService, $entityTypeManager);
    $response = $gateway->onNotify($this->request($payload));

    $this->assertTrue($response->isSuccessful());
  }

  public function testUnknownOrderIsAcknowledgedWithoutError(): void {
    $payload = $this->signedPayload();

    $reepayService = $this->createReepayServiceMock();
    $reepayService->method('getWebhookSecret')->willReturn(self::SECRET);

    $orderStorage = $this->createMock(EntityStorageInterface::class);
    $orderStorage->expects($this->once())->method('load')->with('17')->willReturn(NULL);

    $paymentStorage = $this->createMock(EntityStorageInterface::class);
    $paymentStorage->expects($this->never())->method('loadByProperties');

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturnMap([
      ['commerce_order', $orderStorage],
      ['commerce_payment', $paymentStorage],
      ['commerce_currency', $this->currencyStorage($currencies ?? NULL)],
    ]);

    $gateway = $this->createGateway($reepayService, $entityTypeManager);
    $response = $gateway->onNotify($this->request($payload));

    $this->assertTrue($response->isSuccessful());
  }

  public function testValidSignatureAuthorizesPaymentForRecognizedEvent(): void {
    $payload = $this->signedPayload(['event_type' => 'invoice_authorized', 'invoice' => '17-abc']);

    $reepayService = $this->createReepayServiceMock();
    $reepayService->method('getWebhookSecret')->willReturn(self::SECRET);
    $reepayService->expects($this->once())->method('getInvoice')->with('17-abc')->willReturn($this->paidInvoice());

    $orderState = $this->createMock(StateItemInterface::class);
    $orderState->expects($this->once())->method('applyTransitionById')->with('place');

    $order = $this->createMock(OrderInterface::class);
    $checkoutStepField = (object) ['value' => 'payment'];
    $stateField = (object) ['value' => 'draft'];
    $order->method('get')->willReturnMap([
      ['checkout_step', $checkoutStepField],
      ['state', $stateField],
    ]);
    $order->method('getState')->willReturn($orderState);
    $order->method('getTotalPrice')->willReturn(new Price('39.00', 'USD'));
    $order->method('id')->willReturn(17);
    $order->expects($this->once())->method('save');
    $order->expects($this->once())->method('unlock');

    $orderStorage = $this->createMock(EntityStorageInterface::class);
    $orderStorage->method('load')->with('17')->willReturn($order);

    $transition = $this->createMock(WorkflowTransition::class);
    $workflow = $this->createMock(WorkflowInterface::class);
    $workflow->method('getTransition')->with('authorize')->willReturn($transition);

    $paymentState = $this->createMock(StateItemInterface::class);
    $paymentState->method('getWorkflow')->willReturn($workflow);
    $paymentState->expects($this->once())->method('applyTransition')->with($transition);

    $payment = $this->createMock(PaymentInterface::class);
    $payment->method('getState')->willReturn($paymentState);
    $payment->expects($this->once())->method('save');

    $paymentStorage = $this->createMock(EntityStorageInterface::class);
    $paymentStorage->method('loadByProperties')->with(['order_id' => '17', 'payment_gateway' => self::GATEWAY_ID])->willReturn([$payment]);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturnMap([
      ['commerce_order', $orderStorage],
      ['commerce_payment', $paymentStorage],
      ['commerce_currency', $this->currencyStorage($currencies ?? NULL)],
    ]);

    $gateway = $this->createGateway($reepayService, $entityTypeManager);
    $response = $gateway->onNotify($this->request($payload));

    $this->assertTrue($response->isSuccessful());
  }

  /**
   * Verifies every logging call point runs, and that none of them ever
   * carries the webhook secret or the raw signature.
   */
  public function testLoggingCoversKeyDecisionPointsWithoutLeakingSecretOrSignature(): void {
    $forgedSignature = 'forged-signature-should-never-appear-in-logs';
    $payload = $this->signedPayload(['signature' => $forgedSignature]);

    $loggedCalls = [];
    $recorder = function (string $message, array $context = [], string $level = 'info') use (&$loggedCalls) {
      $loggedCalls[] = [$message, $context, $level];
    };

    $reepayService = $this->createReepayServiceMock();
    $reepayService->method('getWebhookSecret')->willReturn(self::SECRET);
    $reepayService->method('logWebhookEvent')->willReturnCallback($recorder);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects($this->never())->method('getStorage');

    $gateway = $this->createGateway($reepayService, $entityTypeManager);
    $response = $gateway->onNotify($this->request($payload));

    $this->assertSame(403, $response->getStatusCode());
    $this->assertNotEmpty($loggedCalls, 'Expected at least one webhook log entry to be recorded.');

    $rejectionLog = array_filter($loggedCalls, fn ($call) => str_starts_with($call[0], 'Rejected: signature verification failed'));
    $this->assertCount(1, $rejectionLog, 'Expected exactly one signature-rejection log entry.');
    $this->assertSame('warning', reset($rejectionLog)[2]);

    foreach ($loggedCalls as [$message, $context]) {
      $this->assertStringNotContainsString(self::SECRET, $message);
      $this->assertStringNotContainsString($forgedSignature, $message);
      foreach ($context as $value) {
        $this->assertNotSame(self::SECRET, $value);
        $this->assertNotSame($forgedSignature, $value);
      }
    }
  }


  /**
   * Commerce currency storage mock: currency code => number of decimals.
   *
   * A code that is not in the list loads as NULL (unknown currency).
   */
  private function currencyStorage(?array $digitsByCode = NULL): EntityStorageInterface {
    $digitsByCode ??= ['USD' => 2, 'JPY' => 0, 'KWD' => 3];
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturnCallback(function (string $code) use ($digitsByCode) {
      if (!isset($digitsByCode[$code])) {
        return NULL;
      }
      $currency = $this->createMock(CurrencyInterface::class);
      $currency->method('getFractionDigits')->willReturn($digitsByCode[$code]);
      return $currency;
    });
    return $storage;
  }

  /**
   * Builds mocks for a draft order at the payment step that must NOT be placed.
   */
  private function notifyGatewayForUnplacedOrder(?array $invoiceResult, bool $withPayment = TRUE, int $orderId = 17, bool $expectInvoiceCall = TRUE, ?Price $total = NULL, ?array $currencies = NULL): array {
    $reepayService = $this->createReepayServiceMock();
    $reepayService->method('getWebhookSecret')->willReturn(self::SECRET);
    if ($expectInvoiceCall) {
      $reepayService->expects($this->once())->method('getInvoice')->willReturn($invoiceResult);
    }
    else {
      $reepayService->expects($this->never())->method('getInvoice');
    }

    $orderState = $this->createMock(StateItemInterface::class);
    $orderState->expects($this->never())->method('applyTransitionById');

    $order = $this->createMock(OrderInterface::class);
    $order->method('get')->willReturnMap([
      ['checkout_step', (object) ['value' => 'payment']],
      ['state', (object) ['value' => 'draft']],
    ]);
    $order->method('getState')->willReturn($orderState);
    $order->method('getTotalPrice')->willReturn($total ?? new Price('39.00', 'USD'));
    $order->method('id')->willReturn($orderId);
    $order->expects($this->never())->method('save');

    $orderStorage = $this->createMock(EntityStorageInterface::class);
    $orderStorage->method('load')->willReturn($order);

    $paymentState = $this->createMock(StateItemInterface::class);
    $paymentState->method('getId')->willReturn('new');

    $payment = $this->createMock(PaymentInterface::class);
    $payment->method('getState')->willReturn($paymentState);
    $payment->expects($this->never())->method('save');
    $paymentStorage = $this->createMock(EntityStorageInterface::class);
    $paymentStorage->method('loadByProperties')->willReturn($withPayment ? [$payment] : []);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturnMap([
      ['commerce_order', $orderStorage],
      ['commerce_payment', $paymentStorage],
      ['commerce_currency', $this->currencyStorage($currencies ?? NULL)],
    ]);

    return [$this->createGateway($reepayService, $entityTypeManager), $reepayService];
  }

  public function testUnpaidInvoiceDoesNotPlaceOrder(): void {
    [$gateway] = $this->notifyGatewayForUnplacedOrder(['state' => 'created']);
    $response = $gateway->onNotify($this->request($this->signedPayload()));
    $this->assertTrue($response->isSuccessful());
  }

  public function testMissingInvoiceDataDoesNotPlaceOrder(): void {
    [$gateway] = $this->notifyGatewayForUnplacedOrder(NULL);
    $response = $gateway->onNotify($this->request($this->signedPayload()));
    $this->assertTrue($response->isSuccessful());
  }

  public function testMissingPaymentEntityDoesNotPlaceOrder(): void {
    [$gateway] = $this->notifyGatewayForUnplacedOrder(['state' => 'settled'], FALSE, 17, FALSE);
    $response = $gateway->onNotify($this->request($this->signedPayload()));
    $this->assertTrue($response->isSuccessful());
  }

  /**
   * onNotify() is always reached through $payment_gateway->getPlugin(),
   * which sets $this->parentEntity; if that is ever unavailable, fail
   * closed instead of loading payments without scoping them to a gateway.
   */
  public function testOnNotifyFailsClosedWhenTheGatewayIsUnknown(): void {
    $payload = $this->signedPayload();

    $reepayService = $this->createReepayServiceMock();
    $reepayService->method('getWebhookSecret')->willReturn(self::SECRET);
    $reepayService->expects($this->never())->method('getInvoice');

    $order = $this->createMock(OrderInterface::class);
    $order->method('get')->willReturnMap([
      ['checkout_step', (object) ['value' => 'payment']],
      ['state', (object) ['value' => 'draft']],
    ]);
    $order->method('id')->willReturn(17);
    $order->expects($this->never())->method('save');

    $orderStorage = $this->createMock(EntityStorageInterface::class);
    $orderStorage->method('load')->willReturn($order);

    // commerce_payment must never be reached once the gateway is unknown.
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturnCallback(function (string $type) use ($orderStorage) {
      if ($type === 'commerce_order') {
        return $orderStorage;
      }
      throw new \LogicException("Unexpected getStorage('$type') after an unknown gateway should have stopped processing.");
    });

    $gateway = $this->createGateway($reepayService, $entityTypeManager, NULL, NULL);
    $response = $gateway->onNotify($this->request($payload));

    $this->assertTrue($response->isSuccessful());
  }

  /**
   * A payment that belongs to a different Frisbii gateway on the same
   * order (e.g. the general method and the dedicated Klarna gateway both
   * enabled) must never be confirmed by this gateway's notification.
   */
  public function testPaymentFromAnotherGatewayIsNotConfirmed(): void {
    $payload = $this->signedPayload(['event_type' => 'invoice_authorized', 'invoice' => '17']);

    $reepayService = $this->createReepayServiceMock();
    $reepayService->method('getWebhookSecret')->willReturn(self::SECRET);
    $reepayService->method('getInvoice')->with('17')->willReturn($this->paidInvoice());

    $orderState = $this->createMock(StateItemInterface::class);
    $order = $this->createMock(OrderInterface::class);
    $order->method('get')->willReturnMap([
      ['checkout_step', (object) ['value' => 'payment']],
      ['state', (object) ['value' => 'draft']],
    ]);
    $order->method('getState')->willReturn($orderState);
    $order->method('getTotalPrice')->willReturn(new Price('39.00', 'USD'));
    $order->method('id')->willReturn(17);
    $order->expects($this->never())->method('save');

    $orderStorage = $this->createMock(EntityStorageInterface::class);
    $orderStorage->method('load')->willReturn($order);

    // Only a payment on a different gateway exists for this order; the
    // query scoped to self::GATEWAY_ID must not see it.
    $paymentStorage = $this->createMock(EntityStorageInterface::class);
    $paymentStorage->method('loadByProperties')->with(['order_id' => '17', 'payment_gateway' => self::GATEWAY_ID])->willReturn([]);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturnMap([
      ['commerce_order', $orderStorage],
      ['commerce_payment', $paymentStorage],
    ]);

    $gateway = $this->createGateway($reepayService, $entityTypeManager);
    $response = $gateway->onNotify($this->request($payload));

    $this->assertTrue($response->isSuccessful());
  }

  /**
   * When an order ended up with more than one "new" payment (left behind by
   * repeated payment-page builds before the FD-11 fix, or simply not yet
   * cleaned up), onNotify() must confirm the most recently created "new"
   * one, not whichever loadByProperties() happens to return first.
   */
  public function testMultiplePaymentsPicksMostRecentNewOne(): void {
    $payload = $this->signedPayload(['event_type' => 'invoice_authorized', 'invoice' => '17']);

    $reepayService = $this->createReepayServiceMock();
    $reepayService->method('getWebhookSecret')->willReturn(self::SECRET);
    $reepayService->method('getInvoice')->with('17')->willReturn($this->paidInvoice());

    $orderState = $this->createMock(StateItemInterface::class);
    $order = $this->createMock(OrderInterface::class);
    $order->method('get')->willReturnMap([
      ['checkout_step', (object) ['value' => 'payment']],
      ['state', (object) ['value' => 'draft']],
    ]);
    $order->method('getState')->willReturn($orderState);
    $order->method('getTotalPrice')->willReturn(new Price('39.00', 'USD'));
    $order->method('id')->willReturn(17);

    $orderStorage = $this->createMock(EntityStorageInterface::class);
    $orderStorage->method('load')->with('17')->willReturn($order);

    $transition = $this->createMock(WorkflowTransition::class);
    $workflow = $this->createMock(WorkflowInterface::class);
    $workflow->method('getTransition')->with('authorize')->willReturn($transition);

    // An older "new" payment (lower entity id) that must NOT be touched.
    $staleState = $this->createMock(StateItemInterface::class);
    $staleState->method('getId')->willReturn('new');
    $stalePayment = $this->createMock(PaymentInterface::class);
    $stalePayment->method('getState')->willReturn($staleState);
    $stalePayment->expects($this->never())->method('save');

    // The most recently created "new" payment (higher entity id) — the one
    // actually used for the checkout attempt being confirmed now.
    $recentState = $this->createMock(StateItemInterface::class);
    $recentState->method('getId')->willReturn('new');
    $recentState->method('getWorkflow')->willReturn($workflow);
    $recentState->expects($this->once())->method('applyTransition')->with($transition);
    $recentPayment = $this->createMock(PaymentInterface::class);
    $recentPayment->method('getState')->willReturn($recentState);
    $recentPayment->expects($this->once())->method('save');

    $paymentStorage = $this->createMock(EntityStorageInterface::class);
    // Deliberately returned with the stale payment first, keyed by a lower
    // entity id, to prove the selection does not just take current().
    $paymentStorage->method('loadByProperties')->with(['order_id' => '17', 'payment_gateway' => self::GATEWAY_ID])
      ->willReturn([5 => $stalePayment, 9 => $recentPayment]);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturnMap([
      ['commerce_order', $orderStorage],
      ['commerce_payment', $paymentStorage],
      ['commerce_currency', $this->currencyStorage($currencies ?? NULL)],
    ]);

    $gateway = $this->createGateway($reepayService, $entityTypeManager);
    $response = $gateway->onNotify($this->request($payload));

    $this->assertTrue($response->isSuccessful());
  }

  public static function mismatchedInvoiceProvider(): array {
    return [
      'lower amount' => [['state' => 'authorized', 'amount' => 3800, 'currency' => 'USD']],
      'higher amount' => [['state' => 'authorized', 'amount' => 4000, 'currency' => 'USD']],
      'different currency' => [['state' => 'authorized', 'amount' => 3900, 'currency' => 'EUR']],
      'missing amount' => [['state' => 'authorized', 'currency' => 'USD']],
      'missing currency' => [['state' => 'authorized', 'amount' => 3900]],
      'amount as text' => [['state' => 'authorized', 'amount' => '3900', 'currency' => 'USD']],
      'settled without settled amount' => [['state' => 'settled', 'amount' => 3900, 'currency' => 'USD']],
      'settled with a lower settled amount' => [['state' => 'settled', 'amount' => 3900, 'currency' => 'USD', 'settled_amount' => 1000]],
    ];
  }

  #[DataProvider('mismatchedInvoiceProvider')]
  public function testInvoiceThatDoesNotCoverTheOrderDoesNotPlaceIt(array $invoice): void {
    [$gateway] = $this->notifyGatewayForUnplacedOrder($invoice);
    $response = $gateway->onNotify($this->request($this->signedPayload()));
    $this->assertTrue($response->isSuccessful());
  }

  /**
   * A total with cents must be compared in minor units without losing the
   * decimals: 39.99 is 3999, not 3900.
   */
  public function testOrderTotalWithCentsMatchesInvoiceInMinorUnits(): void {
    $this->assertOrderIsPlacedForTotal(new Price('39.99', 'USD'), 3999, 'USD');
  }

  /**
   * The invoice amount is in the smallest unit of the order currency, so the
   * factor follows the currency's decimals (FD-13).
   */
  public static function currencyUnitsProvider(): array {
    return [
      'two decimals' => ['39.99', 'USD', 3999],
      'zero decimals' => ['1000', 'JPY', 1000],
      'three decimals' => ['1.234', 'KWD', 1234],
    ];
  }

  #[DataProvider('currencyUnitsProvider')]
  public function testOrderIsPlacedWhenInvoiceUsesTheCurrencyOwnUnit(string $total, string $currency, int $invoiceAmount): void {
    $this->assertOrderIsPlacedForTotal(new Price($total, $currency), $invoiceAmount, $currency);
  }

  /**
   * The old fixed factor of 100 must no longer be accepted for JPY and KWD.
   */
  public static function oldFactorProvider(): array {
    return [
      'JPY times 100' => ['1000', 'JPY', 100000],
      'KWD times 100' => ['1.234', 'KWD', 123],
    ];
  }

  #[DataProvider('oldFactorProvider')]
  public function testInvoiceWithTheOldFactorOfOneHundredDoesNotPlaceTheOrder(string $total, string $currency, int $invoiceAmount): void {
    [$gateway] = $this->notifyGatewayForUnplacedOrder(
      $this->paidInvoice(['amount' => $invoiceAmount, 'currency' => $currency, 'settled_amount' => $invoiceAmount]),
      TRUE, 17, TRUE, new Price($total, $currency)
    );
    $this->assertTrue($gateway->onNotify($this->request($this->signedPayload()))->isSuccessful());
  }

  /**
   * An order currency that Commerce cannot load must not be guessed.
   */
  public function testUnknownCurrencyDoesNotPlaceTheOrder(): void {
    [$gateway] = $this->notifyGatewayForUnplacedOrder(
      $this->paidInvoice(['amount' => 3900, 'currency' => 'EUR', 'settled_amount' => 3900]),
      TRUE, 17, TRUE, new Price('39.00', 'EUR'), ['USD' => 2]
    );
    $this->assertTrue($gateway->onNotify($this->request($this->signedPayload()))->isSuccessful());
  }

  private function assertOrderIsPlacedForTotal(Price $total, int $invoiceAmount, string $currency): void {
    $payload = $this->signedPayload(['invoice' => '17']);

    $reepayService = $this->createReepayServiceMock();
    $reepayService->method('getWebhookSecret')->willReturn(self::SECRET);
    $reepayService->method('getInvoice')->willReturn($this->paidInvoice(['amount' => $invoiceAmount, 'currency' => $currency, 'settled_amount' => $invoiceAmount]));

    $orderState = $this->createMock(StateItemInterface::class);
    $orderState->expects($this->once())->method('applyTransitionById')->with('place');
    $order = $this->createMock(OrderInterface::class);
    $order->method('get')->willReturnMap([
      ['checkout_step', (object) ['value' => 'payment']],
      ['state', (object) ['value' => 'draft']],
    ]);
    $order->method('getState')->willReturn($orderState);
    $order->method('getTotalPrice')->willReturn($total);
    $order->method('id')->willReturn(17);
    $order->expects($this->once())->method('save');

    $orderStorage = $this->createMock(EntityStorageInterface::class);
    $orderStorage->method('load')->willReturn($order);

    $workflow = $this->createMock(WorkflowInterface::class);
    $workflow->method('getTransition')->willReturn($this->createMock(WorkflowTransition::class));
    $paymentState = $this->createMock(StateItemInterface::class);
    $paymentState->method('getId')->willReturn('new');
    $paymentState->method('getWorkflow')->willReturn($workflow);
    $payment = $this->createMock(PaymentInterface::class);
    $payment->method('getState')->willReturn($paymentState);
    $payment->expects($this->once())->method('save');
    $paymentStorage = $this->createMock(EntityStorageInterface::class);
    $paymentStorage->method('loadByProperties')->willReturn([$payment]);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturnMap([
      ['commerce_order', $orderStorage],
      ['commerce_payment', $paymentStorage],
      ['commerce_currency', $this->currencyStorage($currencies ?? NULL)],
    ]);

    $gateway = $this->createGateway($reepayService, $entityTypeManager);
    $this->assertTrue($gateway->onNotify($this->request($payload))->isSuccessful());
  }

  public function testInvalidSignatureDoesNotWait(): void {
    $reepayService = $this->createReepayServiceMock();
    $reepayService->method('getWebhookSecret')->willReturn(self::SECRET);
    $gateway = $this->getMockBuilder(ReepayGatewayBase::class)
      ->setConstructorArgs([['mode' => 'test', 'test_private_key' => 'k', 'live_private_key' => 'k'], 'reepay_checkout', []])
      ->onlyMethods(['sleepBeforeNotifyProcessing', 'getReepayPaymentMethods'])
      ->getMock();
    $gateway->expects($this->never())->method('sleepBeforeNotifyProcessing');
    $this->setInaccessibleProperty($gateway, 'reepayService', $reepayService);

    $response = $gateway->onNotify($this->request($this->signedPayload(['signature' => 'bad'])));
    $this->assertSame(403, $response->getStatusCode());
  }

}
