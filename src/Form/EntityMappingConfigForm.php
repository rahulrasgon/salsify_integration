<?php

namespace Drupal\salsify_integration\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\ConfigFormBase;

/**
 * EntityMappingConfigForm Configuration form class.
 */
class EntityMappingConfigForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'salsify_integration_mapping_config_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('salsify_integration.settings');
    $product_feed = $config->get('product_feed_url');
    if (!empty($product_feed)) {
      $form['salsify_field_mapping'] = [
        '#type' => 'table',
        '#header' => array($this->t('Drupal Field'), $this->t('Salsify Field')),
        '#tableselect' => FALSE,
        '#weight' => 50,
      ];

      $salsify_field_mapping = $config->get('salsify_field_mapping');
      // Gather all of the configured fields on the configured content type.
      $filtered_fields = \Drupal::service('salsify_integration.salsify_service')
        ->getContentTypeFields();
      unset($filtered_fields['field_product_salsify_id']);
      foreach ($filtered_fields as $key => $filtered_field) {
        $form['salsify_field_mapping'][$key]['label'] = [
          '#type' => 'markup',
          '#markup' => '<strong>' . $filtered_field->label() . '</strong>',
        ];
        $form['salsify_field_mapping'][$key]['value'] = [
          '#type' => 'textfield',
          '#default_value' =>  $salsify_field_mapping[$key]['value'] ?? '',
        ];
      }
    }
    else {
      $form['salsify_mapping_message'] = [
        '#type' => 'markup',
        '#markup' => $this->t('The Salsify module is not yet set up.'),
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Retrieve the configuration.
    $this->configFactory->getEditable('salsify_integration.settings')
      // Save the mapping values.
      ->set('salsify_field_mapping', $form_state->getValue('salsify_field_mapping'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Return the configuration names.
   */
  protected function getEditableConfigNames() {
    return [
      'salsify_integration.settings',
    ];
  }

}
