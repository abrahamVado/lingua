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

final class InsightsSearchController extends ControllerBase {

  private const MAX_PAGE_SIZE = 50;
  private const DEFAULT_PAGE_SIZE = 10;

  /** @var array<string, \Drupal\Core\Field\FieldDefinitionInterface> */
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
    // Ensure bundle exists.
    $nodeTypeStorage = $this->entityTypeManager()->getStorage('node_type');
    if (!$nodeTypeStorage->load('insights')) {
      return new JsonResponse(['error' => 'The "insights" content type is not available.'], 404);
    }

    // Params.
    $keywords = trim((string) $request->query->get('q', ''));
    $limit    = max(1, min(self::MAX_PAGE_SIZE, (int) $request->query->get('limit', self::DEFAULT_PAGE_SIZE)));
    $page     = max(0, (int) ($request->query->get('page', 0)));
    $offset   = $page * $limit;

    // Query.
    $storage = $this->entityTypeManager()->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', NodeInterface::PUBLISHED)
      ->condition('type', 'insights')
      ->sort('created', 'DESC');

    if ($keywords !== '') {
      $group = $query->orConditionGroup()->condition('title', $keywords, 'CONTAINS');
      if ($this->hasField('body')) {
        $group->condition('body.value', $keywords, 'CONTAINS');
      }
      $query->condition($group);
    }

    $total = (int) (clone $query)->count()->execute();
    $query->range($offset, $limit);
    $nids  = $query->execute();
    $nodes = $nids ? $storage->loadMultiple($nids) : [];

    // Base cacheability.
    $cacheability = (new CacheableMetadata())
      ->setCacheMaxAge(300)
      ->addCacheContexts(['url.query_args:q', 'url.query_args:limit', 'url.query_args:page'])
      ->addCacheTags(['node_list', 'node:insights']);

    // Build items. Capture bubbleable metadata from URLs explicitly.
    $items = [];
    foreach ($nodes as $node) {
      if (!$node instanceof NodeInterface) {
        continue;
      }

      $generated = $node->toUrl('canonical', ['absolute' => TRUE])->toString(TRUE);
      $url = $generated->getGeneratedUrl();

      // Add bubbleable dependencies explicitly. This prevents “leaked metadata”.
      $cacheability->addCacheableDependency($generated);
      $cacheability->addCacheableDependency($node);
      if ($owner = $node->getOwner()) {
        $cacheability->addCacheableDependency($owner);
      }

      $items[] = [
        'id'        => (int) $node->id(),
        'title'     => $node->label(),
        'summary'   => $this->extractSummary($node),
        'author'    => $owner?->getDisplayName() ?? '',
        'created'   => $this->dateFormatter->format($node->getCreatedTime(), 'custom', DATE_ATOM),
        'url'       => $url,
        'theme'     => $this->extractTheme($node),
        'read_time' => $this->extractReadTime($node),
      ];
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
      if ($node->hasField($field) && !$node->get($field)->isEmpty()) {
        $value = (string) $node->get($field)->value;
        $value = Html::decodeEntities(strip_tags($value));
        if ($value !== '') {
          return $value;
        }
      }
    }
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

  private function extractTheme(NodeInterface $node): array {
    foreach (['field_theme', 'field_tema'] as $field) {
      if ($node->hasField($field) && !$node->get($field)->isEmpty()) {
        $term = $node->get($field)->entity;
        if ($term) {
          return ['id' => (int) $term->id(), 'label' => $term->label()];
        }
      }
    }
    return ['id' => null, 'label' => ''];
  }

  private function extractReadTime(NodeInterface $node): string {
    foreach (['field_read_time', 'field_tiempo_de_lectura'] as $field) {
      if ($node->hasField($field) && !$node->get($field)->isEmpty()) {
        return trim((string) $node->get($field)->value);
      }
    }
    return '';
  }

  private function hasField(string $fieldName): bool {
    if ($this->fieldDefinitions === []) {
      $definitions = $this->entityFieldManager->getFieldDefinitions('node', 'insights');
      $this->fieldDefinitions = is_array($definitions) ? $definitions : [];
    }
    return isset($this->fieldDefinitions[$fieldName]);
  }
}
