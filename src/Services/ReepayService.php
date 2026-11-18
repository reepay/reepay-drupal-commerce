<?php

namespace Drupal\commerce_reepay_checkout\Services;

use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_price\Calculator;
use Drupal\commerce_price\Entity\CurrencyInterface;
use Drupal\commerce_price\Price;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use GuzzleHttp\Exception\ClientException;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

class ReepayService
{
  /**
   * How long the webhook secret may be cached, in seconds.
   */
  const WEBHOOK_SECRET_CACHE_TTL = 600;

  /**
   * The cache ID prefix used to store the webhook secret.
   */
  const WEBHOOK_SECRET_CACHE_ID_PREFIX = 'commerce_reepay_checkout:webhook_secret:';

   /**
   * @var ClientInterface
   */
   private $client;

  /**
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
   private $logger;

  /**
   * @var CacheBackendInterface
   */
   private $cache;

  /**
   * @var TimeInterface
   */
   private $time;

  /**
   * @var array
   */
   private $payload;

  /**
   * @param LoggerChannelFactoryInterface $loggerChannelFactory
   * @param CacheBackendInterface $cache
   * @param TimeInterface $time
   * @param ClientInterface|null $client
   */
   public function __construct(LoggerChannelFactoryInterface $loggerChannelFactory, CacheBackendInterface $cache, TimeInterface $time, ?ClientInterface $client = NULL) {
      $this->logger = $loggerChannelFactory->get('commerce_reepay_checkout');
      $this->cache = $cache;
      $this->time = $time;
      $this->client = $client ?: new Client();
      $this->payload['headers'] = [
        'Accept' => 'application/json',
        'Content-Type' => 'application/json'
      ];
   }

   public function setPrivateKey($key) {
      $this->payload['headers']['Authorization'] = 'Basic ' . base64_encode($key . ':');
   }

   public function createChargeSession(array $payload) {
       try {
            $this->logger->info('Create charge session payload: @payload', ['@payload' => Json::encode($payload)]);

            $this->payload['body'] = Json::encode($payload);
            $this->payload['headers']['User-Agent'] = 'drupal_commerce/1.0.0';
            $response = $this->client->post('https://checkout-api.reepay.com/v1/session/charge', $this->payload);

            $body = Json::decode($response->getBody());

            $return = ['result' => 'success', 'id' => $body['id']];

          } catch(ClientException $ex) {
              $return = ['result' => 'error', 'message', $ex->getMessage()];
              $message = $ex->getResponse()->getBody();
              if($message) {
                $message = Json::decode($message);
                $error = $message['error'];
              }
          } catch (\Exception $ex) {
              $return = ['result' => 'error', 'message', $ex->getMessage()];
              $error = $ex->getMessage();
          }

          if(!empty($error)) {
            $this->logger->error('Create charge session exception: ' . $error);
          }

          return $return;
    }

  /**
   * Converts a price to the integer minor units Frisbii uses.
   *
   * Frisbii counts an amount in the smallest unit of the currency, so the
   * factor is 10 to the power of the currency's decimals (USD 2, JPY 0,
   * KWD 3). Keeps the decimals: 39.99 USD becomes 3999.
   */
  public static function toMinorUnits(Price $price, int $fractionDigits): int {
    $factor = '1' . str_repeat('0', max(0, $fractionDigits));
    return (int) Calculator::round(Calculator::multiply($price->getNumber(), $factor), 0);
  }

  /**
   * Returns the number of decimals of the currency of a price.
   *
   * Fails closed: an unknown currency throws instead of using a default.
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   */
  public static function fractionDigitsFor(Price $price, EntityTypeManagerInterface $entityTypeManager): int {
    $currency = $entityTypeManager->getStorage('commerce_currency')->load($price->getCurrencyCode());
    if (!$currency instanceof CurrencyInterface) {
      throw new PaymentGatewayException(sprintf('The currency %s is not available in Commerce.', $price->getCurrencyCode()));
    }
    return (int) $currency->getFractionDigits();
  }

  /**
   * Converts a price to Frisbii minor units using its currency's decimals.
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   */
  public static function toMinorUnitsFor(Price $price, EntityTypeManagerInterface $entityTypeManager): int {
    return self::toMinorUnits($price, self::fractionDigitsFor($price, $entityTypeManager));
  }

  /**
   * @param $handle
   * @return void | array
   */
  public function getInvoice($handle)
  {
       try {
           $result = $this->client->get("https://api.reepay.com/v1/invoice/{$handle}", $this->payload);
           return Json::decode($result->getBody());
       } catch(\Exception $e) {
           $this->logger->error('Get invoice @handle failed: @message', [
             '@handle' => $handle,
             '@message' => $e->getMessage(),
           ]);
           return NULL;
       }
  }

