<?php

namespace Drupal\pds_suite\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings form for managing PDS Suite secrets and options.
 */
class PdsSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    //1.- Declare the configuration object managed by this form.
    return ['pds_suite.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    //2.- Provide a unique form identifier recognized by Drupal.
    return 'pds_suite_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    //3.- Load the editable configuration to populate default values.
    $config = $this->config('pds_suite.settings');

    //4.- Add a details element grouping API connectivity options.
    $form['api_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('API configuration'),
      '#open' => true,
    ];

    //5.- Provide a text field for the upstream endpoint base URL.
    $form['api_settings']['api_endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API endpoint'),
      '#description' => $this->t('Enter the base endpoint used for remote content federation.'),
      '#default_value' => $config->get('api_endpoint'),
      '#required' => true,
    ];

    //6.- Provide an obfuscated field for the API key credential.
    $form['api_settings']['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API key'),
      '#description' => $this->t('Key provided by the external content service.'),
      '#default_value' => $config->get('api_key'),
    ];

    //7.- Provide a password field for storing a sensitive secret token.
    $form['api_settings']['secret_token'] = [
      '#type' => 'password',
      '#title' => $this->t('Secret token'),
      '#description' => $this->t('Optional signing secret used for webhook verification.'),
      '#default_value' => $config->get('secret_token'),
    ];

    //8.- Offer a checkbox to toggle request logging for debugging.
    $form['api_settings']['enable_logging'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable API logging'),
      '#description' => $this->t('When enabled, each API request will be logged to the Drupal watchdog log.'),
      '#default_value' => $config->get('enable_logging'),
    ];

    //9.- Defer to the parent buildForm for buttons and structure.
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    //10.- Persist submitted values to the configuration storage.
    $this->configFactory->getEditable('pds_suite.settings')
      ->set('api_endpoint', $form_state->getValue('api_endpoint'))
      ->set('api_key', $form_state->getValue('api_key'))
      ->set('secret_token', $form_state->getValue('secret_token'))
      ->set('enable_logging', (bool) $form_state->getValue('enable_logging'))
      ->save();

    //11.- Invoke parent submit handler for standard messaging.
    parent::submitForm($form, $form_state);
  }

}
