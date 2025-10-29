<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_timeline\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Template\Attribute;

/**
 * Provides a timeline showcase for executive trajectories.
 *
 * @Block(
 *   id = "pds_timeline_block",
 *   admin_label = @Translation("PDS Timeline"),
 *   category = @Translation("PDS Recipes")
 * )
 */
final class PdsTimelineBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    //1.- Definimos atributos HTML base para permitir que otros módulos extiendan el bloque.
    $attributes = new Attribute([
      'id' => 'principal-timeline',
      'class' => ['principal-timeline'],
    ]);

    //2.- Retornamos un render array que delega la salida final al template Twig.
    return [
      '#theme' => 'pds_timeline_block',
      '#attributes' => $attributes,
      '#attached' => [
        'library' => ['pds_recipe_timeline/pds_recipe_timeline.public'],
      ],
    ];
  }

}
