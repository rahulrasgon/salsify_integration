<?php

namespace Drupal\salsify_integration;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\salsify_integration\Salsify;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\media\Entity\Media;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Url;

/**
 * Class SalsifyImportField.
 *
 * The class used to perform content imports in to individual fields. Imports
 * are triggered either through queues during a cron run or via the
 * configuration page.
 *
 * @package Drupal\salsify_integration
 */
class SalsifyImportField {

  /**
   * The configFactory interface.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The Salsify config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * The Entity Type Manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The Salsify Service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $salsifyService;

  /**
   * The cache object associated with the specified bin.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * File system service.
   *
   * @var \Drupal\Core\File\FileSystem
   */
  protected $fileSystem;

  /**
   * Constructs a \Drupal\salsify_integration\Salsify object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory interface.
   * @param \Drupal\Core\Entity\Query\QueryFactory $entity_query
   *   The query factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\salsify_integration\Salsify $salsify
   *   The Salsify service.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_salsify
   *   The cache object associated with the Salsify bin.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The fileSystem service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager,
    Salsify $salsify,
    CacheBackendInterface $cache_salsify,
    FileSystemInterface $file_system) {
    $this->configFactory = $config_factory;
    $this->config = $this->configFactory->get('salsify_integration.settings');
    $this->entityTypeManager = $entity_type_manager;
    $this->salsifyService = $salsify;
    $this->cache = $cache_salsify;
    $this->fileSystem = $file_system;
  }

  /**
   * Creates a new SalsifyImportField object.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container object to use when gathering dependencies.
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('salsify_integration.salsify_service'),
      $container->get('cache.default'),
      $container->get('file_system'),
    );
  }

  /**
   * A function to import Salsify data as nodes in Drupal.
   *
   * @param array $product_data
   *   The Salsify individual product data to process.
   */
  public function processSalsifyItem(array $product_data) {
    // Load salsify field mappings.
    $salsify_field_mapping = $this->config->get('salsify_field_mapping');
    // Lookup any existing entities in order to overwrite their contents.
    $nodes = $this->entityTypeManager
      ->getStorage('node')
      ->loadByProperties(['field_product_salsify_id' => $product_data['salsify:id']]);
    $results = reset($nodes);
    // Load the existing entity or generate a new one.
    if ($results) {
      $entity = $this->entityTypeManager->getStorage('node')->load($results->id());
      $salsify_updated = strtotime($product_data['salsify:updated_at']);
      $entity->set('changed', $salsify_updated);
      // Update the title of the product.
      $title = $product_data['SKU Title']
      if (empty($title)) {
        // Delete the product as node title cannot be empty.
        $entity->delete();
        return;
      }
      else {
        $entity->set('title', $title);
      }
      // Delete the product if it is deleted from the salsify.
      if (!empty($product_data['salsify:destroyed_at'])) {
        $entity->delete();
        return;
      }

    }
    else {
      $title = $product_data['SKU Title']
      // Return if title field is missing from the product data.
      if (empty($title)) {
        return;
      }
      $entity_definition = $this->entityTypeManager->getDefinition('node');
      $entity_keys = $entity_definition->getKeys();

      $entity_values = [
        $entity_keys['label'] => $title,
        $entity_keys['bundle'] => 'products',
      ];
      $entity_values['created'] = strtotime($product_data['salsify:created_at']);
      $entity_values['changed'] = strtotime($product_data['salsify:updated_at']);
      if (isset($entity_keys['status'])) {
        $entity_values['status'] = 1;
      }

      $entity = $this->entityTypeManager->getStorage('node')->create($entity_values);
      $entity->set('field_product_salsify_id', $product_data['salsify:id']);
      $entity->getTypedData();
      $entity->save();
    }
    // Load the configurable fields for this content type.
    $filtered_fields = $this->salsifyService->getContentTypeFields();

    // Unset the system values since they've already been processed.
    unset($filtered_fields['field_product_salsify_id']);

    // Set the field data against the Salsify node.
    foreach ($salsify_field_mapping as $key => $field) {
      if (!empty($field['value'])) {
        /* @var \Drupal\field\Entity\FieldConfig $field_config */
        $field_config = $filtered_fields[$key];
        $options = [];
        if ($field_config) {
          // Truncate strings if they are too long for the string field they
          // are mapped against.
          if ($field_config->getType() == 'string') {
            $options = $product_data[$field['value']] ?? '';
            $field_storage = $field_config->getFieldStorageDefinition();
            $max_length = $field_storage->getSetting('max_length');
            if (strlen($options) > $max_length) {
              $options = substr($options, 0, $max_length);
            }
          }

          if ($field_config->getType() == 'text_with_summary') {
            $options = [
              'value' => $product_data[$field['value']] ?? '',
              'format' => 'full_html',
            ];
          }
          if ($field_config->getType() == 'text_long') {
            $options = $product_data[$field['value']] ?? '';
          }
          if ($field_config->getType() == 'boolean') {
            $value = $product_data[$field['value']] ?? 0;
            $options = (bool) $value;
          }

          if ($field_config->getType() == 'link') {
            $salsify_link_fields = explode('|', $field['value']);
            foreach($salsify_link_fields as $salsify_link_field) {
              if (!empty($product_data[$salsify_link_field])) {
                $options[] = [
                  'uri' => $product_data[$salsify_link_field],
                  'title' => $salsify_link_field,
                  'options' => [],
                ];
              }
            }
          }
          if ($field_config->getType() == 'entity_reference') {
            // Load the cached Salsify data from when the items were queued.
            $cache_entry = $this->cache->get('salsify_import_product_data');
            if ($cache_entry) {
              $salsify_data = $cache_entry->data;
            }
            else {
              // NOTE: During this call the cached item is refreshed.
              $salsify_data = $this->salsifyService->getProductData();
            }
            $directory = 'public://media';
            if (!empty($product_data[$field['value']]) && !empty($salsify_data['digital_assets'][$product_data[$field['value']]])) {
              $url = $salsify_data['digital_assets'][$product_data[$field['value']]]['salsify:url'];
              if ($this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY)) {
                $file = system_retrieve_file(trim($url), $directory, true, FileSystemInterface::EXISTS_REPLACE);
              }
              if ($file) {
                // Lookup the existing media if exist.
                $result = $this->entityTypeManager
                  ->getStorage('media')
                  ->getQuery()
                  ->condition('field_media_image.target_id', $file->id())
                  ->execute();

                if (!empty($result)) {
                  $media = reset($result);
                  $media_entity = $this->entityTypeManager->getStorage('media')->load($media);
                  $options = [
                    'target_id' => $media_entity->id(),
                  ];
                }
                else {
                  $media = Media::create([
                    'bundle' => 'image',
                    'uid' => '0',
                    'field_media_image' => [
                      'target_id' => $file->id(),
                    ],
                  ]);
                  $media->setPublished(TRUE)->save();
                  $options = [
                    'target_id' => $media->id(),
                  ];
                }
              }
            }
          }
          $entity->set($key, $options);
        }
      }
    }
    $entity->save();
  }

}
