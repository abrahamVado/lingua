<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_insights_search\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class InsightsSearchController extends ControllerBase {

  private const MAX_PAGE_SIZE = 50;
  private const DEFAULT_PAGE_SIZE = 10;
  private const SUPPORTED_BUNDLES = ['insights', 'insight'];

  /** @var array<string, array<string, \Drupal\Core\Field\FieldDefinitionInterface>> */
  private array $fieldDefinitions = [];

  public function __construct(
    private readonly DateFormatterInterface $dateFormatter,
    private readonly EntityFieldManagerInterface $entityFieldManager,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('date.formatter'),
      $container->get('entity_field.manager'),
    );
  }

  public function search(Request $request): JsonResponse {
    //1.- Resolve eligible bundles so the endpoint supports either "insights" or similar variants.
    $bundles = $this->resolveBundles();
    if ($bundles === []) {
      return new JsonResponse(['error' => 'No Insights content type is available.'], 404);
    }
    $this->warmFieldDefinitions($bundles);

    // Params.
    $keywords = trim((string) $request->query->get('q', ''));
    $limit    = max(1, min(self::MAX_PAGE_SIZE, (int) $request->query->get('limit', self::DEFAULT_PAGE_SIZE)));
    $page     = max(0, (int) ($request->query->get('page', 0)));
    $offset   = $page * $limit;
    $exclude  = [];
    $raw_exclude = $request->query->get('exclude');
    if ($raw_exclude === NULL) {
      $raw_exclude = [];
    }
    elseif (!is_array($raw_exclude)) {
      $raw_exclude = [$raw_exclude];
    }
    foreach ($raw_exclude as $value) {
      foreach (explode(',', (string) $value) as $part) {
        $part = trim($part);
        if ($part === '' || !ctype_digit($part)) {
          continue;
        }
        $exclude[] = (int) $part;
      }
    }
    $exclude = array_values(array_unique(array_filter($exclude, static fn ($nid): bool => $nid > 0)));

    $themes = [];
    $raw_themes = $request->query->all('themes');
    if ($raw_themes === []) {
      $single = $request->query->get('themes');
      if ($single !== NULL) {
        $raw_themes = is_array($single) ? $single : [$single];
      }
    }
    foreach ($raw_themes as $value) {
      foreach (explode(',', (string) $value) as $part) {
        $part = trim($part);
        if ($part === '' || !ctype_digit($part)) {
          continue;
        }
        $themes[] = (int) $part;
      }
    }
    $themes = array_values(array_unique(array_filter($themes, static fn ($tid): bool => $tid > 0)));

    // Query.
    $storage = $this->entityTypeManager()->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', NodeInterface::PUBLISHED)
      ->condition('type', $bundles, 'IN')
      ->sort('created', 'DESC');

    if ($keywords !== '') {
      //2.- Build a flexible OR group that scans title, summaries, and body when present on the bundle.
      $group = $query->orConditionGroup()->condition('title', $keywords, 'CONTAINS');
      foreach ($this->getSummaryFieldNames($bundles) as $fieldName) {
        $group->condition($fieldName . '.value', $keywords, 'CONTAINS');
      }
      if ($this->fieldExists('body', $bundles)) {
        $group->condition('body.value', $keywords, 'CONTAINS');
        $group->condition('body.summary', $keywords, 'CONTAINS');
      }
      $query->condition($group);
    }

    if ($exclude !== []) {
      //3.- Honor requested exclusions so featured items can be paginated separately from the automatic catalog.
      $query->condition('nid', $exclude, 'NOT IN');
    }

    if ($themes !== []) {
      //4.- Limit the result set to the requested taxonomy terms regardless of the field name used on each bundle.
      $themeFields = $this->getThemeFieldNames($bundles);
      if ($themeFields !== []) {
        $themeGroup = $query->orConditionGroup();
        foreach ($themeFields as $fieldName) {
          $themeGroup->condition($fieldName . '.target_id', $themes, 'IN');
        }
        $query->condition($themeGroup);
      }
    }

    $total = (int) (clone $query)->count()->execute();
    $query->range($offset, $limit);
    $nids  = $query->execute();
    $nodes = $nids ? $storage->loadMultiple($nids) : [];
    $nodes = $this->applyTranslations($nodes);

    // Base cacheability.
    $cacheability = (new CacheableMetadata())
      ->setCacheMaxAge(300)
      ->addCacheContexts(['url.query_args:q', 'url.query_args:limit', 'url.query_args:page', 'url.query_args:exclude', 'url.query_args:themes'])
      ->addCacheTags($this->buildCacheTags($bundles));

    // Build items. Capture bubbleable metadata from URLs explicitly.
    $items = [];
    foreach ($nodes as $node) {
      if (!$node instanceof NodeInterface) {
        continue;
      }

      $generated = $node->toUrl('canonical', ['absolute' => TRUE])->toString(TRUE);
      $item = $this->buildItem($node, $generated->getGeneratedUrl());

      //3.- Bubble cache metadata from nodes, URLs, and authors so responses stay coherent.
      $cacheability->addCacheableDependency($generated);
      $cacheability->addCacheableDependency($node);
      if ($owner = $node->getOwner()) {
        $cacheability->addCacheableDependency($owner);
      }

      $items[] = $item;
    }

    $payload = [
      'meta' => [
        'query' => $keywords,
        'limit' => $limit,
        'page'  => $page,
        'total' => $total,
        'pages' => $limit > 0 ? (int) ceil($total / $limit) : 0,
      ],
      'data' => $items,
    ];

    $response = new CacheableJsonResponse($payload, 200, [
      'Cache-Control' => 'public, max-age=300',
    ]);
    $response->addCacheableDependency($cacheability);
    return $response;
  }

  private function extractSummary(NodeInterface $node): string {
    foreach (['field_summary', 'field_resumen', 'field_short_description'] as $field) {
      if ($this->nodeHasField($node, $field) && !$node->get($field)->isEmpty()) {
        $value = (string) $node->get($field)->value;
        $value = Html::decodeEntities(strip_tags($value));
        if ($value !== '') {
          return $value;
        }
      }
    }
    if ($this->nodeHasField($node, 'body') && !$node->get('body')->isEmpty()) {
      $item = $node->get('body')->first();
      if ($item) {
        $text = (string) ($item->summary ?? $item->value ?? '');
        $text = Html::decodeEntities(strip_tags($text));
        return $text;
      }
    }
    return '';
  }

  private function extractTheme(NodeInterface $node): array {
    foreach ($this->getThemeFieldNames([$node->bundle()]) as $field) {
      if ($this->nodeHasField($node, $field) && !$node->get($field)->isEmpty()) {
        $term = $node->get($field)->entity;
        if ($term instanceof TermInterface) {
          $machine = $this->normalizeTermIdentifier($term);
          return [
            'id' => (int) $term->id(),
            'label' => $term->label(),
            'machine_name' => $machine,
          ];
        }
      }
    }
    return ['id' => null, 'label' => '', 'machine_name' => ''];
  }

  private function extractReadTime(NodeInterface $node): string {
    foreach (['field_read_time', 'field_tiempo_de_lectura'] as $field) {
      if ($this->nodeHasField($node, $field) && !$node->get($field)->isEmpty()) {
        return trim((string) $node->get($field)->value);
      }
    }
    return '';
  }

  private function buildItem(NodeInterface $node, string $url): array {
    $theme = $this->extractTheme($node);
    $themeId = $theme['machine_name'] ?? '';
    if ($themeId === '' && isset($theme['id']) && $theme['id'] !== null) {
      $themeId = (string) $theme['id'];
    }

    return [
      'id' => (int) $node->id(),
      'title' => $node->label(),
      'summary' => $this->extractSummary($node),
      'author' => $node->getOwner()?->getDisplayName() ?? '',
      'created' => $this->dateFormatter->format($node->getCreatedTime(), 'custom', DATE_ATOM),
      'url' => $url,
      'theme' => $theme,
      'theme_id' => $themeId,
      'theme_label' => $theme['label'] ?? '',
      'read_time' => $this->extractReadTime($node),
    ];
  }

  private function resolveBundles(): array {
    $storage = $this->entityTypeManager()->getStorage('node_type');
    $bundles = [];

    foreach (self::SUPPORTED_BUNDLES as $bundle) {
      if ($storage->load($bundle)) {
        $bundles[] = $bundle;
      }
    }

    if ($bundles === []) {
      foreach ($storage->loadMultiple() as $machine_name => $definition) {
        if (str_contains($machine_name, 'insight')) {
          $bundles[] = $machine_name;
        }
      }
    }

    return array_values(array_unique($bundles));
  }

  private function warmFieldDefinitions(array $bundles): void {
    foreach ($bundles as $bundle) {
      if (!isset($this->fieldDefinitions[$bundle])) {
        $definitions = $this->entityFieldManager->getFieldDefinitions('node', $bundle);
        $this->fieldDefinitions[$bundle] = is_array($definitions) ? $definitions : [];
      }
    }
  }

  private function fieldExists(string $fieldName, array $bundles): bool {
    foreach ($bundles as $bundle) {
      if (!isset($this->fieldDefinitions[$bundle])) {
        continue;
      }
      if (isset($this->fieldDefinitions[$bundle][$fieldName])) {
        return TRUE;
      }
    }
    return FALSE;
  }

  private function nodeHasField(NodeInterface $node, string $fieldName): bool {
    $bundle = $node->bundle();
    if (!isset($this->fieldDefinitions[$bundle])) {
      $this->warmFieldDefinitions([$bundle]);
    }
    return isset($this->fieldDefinitions[$bundle][$fieldName]) && $node->hasField($fieldName);
  }

  private function getThemeFieldNames(array $bundles): array {
    //1.- Inspect candidate taxonomy reference fields so both localized and legacy setups remain compatible with filtering.
    $fields = [];
    foreach (['field_theme', 'field_tema'] as $fieldName) {
      if ($this->fieldExists($fieldName, $bundles)) {
        $fields[] = $fieldName;
      }
    }
    return $fields;
  }

  private function getSummaryFieldNames(array $bundles): array {
    $summaryFields = [];
    foreach (['field_summary', 'field_resumen', 'field_short_description'] as $fieldName) {
      if ($this->fieldExists($fieldName, $bundles)) {
        $summaryFields[] = $fieldName;
      }
    }
    return $summaryFields;
  }

  private function normalizeTermIdentifier(TermInterface $term): string {
    if ($term->hasField('field_machine_name') && !$term->get('field_machine_name')->isEmpty()) {
      $value = trim((string) $term->get('field_machine_name')->value ?? '');
      if ($value !== '') {
        return mb_strtolower($value);
      }
    }
    return Html::cleanCssIdentifier(mb_strtolower($term->label() ?? ''));
  }

  private function applyTranslations(array $nodes): array {
    $currentLangcode = $this->languageManager()->getCurrentLanguage()->getId();
    foreach ($nodes as $nid => $node) {
      if ($node instanceof NodeInterface && $node->hasTranslation($currentLangcode)) {
        $nodes[$nid] = $node->getTranslation($currentLangcode);
      }
    }
    return $nodes;
  }

  private function buildCacheTags(array $bundles): array {
    $tags = ['node_list'];
    foreach ($bundles as $bundle) {
      $tags[] = 'node_list:' . $bundle;
    }
    return array_values(array_unique($tags));
  }
}
