<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_image_text\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;

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
      'title' => '',
      'description' => '',
      'button_url' => '',
      'button_text' => '',
      'image_position' => 'left',
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
      '#required' => TRUE,
      '#description' => $this->t('Absolute or public relative URL.'),
    ];
    $form['image_alt'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Image alt text'),
      '#default_value' => $configuration['image_alt'] ?? '',
      '#maxlength' => 512,
    ];

    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#default_value' => $configuration['title'] ?? '',
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
  public function blockSubmit($form, FormStateInterface $form_state): void {
    //3.- Persist the submitted values while validating the selectable options.
    $values = $form_state->getValues();

    $this->setConfiguration([
      'image_src' => (string) ($values['image_src'] ?? ''),
      'image_alt' => (string) ($values['image_alt'] ?? ''),
      'title' => (string) ($values['title'] ?? ''),
      'description' => (string) ($values['description'] ?? ''),
      'button_url' => (string) ($values['button_url'] ?? ''),
      'button_text' => (string) ($values['button_text'] ?? ''),
      'image_position' => in_array(($values['image_position'] ?? 'left'), ['left', 'right'], TRUE) ? $values['image_position'] : 'left',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    //4.- Supply the render array that feeds variables into the Twig template.
    $configuration = $this->getConfiguration();
    $image_position = $configuration['image_position'] ?? 'left';
    $validated_position = in_array($image_position, ['left', 'right'], TRUE) ? $image_position : 'left';

    return [
      '#theme' => 'pds_recipe_image_text',
      'image_src' => $configuration['image_src'] ?? '',
      'image_alt' => $configuration['image_alt'] ?? '',
      'title' => $configuration['title'] ?? '',
      'description' => $configuration['description'] ?? '',
      'button_url' => $configuration['button_url'] ?? '',
      'button_text' => $configuration['button_text'] ?? '',
      'image_position' => $validated_position,
      '#attached' => [
        'library' => ['pds_recipe_image_text/educacion'],
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

}
