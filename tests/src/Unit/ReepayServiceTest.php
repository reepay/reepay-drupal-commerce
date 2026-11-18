<?php

namespace Drupal\Tests\commerce_reepay_checkout\Unit;

use Drupal\commerce_price\Price;
use Drupal\commerce_reepay_checkout\Services\ReepayService;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
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

  public static function minorUnitsProvider(): array {
    return [
      'whole amount' => ['39.00', 3900],
      'cents are kept' => ['39.99', 3999],
      'single cent' => ['0.01', 1],
      'rounds half up' => ['10.005', 1001],
      'zero' => ['0', 0],
    ];
  }

  #[DataProvider('minorUnitsProvider')]
  public function testToMinorUnitsKeepsTheDecimals(string $number, int $expected): void {
    $this->assertSame($expected, ReepayService::toMinorUnits(new Price($number, 'USD')));
  }

}
