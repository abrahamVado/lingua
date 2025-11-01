<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_educacion_financiera\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * @Block(
 *   id = "pds_educacion_financiera_block",
 *   admin_label = @Translation("PDS Educación financiera"),
 *   category = @Translation("PDS Recipes")
 * )
 */
final class PdsEducacionFinancieraBlock extends BlockBase {

  public function defaultConfiguration(): array {
    return [
      'title_html' => 'Educação financeira para Ensino Fundamental e Médio.',
      'body_text'  => 'Desde 2016, mais de 12 mil alunos já participaram das aulas ministradas por nossos colaboradores e parceiros voluntários.',
      'link_text'  => 'Conheça o programa',
      'link_url'   => '',
    ];
  }

  public function blockForm($form, FormStateInterface $form_state): array {
    $cfg = $this->getConfiguration();

    $form['title_html'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Título'),
      '#default_value' => $cfg['title_html'] ?? '',
      '#required' => TRUE,
    ];
    $form['body_text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Texto'),
      '#default_value' => $cfg['body_text'] ?? '',
      '#rows' => 3,
      '#required' => TRUE,
    ];
    $form['link_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Texto del botón'),
      '#default_value' => $cfg['link_text'] ?? '',
      '#required' => TRUE,
    ];
    $form['link_url'] = [
      '#type' => 'url',
      '#title' => $this->t('URL del botón'),
      '#default_value' => $cfg['link_url'] ?? '',
      '#description' => $this->t('Dejar vacío para desactivar el botón.'),
    ];

    return $form;
  }

  public function blockSubmit($form, FormStateInterface $form_state): void {
    $v = $form_state->getValues();
    $this->setConfiguration([
      'title_html' => (string) $v['title_html'],
      'body_text'  => (string) $v['body_text'],
      'link_text'  => (string) $v['link_text'],
      'link_url'   => (string) ($v['link_url'] ?? ''),
    ]);
  }

  private function resolveModuleUri(string $uri): string {
    $scheme = 'module://';
    if (!str_starts_with($uri, $scheme)) {
      return $uri;
    }
    $rest = substr($uri, strlen($scheme));           // module_name/...
    [$mod, $rel] = explode('/', $rest, 2) + [null, null];
    if (!$mod || !$rel) {
      return $uri;
    }
    $path = \Drupal::service('extension.list.module')->getPath($mod);
    return base_path() . $path . '/' . ltrim($rel, '/');
  }

  public function build(): array {
    // Logo via module://
    $logo_url = $this->resolveModuleUri('module://pds_recipe_educacion_financiera/images/logo.png');

    $cfg = $this->getConfiguration();

    // Link URL (http or internal path)
    $link_url = '';
    if (!empty($cfg['link_url'])) {
      $link_url = str_starts_with($cfg['link_url'], 'http')
        ? $cfg['link_url']
        : Url::fromUri('base:' . ltrim($cfg['link_url'], '/'))->toString();
    }

    return [
      '#theme' => 'pds_educacion_financiera',
      '#attributes' => ['class' => ['pds-educacion']],
      '#heading' => ['#markup' => $cfg['title_html'] ?? ''],
      '#subheading' => $cfg['body_text'] ?? '',
      '#logo_url' => $logo_url,
      '#link_text' => $cfg['link_text'] ?? '',
      '#link_url' => $link_url,
      '#attached' => ['library' => ['pds_recipe_educacion_financiera/educacion']],
      '#cache' => ['max-age' => 0],
    ];
  }

}
