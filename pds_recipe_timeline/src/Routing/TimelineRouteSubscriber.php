<?php

declare(strict_types=1);

namespace Drupal\pds_recipe_timeline\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Routes timeline AJAX operations through the specialized controller.
 */
final class TimelineRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    //1.- Map the template AJAX endpoints to the timeline-aware controller methods.
    $targets = [
      'pds_recipe_template.create_row' => '\\Drupal\\pds_recipe_timeline\\Controller\\TimelineRowController::createRow',
      'pds_recipe_template.update_row' => '\\Drupal\\pds_recipe_timeline\\Controller\\TimelineRowController::update',
      'pds_recipe_template.list_rows' => '\\Drupal\\pds_recipe_timeline\\Controller\\TimelineRowController::list',
    ];

    foreach ($targets as $route_name => $controller) {
      //2.- Skip missing routes so deployments without the base module stay safe.
      $route = $collection->get($route_name);
      if (!$route) {
        continue;
      }

      //3.- Point the controller to our decorator so recipe specific storage hooks run.
      $route->setDefault('_controller', $controller);
    }
  }

}
