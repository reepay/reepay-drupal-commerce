<?php

namespace Drupal\Tests\commerce_reepay_checkout\Unit;

use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_price\Entity\CurrencyInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_reepay_checkout\Services\ReepayService;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(ReepayService::class)]
#[Group('commerce_reepay_checkout')]
class ReepayServiceTest extends UnitTestCase {

  /**
   * Builds a ReepayService with mocked dependencies.
   */
  private function createService(ClientInterface $client, CacheBackendInterface $cache, int $requestTime = 1000000): ReepayService {
    $channel = $this->createMock(LoggerChannelInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($channel);

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn($requestTime);

    return new ReepayService($loggerFactory, $cache, $time, $client);
  }

  public function testGetWebhookSecretFetchesAndCachesOnCacheMiss(): void {
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->once())
      ->method('request')
      ->with('GET', 'https://api.reepay.com/v1/account/webhook_settings')
      ->willReturn(new GuzzleResponse(200, [], json_encode(['secret' => 'top-secret'])));

    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->expects($this->once())->method('get')->willReturn(FALSE);
    $cache->expects($this->once())
      ->method('set')
      ->with($this->isType('string'), 'top-secret', 1000600);

    $service = $this->createService($client, $cache);

    $this->assertSame('top-secret', $service->getWebhookSecret());
  }

  public function testGetWebhookSecretReturnsCachedValueWithoutHttpCall(): void {
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->never())->method('request');

    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn((object) ['data' => 'cached-secret']);

    $service = $this->createService($client, $cache);