  /**
   * @param string $invoice
   * @param $amount
   * @return array|string[]
   */
  public function settle(string $invoice, $amount)
  {
    try {
      $this->payload['body'] = Json::encode(['amount' => $amount]);
      $this->client->post("https://api.reepay.com/v1/charge/{$invoice}/settle", $this->payload);
      return ['result' => 'success'];
    } catch (\Exception $e) {
      return ['result'=>'error', 'message' => $e->getMessage()];
    }
   }

  /**
   * @param string $invoice
   * @param $amount
   * @return array|string[]
   */
  public function refund(string $invoice, $amount)
  {
    try {
         $this->payload['body'] = Json::encode(['invoice' => $invoice, 'amount' => $amount]);
         $this->client->post('https://api.reepay.com/v1/refund', $this->payload);
         return ['result' => 'success'];
    } catch(\Exception $e) {
         return ['resul' => 'error', 'message' => $e->getMessage()];
    }

  }

  /**
   * Retrieves the webhook secret, using a short-lived cache.
   *
   * @param bool $forceRefresh
   *   Bypass the cache and fetch a fresh secret from Frisbii.
   *
   * @return string|null
   *   The webhook secret, or NULL when it could not be retrieved.
   */
  public function getWebhookSecret(bool $forceRefresh = FALSE): ?string
  {
    $cacheId = $this->getWebhookSecretCacheId();

    if (!$forceRefresh) {
      $cached = $this->cache->get($cacheId);
      if ($cached && !empty($cached->data)) {
        return $cached->data;
      }
    }

    try {
      $result = $this->client->request('GET', 'https://api.reepay.com/v1/account/webhook_settings', $this->payload);
      $result_decoded = Json::decode($result->getBody());
    } catch (\Exception $e) {
      $this->logger->error('Could not retrieve webhook settings: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }

    if (empty($result_decoded['secret'])) {
      $this->logger->error('Webhook settings response did not contain a secret.');
      return NULL;
    }

    $this->cache->set($cacheId, $result_decoded['secret'], $this->time->getRequestTime() + self::WEBHOOK_SECRET_CACHE_TTL);

    return $result_decoded['secret'];
  }

  /**
   * Invalidates the cached webhook secret.
   */
  public function invalidateWebhookSecret(): void
  {
    $this->cache->delete($this->getWebhookSecretCacheId());
  }

  /**
   * Builds the cache ID for the webhook secret of the currently set key.
   */
  private function getWebhookSecretCacheId(): string
  {
    $authorization = $this->payload['headers']['Authorization'] ?? '';
    return self::WEBHOOK_SECRET_CACHE_ID_PREFIX . hash('sha256', $authorization);
  }

  /**
   * Writes a non-sensitive trace line for the webhook flow.
   *
   * Never pass the private key, webhook secret, raw signature, or full
   * request payload here.
   *
   * @param string $message
   *   The log message, with @placeholder tokens.
   * @param array $context
   *   Values for the message's @placeholder tokens.
   * @param string $level
   *   One of 'info', 'warning' or 'error'.
   */
  public function logWebhookEvent(string $message, array $context = [], string $level = 'info'): void
  {
    switch ($level) {
      case 'warning':
        $this->logger->warning($message, $context);
        break;

      case 'error':
        $this->logger->error($message, $context);
        break;

      default:
        $this->logger->info($message, $context);
    }
  }

  public function updateWebhook(string $notifyUrl)
  {
        try {
          $result = $this->client->get('https://api.reepay.com/v1/account/webhook_settings', $this->payload);
          $result_decoded = Json::decode($result->getBody());
          $webhookUrlIsSet = false;
          foreach($result_decoded['urls'] as $url) {
            if($notifyUrl == $url) {
              $webhookUrlIsSet = true;
            }
          }

          if(!$webhookUrlIsSet) {
            $result_decoded['urls'][] = $notifyUrl;
            $result_decoded['disabled'] = false;
            $this->payload['body'] = Json::encode($result_decoded);
            $result = $this->client->put("https://api.reepay.com/v1/account/webhook_settings", $this->payload);
            if($result->getStatusCode() == 200 ) {
                return ['saved' => 'yes'];
            }
          }

        } catch(\Exception $e) {

        }
        return ['saved' => 'no'];
  }

  public function void(string $invoice)
  {
    try {
        $this->client->post('https://api.reepay.com/v1/charge/' . $invoice . '/cancel', $this->payload);
        return ['result' => 'success'];
    } catch(\Exception $e) {
        return ['resul' => 'error', 'message' => $e->getMessage()];
    }
  }
}
