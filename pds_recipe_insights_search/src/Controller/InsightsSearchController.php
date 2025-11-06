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
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides JSON search results for Insights content.
 */
final class InsightsSearchController extends ControllerBase {

  /**
   * Maximum number of results returned per request.
   */
  private const MAX_PAGE_SIZE = 50;

  /**
   * Default number of results returned per request.
   */
  private const DEFAULT_PAGE_SIZE = 10;

  /**
   * Cached field definitions for the Insights bundle.
   *
   * @var array<string, \Drupal\Core\Field\FieldDefinitionInterface>
   */
  private array $fieldDefinitions = [];

  public function __construct(
    private readonly DateFormatterInterface $dateFormatter,
    private readonly EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('date.formatter'),
      $container->get('entity_field.manager'),
    );
  }

  /**
   * Returns search results for the Insights content type.
   */
  public function search(Request $request): JsonResponse {
    // 1) Ensure the "insights" content type exists.
    $nodeTypeStorage = $this->entityTypeManager()->getStorage('node_type');
    if (!$nodeTypeStorage->load('insights')) {
      return new JsonResponse([
        'error' => 'The "insights" content type is not available.',
      ], JsonResponse::HTTP_NOT_FOUND);
    }

    // 2) Normalize pagination and search params.
    $keywords = trim((string) $request->query->get('q', ''));
    $limit = (int) $request->query->get('limit', self::DEFAULT_PAGE_SIZE);
    $limit = max(1, min(self::MAX_PAGE_SIZE, $limit));
    $pageParam = $request->query->get('page', 0);
    $page = is_numeric($pageParam) ? (int) $pageParam : 0;
    $page = max(0, $page);
    $offset = $page * $limit;

    // 3) Build the EntityQuery for published Insights.
    $storage = $this->entityTypeManager()->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', NodeInterface::PUBLISHED)
      ->condition('type', 'insights')
      ->sort('created', 'DESC');

    if ($keywords !== '') {
      $group = $query->orConditionGroup()
        ->condition('title', $keywords, 'CONTAINS');
      if ($this->hasField('body')) {
        $group->condition('body.value', $keywords, 'CONTAINS');
      }
      $query->condition($group);
    }

    // 4) Count total before paging.
    $total = (int) (clone $query)->count()->execute();

    // 5) Apply range and load nodes.
    $query->range($offset, $limit);
    $nids = $query->execute();
    $nodes = $nids ? $storage->loadMultiple($nids) : [];

    // 6) Map entities to lightweight items.
    $items = [];
    foreach ($nodes as $node) {
      if (!$node instanceof NodeInterface) {
        continue;
      }
      $items[] = [
        'id'        => (int) $node->id(),
        'title'     => $node->label(),
        'summary'   => $this->extractSummary($node),
        'author'    => $node->getOwner()?->getDisplayName() ?? '',
        'created'   => $this->dateFormatter->format($node->getCreatedTime(), 'custom', DATE_ATOM),
        'url'       => $node->toUrl('canonical', ['absolute' => TRUE])->toString(),
        'theme'     => $this->extractTheme($node),
        'read_time' => $this->extractReadTime($node),
      ];
    }

    // 7) Cache-aware JSON response.
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

    $response = new CacheableJsonResponse($payload);
    $cacheability = (new CacheableMetadata())
      ->setCacheMaxAge(300)
      ->addCacheContexts([
        'url.query_args:q',
        'url.query_args:limit',
        'url.query_args:page',
      ])
      ->addCacheTags([
        'node_list',
        'node:insights',
      ]);
    $response->addCacheableDependency($cacheability);

    return $response;
  }

  /**
   * Extracts a summary for the node.
   */
  private function extractSummary(NodeInterface $node): string {
    // Prefer explicit summary-type fields.
    foreach (['field_summary', 'field_resumen', 'field_short_description'] as $field) {
      if ($node->hasField($field) && !$node->get($field)->isEmpty()) {
        $value = (string) $node->get($field)->value;
        $value = Html::decodeEntities(strip_tags($value));
        if ($value !== '') {
          return $value;
        }
      }
    }

    // Fallback to body.
    if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
      $item = $node->get('body')->first();
      if ($item) {
        $text = (string) ($item->summary ?? $item->value ?? '');
        $text = Html::decodeEntities(strip_tags($text));
        return $text;
      }
    }
    return '';
  }

  /**
   * Extracts theme info if present.
   *
   * @return array{ id: int|null, label: string }
   */
  private function extractTheme(NodeInterface $node): array {
    foreach (['field_theme', 'field_tema'] as $field) {
      if ($node->hasField($field) && !$node->get($field)->isEmpty()) {
        $term = $node->get($field)->entity;
        if ($term) {
          return [
            'id' => (int) $term->id(),
            'label' => $term->label(),
          ];
        }
      }
    }
    return ['id' => null, 'label' => ''];
  }

  /**
   * Extracts read time if available.
   */
  private function extractReadTime(NodeInterface $node): string {
    foreach (['field_read_time', 'field_tiempo_de_lectura'] as $field) {
      if ($node->hasField($field) && !$node->get($field)->isEmpty()) {
        return trim((string) $node->get($field)->value);
      }
    }
    return '';
  }

  /**
   * Checks if the Insights bundle has a field.
   */
  private function hasField(string $fieldName): bool {
    if ($this->fieldDefinitions === []) {
      $definitions = $this->entityFieldManager->getFieldDefinitions('node', 'insights');
      $this->fieldDefinitions = is_array($definitions) ? $definitions : [];
    }
    return isset($this->fieldDefinitions[$fieldName]);
  }

}
