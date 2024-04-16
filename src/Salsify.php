<?php

namespace Drupal\salsify_integration;


use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\TypedData\Exception\MissingDataException;
use GuzzleHttp\Client;
use Drupal\field\Entity\FieldConfig;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\Exception\RequestException;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Cache\CacheBackendInterface;

/**
 * Class Salsify.
 *
 * @package Drupal\salsify_integration
 */
class Salsify {

  /**
   * The configFactory interface.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The Entity Type Manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger interface.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * HTTPClient object.
   *
   * @var GuzzleHttp\Client
   */
  private $httpClient;

  /**
   * The Entity Field Manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The queue factory.
   *
   * @var Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * The cache object associated with the specified bin.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * Constructs a \Drupal\salsify_integration\Salsify object.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger
   *   The logger interface.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory interface.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \GuzzleHttp\Client $client
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param Drupal\Core\Queue\QueueFactory $queue
   *  The queue factory.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_salsify
   *   The cache object associated with the Salsify bin.
   */
  public function __construct(
    LoggerChannelFactoryInterface $logger,
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager,
    Client $client,
    EntityFieldManagerInterface $entity_field_manager,
    QueueFactory $queue,
    CacheBackendInterface $cache_salsify) {
    $this->logger = $logger;
    $this->configFactory = $config_factory->getEditable('salsify_integration.settings');
    $this->entityTypeManager = $entity_type_manager;
    $this->httpClient = $client;
    $this->entityFieldManager = $entity_field_manager;
    $this->queueFactory = $queue;
    $this->cache = $cache_salsify;
  }


  /**
   * Get the URL to the Salsify product channel.
   *
   * @return string
   *   A fully-qualified URL string.
   */
  protected function getUrl() {
    return $this->configFactory->get('product_feed_url');
  }

  /**
   * Get the Salsify user account access token to use with this integration.
   *
   * @return string
   *   The access token string.
   */
  protected function getAccessToken() {
    return $this->configFactory->get('token');
  }

  /**
   * Utility function to load product data from Salsify for further processing.
   *
   * @return array
   *   An array of raw, unprocessed product data. Empty if an error was found.
   */
  protected function getProductData() {
    $endpoint = $this->getUrl();
    $access_token = $this->getAccessToken();
    try {
      // Access the channel URL to fetch the newest product feed URL.
      $generate_product_feed = $this->httpClient->get($endpoint, [
        'headers' => [
          'Authorization' => 'Bearer ' . $access_token,
        ],
      ]);
      $response = $generate_product_feed->getBody()->getContents();
      $response_array = Json::decode($response);
      $feed_url = $response_array['product_export_url'];
      // Load the feed URL and data in order to get product and field data.
      $product_feed = $this->httpClient->get($feed_url);
      $product_results = Json::decode($product_feed->getBody()->getContents());
      // Remove the single-level nesting returned by Salsify to make it easier
      // to access the product data.
      $product_data = [];
      foreach ($product_results as $product_result) {
        $product_data = $product_data + $product_result;
      }

      if (isset($product_data['digital_assets'])) {
        // Rekey the Digital Assets by their Salsify ID to make looking them
        // up in later calls easier.
        $product_data['digital_assets'] = $this->rekeyArray($product_data['digital_assets'], 'salsify:id');
      }
      // Add the newly updated product data into the site cache.
      $this->cache->set('salsify_import_product_data', $product_data);

      return $product_data;
    }
    catch (RequestException $e) {
      $this->logger->get('salsify_integration')->notice('Could not make GET request to %endpoint because of error "%error".', ['%endpoint' => $endpoint, '%error' => $e->getMessage()]);
    }
  }

  /**
   * Utility function to load a content types configurable fields.
   *
   * @return array
   *   An array of field objects.
   */
  public function getContentTypeFields() {
    $fields = $this->entityFieldManager->getFieldDefinitions('node', 'products');
    $filtered_fields = array_filter(
      $fields, function ($field_definition) {
        return $field_definition instanceof FieldConfig;
      }
    );
    return $filtered_fields;
  }

  /**
   * Utility function to rekey a nested array using one of its subvalues.
   *
   * @param array $array
   *   An array of arrays.
   * @param string $key
   *   The key in the subarray to use as the key on $array.
   *
   * @return array|bool
   *   The newly keyed array or FALSE if the key wasn't found.
   */
  public function rekeyArray(array $array, $key) {
    $new_array = [];
    foreach ($array as $entry) {
      if (is_array($entry) && isset($entry[$key])) {
        $new_array[$entry[$key]] = $entry;
      }
      else {
        break;
      }
    }

    return $new_array;

  }

}
