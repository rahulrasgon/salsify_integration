<?php

namespace Drupal\salsify_integration\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a form to configure Salsify API settings.
 */
class ConfigForm extends ConfigFormBase {

  /**
   * Config settings.
   *
   * @var string
   */
  const SETTINGS = 'salsify_integration.settings';

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'salsify_integration_config_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      static::SETTINGS,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config(static::SETTINGS);

    $form['product_feed_url'] = [
      '#type' => 'url',
      '#size' => 75,
      '#title' => $this->t('Salsify Product Feed'),
      '#required' => TRUE,
      '#default_value' => $config->get('product_feed_url'),
      '#description' => t('Enter the Product Feed Latest Channel Url here. An example URL looks like: https://app.salsify.com/api/channels/{channel_id}/runs/latest'),
    ];

    $form['token'] = [
      '#type' => 'textfield',
      '#title' => t('Authentication token'),
      '#required' => TRUE,
      '#default_value' => $config->get('token'),
      '#description' => t('The Salsify API uses authentication tokens to allow access to the API. You can generate a personal token in the Salsify application on the <a href="@url" target="_blank">My Profile page</a>.', ['@url' => 'https://app.salsify.com/app/profile/edit/api']),
    ];

    $form['organization_id'] = [
      '#type' => 'textfield',
      '#title' => t('Organization ID'),
      '#required' => TRUE,
      '#default_value' => $config->get('organization_id'),
      '#description' => t('Enter the Organization ID here. An example organization ID looks like: s-000000-0000-0000-000-000000000000.'),
    ];


    $form['salsify_start_import'] = [
      '#type' => 'button',
      '#value' => $this->t('Sync with Salsify'),
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // If the form was submitted via the "Sync" button, then run the import
    // process right away.
    $trigger = $form_state->getTriggeringElement();
    if ($trigger['#id'] == 'edit-salsify-start-import') {
      $product_feed = \Drupal::service('salsify_integration.salsify_service');
      $results = $product_feed->importProductData(TRUE);
      if ($results) {
        $this->messenger()->addStatus($results['message'], $results['status']);
      }
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Retrieve the configuration.
    $this->configFactory->getEditable(static::SETTINGS)
      // Save the submitted token value.
      ->set('token', $form_state->getValue('token'))
      ->set('product_feed_url', $form_state->getValue('product_feed_url'))
      ->set('organization_id', $form_state->getValue('organization_id'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
