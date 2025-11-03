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
      'eyebrow' => '',
      'headline' => '',
      'description' => '',
      'primary_button_label' => '',
      'primary_button_url' => '',
      'doc_icon_url' => '',
      'doc_link_url' => '',
      'doc_link_label' => '',
    ];
  }

  public function blockForm($form, FormStateInterface $form_state): array {
    $cfg = $this->getConfiguration();

    //1.- Capture the eyebrow text displayed above the headline.
    $form['eyebrow'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Eyebrow'),
      '#default_value' => $cfg['eyebrow'] ?? '',
      '#maxlength' => 255,
      '#description' => $this->t('Texto breve que aparece encima del titular.'),
    ];

    //2.- Manage the main headline copy.
    $form['headline'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Titular'),
      '#default_value' => $cfg['headline'] ?? '',
      '#maxlength' => 255,
      '#description' => $this->t('Texto principal del banner.'),
    ];

    //3.- Handle the descriptive paragraph.
    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Descripción'),
      '#default_value' => $cfg['description'] ?? '',
      '#rows' => 3,
      '#description' => $this->t('Texto descriptivo que acompaña al titular.'),
    ];

    //4.- Configure the primary button label.
    $form['primary_button_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Texto del botón principal'),
      '#default_value' => $cfg['primary_button_label'] ?? '',
      '#maxlength' => 255,
    ];

    //5.- Configure the primary button destination.
    $form['primary_button_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('URL del botón principal'),
      '#default_value' => $cfg['primary_button_url'] ?? '',
      '#description' => $this->t('Vacío para desactivar. Acepta https://..., rutas internas como /mi/pagina o esquemas module://.'),
    ];

    //6.- Capture an optional icon for the documento link.
    $form['doc_icon_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Icono del enlace secundario'),
      '#default_value' => $cfg['doc_icon_url'] ?? '',
      '#description' => $this->t('URL absoluta, ruta interna o esquema module:// para el ícono.'),
    ];

    //7.- Configure the secondary link URL.
    $form['doc_link_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('URL del enlace secundario'),
      '#default_value' => $cfg['doc_link_url'] ?? '',
      '#description' => $this->t('Vacío para ocultar. Acepta https://..., rutas internas o module://.'),
    ];

    //8.- Configure the secondary link label.
    $form['doc_link_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Texto del enlace secundario'),
      '#default_value' => $cfg['doc_link_label'] ?? '',
      '#maxlength' => 255,
    ];

    return $form;
  }

  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    //1.- Validate that the button URL follows expected schemes.
    $this->assertValidUrl($form_state, 'primary_button_url');
    //2.- Validate that the document URL follows expected schemes.
    $this->assertValidUrl($form_state, 'doc_link_url');
  }

  public function blockSubmit($form, FormStateInterface $form_state): void {
    //1.- Persist the submitted values into the block configuration.
    $v = $form_state->getValues();
    $this->setConfiguration([
      'eyebrow' => (string) ($v['eyebrow'] ?? ''),
      'headline' => (string) ($v['headline'] ?? ''),
      'description' => (string) ($v['description'] ?? ''),
      'primary_button_label' => (string) ($v['primary_button_label'] ?? ''),
      'primary_button_url' => trim((string) ($v['primary_button_url'] ?? '')),
      'doc_icon_url' => trim((string) ($v['doc_icon_url'] ?? '')),
      'doc_link_url' => trim((string) ($v['doc_link_url'] ?? '')),
      'doc_link_label' => (string) ($v['doc_link_label'] ?? ''),
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

  /**
   * Ensure URLs entered in the form respect supported schemes.
   */
  private function assertValidUrl(FormStateInterface $form_state, string $field_name): void {
    //1.- Obtain and trim the target value.
    $value = trim((string) ($form_state->getValue($field_name) ?? ''));
    if ($value === '') {
      return;
    }

    //2.- Allow absolute URLs, internal routes or module scheme assets.
    $is_abs = str_starts_with($value, 'http://') || str_starts_with($value, 'https://');
    $is_internal = str_starts_with($value, '/');
    $is_module = str_starts_with($value, 'module://');

    if (!$is_abs && !$is_internal && !$is_module) {
      $form_state->setErrorByName($field_name, $this->t('Use una URL absoluta (https://...), una ruta interna que inicie con / o el esquema module://.'));
    }
  }


  private function buildAssetUrl(string $relative_path): string {
    static $module_path = NULL;
    if ($module_path === NULL) {
      $module_path = \Drupal::service('extension.path.resolver')->getPath('module', 'pds_recipe_hero_banner_gradient');
    }
    $base_path = base_path();
    $relative = ltrim($relative_path, '/');
    return $base_path . trim($module_path, '/') . '/' . $relative;
  }

  public function build(): array {
    $document_url = $this->buildAssetUrl('images/document.svg');

    //1.- Prepare configuration and transform URLs for Twig consumption.
    $cfg = $this->getConfiguration();
    $primary_url = $this->buildLinkUrl((string) ($cfg['primary_button_url'] ?? ''));
    $doc_url = $this->buildLinkUrl((string) ($cfg['doc_link_url'] ?? ''));
    $doc_icon = $this->resolveModuleUri((string) ($cfg['doc_icon_url'] ?? ''));

    //2.- Return render array that maps directly to Twig variables.
    return [
      '#theme' => 'pds_hero_banner_gradient',
      '#eyebrow' => $cfg['eyebrow'] ?? '',
      '#headline' => $cfg['headline'] ?? '',
      '#description' => $cfg['description'] ?? '',
      '#primary_button_label' => $cfg['primary_button_label'] ?? '',
      '#primary_button_url' => $primary_url,
      '#doc_icon_url' => $doc_icon,
      '#doc_link_url' => $doc_url,
      '#doc_link_label' => $cfg['doc_link_label'] ?? '',
      '#document_url' => $document_url,
      '#attached' => ['library' => ['pds_recipe_hero_banner_gradient/educacion']],
      '#cache' => ['max-age' => 0],
    ];
  }

}