    $this->assertSame('cached-secret', $service->getWebhookSecret());
  }

  public function testGetWebhookSecretForceRefreshBypassesCacheRead(): void {
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->once())
      ->method('request')
      ->willReturn(new GuzzleResponse(200, [], json_encode(['secret' => 'fresh-secret'])));

    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->expects($this->never())->method('get');
    $cache->expects($this->once())->method('set');

    $service = $this->createService($client, $cache);

    $this->assertSame('fresh-secret', $service->getWebhookSecret(TRUE));
  }

  public function testGetWebhookSecretReturnsNullWhenRequestFails(): void {
    $client = $this->createMock(ClientInterface::class);
    $client->method('request')->willThrowException(
      new ConnectException('Network down', new GuzzleRequest('GET', 'https://api.reepay.com/v1/account/webhook_settings'))
    );

    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);
    $cache->expects($this->never())->method('set');

    $service = $this->createService($client, $cache);

    $this->assertNull($service->getWebhookSecret());
  }

  public function testGetWebhookSecretReturnsNullWhenResponseHasNoSecret(): void {
    $client = $this->createMock(ClientInterface::class);
    $client->method('request')->willReturn(new GuzzleResponse(200, [], json_encode([])));

    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);
    $cache->expects($this->never())->method('set');

    $service = $this->createService($client, $cache);

    $this->assertNull($service->getWebhookSecret());
  }

  public function testInvalidateWebhookSecretDeletesCacheEntry(): void {
    $client = $this->createMock(ClientInterface::class);
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->expects($this->once())->method('delete')->with($this->isType('string'));

    $service = $this->createService($client, $cache);
    $service->invalidateWebhookSecret();
  }

  /**
   * No cooldown active: the secret is invalidated and refetched, and the
   * cooldown is recorded so the next mismatch does not repeat this.
   */
  public function testRefreshWebhookSecretOncePerCooldownRefreshesWhenNoCooldownIsActive(): void {
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->once())
      ->method('request')
      ->willReturn(new GuzzleResponse(200, [], json_encode(['secret' => 'rotated-secret'])));

    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);
    $cache->expects($this->exactly(2))->method('set')
      ->with($this->isType('string'), $this->anything(), $this->isType('int'));
    $cache->expects($this->once())->method('delete')->with($this->isType('string'));

    $service = $this->createService($client, $cache, 1000000);

    $this->assertSame('rotated-secret', $service->refreshWebhookSecretOncePerCooldown());
  }

  /**
   * A cooldown already recorded: no cache write, no delete, no HTTP call —
   * repeated bad signatures cannot force unlimited refreshes.
   */
  public function testRefreshWebhookSecretOncePerCooldownDoesNothingWhileCooldownIsActive(): void {
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->never())->method('request');

    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn((object) ['data' => TRUE]);
    $cache->expects($this->never())->method('set');
    $cache->expects($this->never())->method('delete');

    $service = $this->createService($client, $cache);

    $this->assertNull($service->refreshWebhookSecretOncePerCooldown());
  }

  /**
   * FD-14: an event id is remembered for the full Frisbii retry period.
   */
  public function testMarkWebhookEventProcessedStoresTheIdForTheRetryPeriod(): void {
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->expects($this->once())->method('set')->with(
      ReepayService::WEBHOOK_EVENT_CACHE_ID_PREFIX . 'evt_1',
      TRUE,
      1000000 + ReepayService::WEBHOOK_EVENT_CACHE_TTL
    );

    $this->createService($this->createMock(ClientInterface::class), $cache, 1000000)->markWebhookEventProcessed('evt_1');
  }

  public function testIsWebhookEventProcessedIsTrueOnlyForARememberedId(): void {
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturnCallback(fn ($id) => $id === ReepayService::WEBHOOK_EVENT_CACHE_ID_PREFIX . 'seen' ? (object) ['data' => TRUE] : FALSE);

    $service = $this->createService($this->createMock(ClientInterface::class), $cache);

    $this->assertTrue($service->isWebhookEventProcessed('seen'));
    $this->assertFalse($service->isWebhookEventProcessed('new'));
  }

  /**
   * The outgoing payload keeps the customer and billing_address, but the
   * copy written to the log has both replaced, since name/email/address
   * has no place accumulating in watchdog.
   */
  public function testCreateChargeSessionRedactsCustomerDataFromTheLogOnly(): void {
    $payload = [
      'order' => [
        'handle' => '42',
        'amount' => 3999,
        'currency' => 'USD',
        'customer' => ['first_name' => 'Jane', 'email' => 'jane@example.com'],
        'billing_address' => ['first_name' => 'Jane', 'address' => 'Street 1'],
      ],
    ];

    $sentBody = NULL;
    $client = new class($sentBody) implements ClientInterface {
      private $sentBodyRef;
      public function __construct(&$sentBodyRef) {
        $this->sentBodyRef = &$sentBodyRef;
      }
      public function post($uri, array $options = []) {
        $this->sentBodyRef = $options['body'] ?? NULL;
        return new GuzzleResponse(200, [], json_encode(['id' => 'cs_123']));
      }
      public function send(\Psr\Http\Message\RequestInterface $request, array $options = []): \Psr\Http\Message\ResponseInterface {
        throw new \LogicException('Not used by this test.');
      }
      public function sendAsync(\Psr\Http\Message\RequestInterface $request, array $options = []): \GuzzleHttp\Promise\PromiseInterface {
        throw new \LogicException('Not used by this test.');
      }
      public function request(string $method, $uri, array $options = []): \Psr\Http\Message\ResponseInterface {
        throw new \LogicException('Not used by this test.');
      }
      public function requestAsync(string $method, $uri, array $options = []): \GuzzleHttp\Promise\PromiseInterface {
        throw new \LogicException('Not used by this test.');
      }
      public function getConfig(?string $option = NULL): void {
        throw new \LogicException('Not used by this test.');
      }
    };

    $channel = $this->createMock(LoggerChannelInterface::class);
    $loggedPayload = NULL;
    $channel->expects($this->once())->method('info')->willReturnCallback(function ($message, $context) use (&$loggedPayload) {
      $loggedPayload = json_decode($context['@payload'], TRUE);
    });
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($channel);

    $time = $this->createMock(TimeInterface::class);
    $service = new ReepayService($loggerFactory, $this->createMock(CacheBackendInterface::class), $time, $client);

    $result = $service->createChargeSession($payload);

    $this->assertSame('success', $result['result']);
    $this->assertSame('[redacted]', $loggedPayload['order']['customer']);
    $this->assertSame('[redacted]', $loggedPayload['order']['billing_address']);
    $this->assertSame(3999, $loggedPayload['order']['amount']);
    $this->assertSame('USD', $loggedPayload['order']['currency']);

    $sentPayload = json_decode($sentBody, TRUE);
    $this->assertSame('Jane', $sentPayload['order']['customer']['first_name']);
    $this->assertSame('Street 1', $sentPayload['order']['billing_address']['address']);
  }

  public static function minorUnitsProvider(): array {
    return [
      'whole amount' => ['39.00', 'USD', 2, 3900],
      'cents are kept' => ['39.99', 'USD', 2, 3999],
      'single cent' => ['0.01', 'USD', 2, 1],
      'rounds half up' => ['10.005', 'USD', 2, 1001],
      'zero' => ['0', 'USD', 2, 0],
      'zero decimals' => ['1000', 'JPY', 0, 1000],
      'zero decimals, small amount' => ['199', 'JPY', 0, 199],
      'three decimals' => ['1.234', 'KWD', 3, 1234],
      'three decimals, whole amount' => ['2', 'KWD', 3, 2000],
    ];
  }

  #[DataProvider('minorUnitsProvider')]
  public function testToMinorUnitsFollowsTheCurrencyDecimals(string $number, string $currency, int $digits, int $expected): void {
    $this->assertSame($expected, ReepayService::toMinorUnits(new Price($number, $currency), $digits));
  }

  public function testToMinorUnitsForLoadsTheDecimalsFromCommerce(): void {
    $currency = $this->createMock(CurrencyInterface::class);
    $currency->method('getFractionDigits')->willReturn(0);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())->method('load')->with('JPY')->willReturn($currency);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('commerce_currency')->willReturn($storage);

    $this->assertSame(1000, ReepayService::toMinorUnitsFor(new Price('1000', 'JPY'), $entityTypeManager));
  }

  public function testUnknownCurrencyFailsInsteadOfUsingTwoDecimals(): void {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn(NULL);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('commerce_currency')->willReturn($storage);

    $this->expectException(PaymentGatewayException::class);
    ReepayService::toMinorUnitsFor(new Price('39.00', 'USD'), $entityTypeManager);
  }

}
