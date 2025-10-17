<?php

namespace Drupal\pds_suite\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * API controller that exposes curated PDS content.
 */
class PdsApiController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * Constructs the controller.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DateFormatterInterface $dateFormatter,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    //1.- Resolve dependencies from the service container when instantiated.
    return new static(
      //2.- Inject the configuration factory for accessing module settings.
      $container->get('config.factory'),
      //3.- Inject the entity type manager to load content entities efficiently.
      $container->get('entity_type.manager'),
      //4.- Inject the date formatter to normalize timestamps in responses.
      $container->get('date.formatter'),
      //5.- Inject the logger factory for optional request logging.
      $container->get('logger.factory'),
    );
  }

  /**
   * Returns curated content in JSON format.
   */
  public function content(): JsonResponse {
    //1.- Pull live configuration to honor administrator preferences.
    $config = $this->configFactory->get('pds_suite.settings');
    //2.- Define the node bundles that should appear in the aggregated feed.
    $bundles = [
      'insights' => 3,
      'investment_strategies' => 2,
      'regulatory_notices' => 2,
      'executives' => 4,
      'market_perpectives' => 2,
      'ways_to_invest' => 2,
      'financial_education' => 2,
    ];
    //3.- Prepare a normalized payload keyed by bundle machine names.
    $payload = [];
    foreach ($bundles as $bundle => $limit) {
      //4.- Build an entity query to retrieve the newest published nodes per bundle.
      $query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('status', 1)
        ->condition('type', $bundle)
        ->sort('created', 'DESC')
        ->range(0, $limit);
      $nids = $query->execute();
      //5.- Load the full node entities for transformation into public data.
      $nodes = $nids ? $this->entityTypeManager->getStorage('node')->loadMultiple($nids) : [];
      $payload[$bundle] = [];
      foreach ($nodes as $node) {
        //6.- Append curated node metadata to the payload.
        $payload[$bundle][] = [
          'id' => $node->uuid(),
          'title' => $node->label(),
          'url' => $node->toUrl('canonical', ['absolute' => true])->toString(),
          'created' => $this->dateFormatter->format($node->getCreatedTime(), 'html_date'),
        ];
      }
    }

    //7.- Compose response metadata that includes configuration values.
    $response = [
      'generated' => $this->dateFormatter->format(time(), 'html_datetime'),
      'endpoint' => $config->get('api_endpoint'),
      'bundles' => array_keys($bundles),
      'items' => $payload,
    ];

    //8.- Optionally log API access for observability.
    if ($config->get('enable_logging')) {
      $this->loggerFactory->get('pds_suite')->info('PDS API endpoint delivered @count bundles.', [
        '@count' => count($payload),
      ]);
    }

    //9.- Return the JSON response to the HTTP client.
    return new JsonResponse($response);
  }

}
