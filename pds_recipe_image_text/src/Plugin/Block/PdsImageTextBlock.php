<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_image_text\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;

/**
 * @Block(
 *   id = "pds_image_text_block",
 *   admin_label = @Translation("PDS Imagen + Texto"),
 *   category = @Translation("PDS Recipes")
 * )
 */
final class PdsImageTextBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    //1.- Define default values for every configurable field in the block form.
    return [
      'image_src' => '',
      'image_alt' => '',
      'header' => '',
      'description' => '',
      'button_url' => '',
      'button_text' => '',
      'image_position' => 'left',
      'image_file_id' => NULL,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    //2.- Build the configuration form by exposing every editable field.
    $configuration = $this->getConfiguration();

    $form['image_src'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Image URL'),
      '#default_value' => $configuration['image_src'] ?? '',
      '#required' => FALSE,
      '#description' => $this->t('Absolute or public relative URL.'),
    ];
    //2.1.- Provide a managed file upload so editors can work without external URLs.
    $form['image_file'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Upload image'),
      '#default_value' => isset($configuration['image_file_id']) && $configuration['image_file_id'] ? [
        $configuration['image_file_id'],
      ] : [],
      '#upload_location' => 'public://pds_recipe_image_text',
      '#upload_validators' => [
        'file_validate_extensions' => ['png jpg jpeg gif svg'],
      ],
      '#description' => $this->t('Uploading an image will override the URL field above.'),
    ];
    $form['image_alt'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Image alt text'),
      '#default_value' => $configuration['image_alt'] ?? '',
      '#maxlength' => 512,
    ];

    $form['header'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Header'),
      '#default_value' => $configuration['header'] ?? '',
      '#maxlength' => 255,
    ];
    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $configuration['description'] ?? '',
      '#rows' => 3,
    ];

    $form['button_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Button URL'),
      '#default_value' => $configuration['button_url'] ?? '',
    ];
    $form['button_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Button text'),
      '#default_value' => $configuration['button_text'] ?? '',
      '#maxlength' => 128,
    ];

    $form['image_position'] = [
      '#type' => 'select',
      '#title' => $this->t('Image position (desktop)'),
      '#options' => [
        'left' => $this->t('Left'),
        'right' => $this->t('Right'),
      ],
      '#default_value' => $configuration['image_position'] ?? 'left',
      '#description' => $this->t('Mobile stays stacked.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockValidate($form, FormStateInterface $form_state): void {
    //3.- Confirm that at least one image source (URL or file) is provided.
    $image_url = (string) $form_state->getValue('image_src');
    $image_file = $form_state->getValue('image_file');

    if ($image_url === '' && (empty($image_file) || empty($image_file[0]))) {
      $form_state->setErrorByName('image_src', $this->t('Provide an image URL or upload an image.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    //4.- Persist the submitted values while validating the selectable options.
    $values = $form_state->getValues();

    $image_file_value = $values['image_file'] ?? [];
    $image_file_id = is_array($image_file_value) && isset($image_file_value[0]) ? (int) $image_file_value[0] : 0;
    if ($image_file_id > 0) {
      /** @var \Drupal\file\Entity\File|null $file */
      $file = File::load($image_file_id);
      if ($file instanceof File) {
        //4.1.- Promote the uploaded file to permanent storage for reuse.
        $file->setPermanent();
        $file->save();
      }
    }

    $this->setConfiguration([
      'image_src' => (string) ($values['image_src'] ?? ''),
      'image_alt' => (string) ($values['image_alt'] ?? ''),
      'header' => (string) ($values['header'] ?? ''),
      'description' => (string) ($values['description'] ?? ''),
      'button_url' => (string) ($values['button_url'] ?? ''),
      'button_text' => (string) ($values['button_text'] ?? ''),
      'image_position' => in_array(($values['image_position'] ?? 'left'), ['left', 'right'], TRUE) ? $values['image_position'] : 'left',
      'image_file_id' => $image_file_id > 0 ? $image_file_id : NULL,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    //5.- Supply the render array that feeds variables into the Twig template.
    $configuration = $this->getConfiguration();
    $image_position = $configuration['image_position'] ?? 'left';
    $validated_position = in_array($image_position, ['left', 'right'], TRUE) ? $image_position : 'left';

    $image_src = $configuration['image_src'] ?? '';
    $image_file_id = $configuration['image_file_id'] ?? NULL;
    if (!empty($image_file_id)) {
      /** @var \Drupal\file\Entity\File|null $file */
      $file = File::load((int) $image_file_id);
      if ($file instanceof File) {
        //5.1.- Replace the configured URL with the generated URL for the file.
        $image_src = \Drupal::service('file_url_generator')->generateString($file->getFileUri());
      }
    }

    return [
      '#theme' => 'pds_recipe_image_text',
      '#image_src' => $image_src,
      '#image_alt' => $configuration['image_alt'] ?? '',
      '#header' => $configuration['header'] ?? '',
      '#description' => $configuration['description'] ?? '',
      '#button_url' => $configuration['button_url'] ?? '',
      '#button_text' => $configuration['button_text'] ?? '',
      '#image_position' => $validated_position,
      '#attached' => [
        'library' => ['pds_recipe_image_text/educacion'],
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

}
