<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_insights_search\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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

  /**
   * Constructs the controller with the required services.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly EntityFieldManagerInterface $entityFieldManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
      $container->get('entity_field.manager'),
    );
  }

  /**
   * Returns search results for the Insights content type.
   */
  public function search(Request $request): JsonResponse {
    //1.- Validate that the Insights content type exists before querying it.
    $nodeTypeStorage = $this->entityTypeManager->getStorage('node_type');
    if (!$nodeTypeStorage->load('insights')) {
      return new JsonResponse([
        'error' => 'The "insights" content type is not available.',
      ], JsonResponse::HTTP_NOT_FOUND);
    }

    //2.- Normalize pagination and search parameters coming from the request.
    $keywords = trim((string) $request->query->get('q', ''));
    $limit = (int) $request->query->get('limit', self::DEFAULT_PAGE_SIZE);
    $limit = max(1, min(self::MAX_PAGE_SIZE, $limit));
    $pageParam = $request->query->get('page', 0);
    $page = is_numeric($pageParam) ? (int) $pageParam : 0;
    $page = max(0, $page);
    $offset = $page * $limit;

    //3.- Build the entity query that searches published Insights nodes.
    $storage = $this->entityTypeManager->getStorage('node');
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

    //4.- Clone the query to compute the total results before slicing.
    $count_query = clone $query;
    $total = (int) $count_query->count()->execute();

    //5.- Apply pagination and load the matching nodes.
    $query->range($offset, $limit);
    $nids = $query->execute();
    $nodes = $nids ? $storage->loadMultiple($nids) : [];

    //6.- Transform the node entities into lightweight response items.
    $items = [];
    foreach ($nodes as $node) {
      if (!$node instanceof NodeInterface) {
        continue;
      }
      $items[] = [
        'id' => (int) $node->id(),
        'title' => $node->label(),
        'summary' => $this->extractSummary($node),
        'author' => $node->getOwner()?->getDisplayName() ?? '',
        'created' => $this->dateFormatter->format($node->getCreatedTime(), 'custom', DATE_ATOM),
        'url' => $node->toUrl('canonical', ['absolute' => TRUE])->toString(),
        'theme' => $this->extractTheme($node),
        'read_time' => $this->extractReadTime($node),
      ];
    }

    //7.- Prepare a cache-aware JSON response with meta information.
    $payload = [
      'meta' => [
        'query' => $keywords,
        'limit' => $limit,
        'page' => $page,
        'total' => $total,
        'pages' => $limit > 0 ? (int) ceil($total / $limit) : 0,
      ],
      'data' => $items,
    ];

    $response = new CacheableJsonResponse($payload);
    $cacheability = (new CacheableMetadata())
      ->setCacheMaxAge(300)
      ->addCacheContexts(['url.query_args:q', 'url.query_args:limit', 'url.query_args:page'])
      ->addCacheTags(['node_list', 'node:insights']);
    $response->addCacheableDependency($cacheability);

    return $response;
  }

  /**
   * Extracts a summary for the node.
   */
  private function extractSummary(NodeInterface $node): string {
    //1.- Prioritize summary-capable fields while gracefully falling back.
    foreach (['field_summary', 'field_resumen', 'field_short_description'] as $field) {
      if ($node->hasField($field) && !$node->get($field)->isEmpty()) {
        $value = (string) $node->get($field)->value;
        $value = Html::decodeEntities(strip_tags($value));
        if ($value !== '') {
          return $value;
        }
      }
    }

    //2.- Fall back to the body field when no explicit summary is available.
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
   * Extracts theme information when the field exists on the bundle.
   *
   * @return array{ id: int|null, label: string }
   *   Machine id and human readable label for the theme, or defaults.
   */
  private function extractTheme(NodeInterface $node): array {
    //1.- Verify that the bundle exposes a theme reference field.
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

    //2.- Default to empty data when the field is absent or unassigned.
    return [
      'id' => NULL,
      'label' => '',
    ];
  }

  /**
   * Extracts a read time value if available.
   */
  private function extractReadTime(NodeInterface $node): string {
    //1.- Look for a dedicated read time field on the bundle.
    foreach (['field_read_time', 'field_tiempo_de_lectura'] as $field) {
      if ($node->hasField($field) && !$node->get($field)->isEmpty()) {
        return trim((string) $node->get($field)->value);
      }
    }

    //2.- Provide an empty string when the field is missing.
    return '';
  }

  /**
   * Checks if the Insights content type exposes the requested field.
   */
  private function hasField(string $fieldName): bool {
    //1.- Load and cache the field definitions for the Insights bundle.
    if ($this->fieldDefinitions === []) {
      $definitions = $this->entityFieldManager->getFieldDefinitions('node', 'insights');
      $this->fieldDefinitions = is_array($definitions) ? $definitions : [];
    }

    //2.- Confirm the existence of the requested field within the bundle map.
    return isset($this->fieldDefinitions[$fieldName]);
  }

}
