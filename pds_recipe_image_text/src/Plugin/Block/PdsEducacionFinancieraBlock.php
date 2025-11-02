<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_latamvideo\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Image + Text callout with switchable image position.
 *
 * @Block(
 *   id = "pds_image_text_block",
 *   admin_label = @Translation("PDS Image + Text"),
 *   category = @Translation("PDS Recipes")
 * )
 */
final class PdsImageTextBlock extends BlockBase {

  public function defaultConfiguration(): array {
    return [
      'image_src' => '',
      'image_alt' => '',
      'title' => '',
      'description' => '',
      'button_url' => '',
      'button_text' => '',
      'image_position' => 'left', // left | right
    ];
  }

  public function blockForm($form, FormStateInterface $form_state): array {
    $c = $this->getConfiguration();

    $form['image_src'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Image URL'),
      '#default_value' => $c['image_src'] ?? '',
      '#required' => TRUE,
      '#description' => $this->t('Absolute or public relative URL.'),
    ];
    $form['image_alt'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Image alt text'),
      '#default_value' => $c['image_alt'] ?? '',
      '#maxlength' => 512,
    ];

    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#default_value' => $c['title'] ?? '',
      '#maxlength' => 255,
    ];
    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $c['description'] ?? '',
      '#rows' => 3,
    ];

    $form['button_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Button URL'),
      '#default_value' => $c['button_url'] ?? '',
    ];
    $form['button_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Button text'),
      '#default_value' => $c['button_text'] ?? '',
      '#maxlength' => 128,
    ];

    $form['image_position'] = [
      '#type' => 'select',
      '#title' => $this->t('Image position (desktop)'),
      '#options' => [
        'left' => $this->t('Left'),
        'right' => $this->t('Right'),
      ],
      '#default_value' => $c['image_position'] ?? 'left',
      '#description' => $this->t('Mobile stays stacked.'),
    ];

    return $form;
  }

  public function blockSubmit($form, FormStateInterface $form_state): void {
    $v = $form_state->getValues();
    $this->setConfiguration([
      'image_src' => (string) $v['image_src'],
      'image_alt' => (string) $v['image_alt'],
      'title' => (string) $v['title'],
      'description' => (string) $v['description'],
      'button_url' => (string) ($v['button_url'] ?? ''),
      'button_text' => (string) ($v['button_text'] ?? ''),
      'image_position' => in_array(($v['image_position'] ?? 'left'), ['left','right'], TRUE) ? $v['image_position'] : 'left',
    ]);
  }

  public function build(): array {
    $c = $this->getConfiguration();

    return [
      '#theme' => 'pds_image_text',
      'image_src' => $c['image_src'] ?? '',
      'image_alt' => $c['image_alt'] ?? '',
      'title' => $c['title'] ?? '',
      'description' => $c['description'] ?? '',
      'button_url' => $c['button_url'] ?? '',
      'button_text' => $c['button_text'] ?? '',
      'image_position' => in_array(($c['image_position'] ?? 'left'), ['left','right'], TRUE) ? $c['image_position'] : 'left',
      '#attached' => [
        'library' => ['pds_recipe_latamvideo/image_text'],
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

}
