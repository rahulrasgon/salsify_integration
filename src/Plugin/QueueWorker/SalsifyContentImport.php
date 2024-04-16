<?php

namespace Drupal\salsify_integration\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\salsify_integration\SalsifyImportField;

/**
 * Provides functionality for the SalsifyContentImport Queue.
 *
 * @QueueWorker(
 *   id = "salsify_integration_content_import",
 *   title = @Translation("Salsify: Content Import"),
 *   cron = {"time" = 10}
 * )
 */
class SalsifyContentImport extends QueueWorkerBase {

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    // Create a new SalsifyImportField object and pass the Salsify data through.
    $salsify_import = SalsifyImportField::create(\Drupal::getContainer());
    $salsify_import->processSalsifyItem($data);
  }

}
