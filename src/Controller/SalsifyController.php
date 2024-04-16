<?php

namespace Drupal\salsify_integration\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Queue\QueueFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Drupal\salsify_integration\Helpers\WebhookService;
use Drupal\Core\Url;
use GuzzleHttp\Client;
use Drupal\Component\Serialization\Json;

/**
 * Defines a controller for managing webhook notifications.
 */
class SalsifyController extends ControllerBase {

  /**
   * The HTTP request object.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * The queue factory.
   *
   * @var Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * HTTPClient object.
   *
   * @var GuzzleHttp\Client
   */
  private $httpClient;

  /**
   * The webhook helper.
   *
   * @var \Drupal\salsify_integration\Helpers\WebhookService
   */
  protected $webhookHelper;

  /**
   * Constructs a WebhookEntitiesController object.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *  The HTTP request object.
   * @param Drupal\Core\Queue\QueueFactory $queue
   *  The queue factory.
   * @param \Drupal\Core\Config\WebhookService $config_factory
   *   The webhook helper.
   * @param \GuzzleHttp\Client $client
   *   The entity type manager.
   */
  public function __construct(Request $request, QueueFactory $queue, WebhookService $webhook_helper, Client $client) {
    $this->request = $request;
    $this->queueFactory = $queue;
    $this->webhookHelper = $webhook_helper;
    $this->httpClient = $client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack')->getCurrentRequest(),
      $container->get('queue'),
      $container->get('salsify_integration.webhook_service'),
      $container->get('http_client')
    );
  }

  /**
   * Listens for webhook notifications and queues them for processing.
   *
   * @return Symfony\Component\HttpFoundation\Response
   *   Webhook providers typically expect an HTTP 200 (OK) response.
   */
  public function listener() {
    // Prepare the response.
     $response = new Response();

    // Capture the contents of the notification (payload).
    $payload = $this->request->getContent();
    $payload_array = Json::decode($payload);

    if ($payload_array['publication_status'] == 'completed') {
      $feed_url = $payload_array['product_feed_export_url'];
      // Load the feed URL and data in order to get product and field data.
      $product_feed = $this->httpClient->get($feed_url);
      $product_results = Json::decode($product_feed->getBody()->getContents());
      // Remove the single-level nesting returned by Salsify to make it easier
      // to access the product data.
      $product_data = [];
      foreach ($product_results as $product_result) {
        $product_data = $product_data + $product_result;
      }

      // Add each product value into a queue for background processing.
      /** @var \Drupal\Core\Queue\QueueInterface $queue */
      $queue = $this->queueFactory->get('salsify_integration_content_import');
      foreach ($product_data['products'] as $product) {
        $queue->createItem($product);
      }

    }

    // Respond with the success message.
    \Drupal::logger('migration__post-import')->notice('Salsify Data Received.');
    $response->setContent('Notification received');
    return $response;
  }

  /**
   * Checks access for incoming webhook notifications.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access() {
    return AccessResult::allowedIf($this->webhookHelper->verifyRequest($this->request));
  }

}
