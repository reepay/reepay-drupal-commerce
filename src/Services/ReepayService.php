<?php

namespace Drupal\commerce_reepay_checkout\Services;

use GuzzleHttp\Client;
use Drupal\Component\Serialization\Json;
use GuzzleHttp\Exception\ClientException;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

class ReepayService
{
   /**
   * @var Client
   */
   private $client;

  /**
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
   private $logger;

  /**
   * @var array
   */
   private $payload;

  /**
   * @param LoggerChannelFactoryInterface $loggerChannelFactory
   */
   public function __construct(LoggerChannelFactoryInterface $loggerChannelFactory) {
      $this->logger = $loggerChannelFactory->get('commerce_reepay_checkout');
      $this->client = new Client();
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
   * @param $handle
   * @return void | array
   */
  public function getInvoice($handle)
  {
       try {
           $result = $this->client->get("https://api.reepay.com/v1/invoice/{$handle}", $this->payload);
           return Json::decode($result->getBody());
       } catch(\Exception $e) {

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
