<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_hero_banner_gradient\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @Block(
 *   id = "pds_hero_banner_gradient_block",
 *   admin_label = @Translation("PDS Hero Banner Gradient"),
 *   category = @Translation("PDS Recipes")
 * )
 */
final class PdsHeroBannerGradientBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * @var \Drupal\Core\Extension\ModuleExtensionList
   */
  private ModuleExtensionList $moduleList;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, ModuleExtensionList $module_list) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->moduleList = $module_list;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('extension.list.module')
    );
  }

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
      '#description' => $this->t('Texto del encabezado. Se renderiza como HTML simple.'),
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
      '#type' => 'textfield',
      '#title' => $this->t('URL del botón'),
      '#default_value' => $cfg['link_url'] ?? '',
      '#description' => $this->t('Vacío para desactivar. Acepta https://... o rutas internas como /mi/página.'),
    ];

    return $form;
  }

  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $url = trim((string) $form_state->getValue('link_url') ?? '');
    if ($url === '') {
      return;
    }

    // Allow absolute http(s) or internal paths starting with '/'.
    $is_abs = str_starts_with($url, 'http://') || str_starts_with($url, 'https://');
    $is_internal = str_starts_with($url, '/');

    if (!$is_abs && !$is_internal) {
      $form_state->setErrorByName('link_url', $this->t('Use una URL absoluta (https://...) o una ruta interna que inicie con /.'));
    }
  }

  public function blockSubmit($form, FormStateInterface $form_state): void {
    $v = $form_state->getValues();
    $this->setConfiguration([
      'title_html' => (string) $v['title_html'],
      'body_text'  => (string) $v['body_text'],
      'link_text'  => (string) $v['link_text'],
      'link_url'   => trim((string) ($v['link_url'] ?? '')),
    ]);
  }

  /**
   * Resolve "module://module_name/relative/path" into a public URL.
   */
  private function resolveModuleUri(string $uri): string {
    $scheme = 'module://';
    if (!str_starts_with($uri, $scheme)) {
      return $uri;
    }
    $rest = substr($uri, strlen($scheme)); // module_name/...
    [$mod, $rel] = explode('/', $rest, 2) + [null, null];
    if (!$mod || !$rel) {
      return $uri;
    }
    $path = $this->moduleList->getPath($mod);
    return base_path() . $path . '/' . ltrim($rel, '/');
  }

  /**
   * Normalize link_url into a string usable in Twig.
   */
  private function buildLinkUrl(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') {
      return '';
    }
    if (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://')) {
      // Absolute.
      return Url::fromUri($raw)->toString();
    }
    if (str_starts_with($raw, '/')) {
      // Internal path like /node/1 or /mi/ruta.
      return Url::fromUserInput($raw)->toString();
    }
    // Fallback: treat as internal relative to base.
    return Url::fromUri('base:' . ltrim($raw, '/'))->toString();
  }

  public function build(): array {
    // Static logo from this module.
    $logo_url = $this->resolveModuleUri('module://pds_recipe_hero_banner_gradient/images/logo.png');

    $cfg = $this->getConfiguration();
    $link_url = $this->buildLinkUrl((string) ($cfg['link_url'] ?? ''));

    return [
      '#theme' => 'pds_hero_banner_gradient',
      '#attributes' => ['class' => ['pds-educacion']],
      // Keep original structure.
      '#heading' => ['#markup' => $cfg['title_html'] ?? ''],
      '#subheading' => $cfg['body_text'] ?? '',
      '#logo_url' => $logo_url,
      '#link_text' => $cfg['link_text'] ?? '',
      '#link_url' => $link_url,
      '#attached' => ['library' => ['pds_recipe_hero_banner_gradient/educacion']],
      // Preserved caching behavior.
      '#cache' => ['max-age' => 0],
    ];
  }

}
