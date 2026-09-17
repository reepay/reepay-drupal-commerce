<?php

namespace Drupal\Tests\commerce_reepay_checkout\Unit;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_reepay_checkout\Plugin\Commerce\PaymentGateway\Reepay;
use Drupal\commerce_reepay_checkout\Services\ReepayService;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\state_machine\Plugin\Field\FieldType\StateItemInterface;
use Drupal\state_machine\Plugin\Workflow\WorkflowInterface;
use Drupal\state_machine\Plugin\Workflow\WorkflowTransition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(Reepay::class)]
#[Group('commerce_reepay_checkout')]
class ReepayOnNotifyTest extends UnitTestCase {

  private const SECRET = 'whsec_test_secret';

  /**
   * Builds a Reepay gateway plugin instance with mocked collaborators.
   *
   * The 15-second delay at the top of onNotify() is stubbed out so tests
   * run instantly; every other collaborator is injected via reflection
   * since the plugin has no public setters for them.
   */
  private function createGateway(ReepayService $reepayService, ?EntityTypeManagerInterface $entityTypeManager = NULL, ?EventDispatcherInterface $eventDispatcher = NULL): Reepay {
    $configuration = [
      'mode' => 'test',
      'test_private_key' => 'test-key',
      'live_private_key' => 'live-key',
    ];

    /** @var \Drupal\commerce_reepay_checkout\Plugin\Commerce\PaymentGateway\Reepay $gateway */
    $gateway = $this->getMockBuilder(Reepay::class)
      ->setConstructorArgs([$configuration, 'reepay_checkout', []])
      ->onlyMethods(['sleepBeforeNotifyProcessing'])
      ->getMock();

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1700000000);

    $this->setInaccessibleProperty($gateway, 'reepayService', $reepayService);
    $this->setInaccessibleProperty($gateway, 'entityTypeManager', $entityTypeManager ?? $this->createMock(EntityTypeManagerInterface::class));
    $this->setInaccessibleProperty($gateway, 'eventDispatcher', $eventDispatcher ?? $this->createMock(EventDispatcherInterface::class));
    $this->setInaccessibleProperty($gateway, 'time', $time);

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
    $reepayService->expects($this->once())->method('invalidateWebhookSecret');

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
    $reepayService->expects($this->exactly(2))
      ->method('getWebhookSecret')
      ->willReturnCallback(function (bool $forceRefresh = FALSE) use ($rotatedSecret) {
        return $forceRefresh ? $rotatedSecret : self::SECRET;
      });
    $reepayService->expects($this->once())->method('invalidateWebhookSecret');

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
    ]);

    $gateway = $this->createGateway($reepayService, $entityTypeManager);
    $response = $gateway->onNotify($this->request($payload));

    $this->assertTrue($response->isSuccessful());
  }

  public function testValidSignatureAuthorizesPaymentForRecognizedEvent(): void {
    $payload = $this->signedPayload(['event_type' => 'invoice_authorized', 'invoice' => '17-abc']);

    $reepayService = $this->createReepayServiceMock();
    $reepayService->method('getWebhookSecret')->willReturn(self::SECRET);
    $reepayService->expects($this->once())->method('getInvoice')->with('17-abc')->willReturn(['state' => 'authorized']);

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
    $order->method('getTotalPrice')->willReturn(NULL);
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
    $paymentStorage->method('loadByProperties')->with(['order_id' => '17'])->willReturn([$payment]);

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

}
