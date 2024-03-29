<?php

namespace Drupal\farm_map\Element;

use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Element\RenderElement;
use Drupal\farm_map\Event\MapRenderEvent;

/**
 * Provides a farm_map render element.
 *
 * @RenderElement("farm_map")
 */
class FarmMap extends RenderElement {

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    $class = get_class($this);
    return [
      '#pre_render' => [
        [$class, 'preRenderMap'],
      ],
      '#theme' => 'farm_map',
      '#map_type' => 'default',
    ];
  }

  /**
   * Pre-render callback for the map render array.
   *
   * @param array $element
   *   A renderable array containing a #map_type property, which will be
   *   appended to 'farm-map-' as the map element ID.
   *
   * @return array
   *   A renderable array representing the map.
   */
  public static function preRenderMap(array $element) {

    if (isset($element['#map_id'])) {
      $map_id = Html::getUniqueId('farm-map-' . $element['#map_id'] );
    }
    else {
      // Set the id to the map name.
      $map_id = Html::getUniqueId('farm-map-' . $element['#map_type'] );
    }
    $element['#attributes']['id'] = $map_id;

    // Get the map type.
    /** @var \Drupal\farm_map\Entity\MapTypeInterface $map */
    $map = \Drupal::entityTypeManager()->getStorage('map_type')->load($element['#map_type']);

    // Add the farm-map class.
    $element['#attributes']['class'][] = 'farm-map';

    // Attach the nfa-map and farm_map libraries.
    $element['#attached']['library'][] = 'farm_map/nfa-map';
    $element['#attached']['library'][] = 'farm_map/farm_map';

    // Include map settings.
    $map_settings = !empty($element['#map_settings']) ? $element['#map_settings'] : [];

    // Include the map options.
    $map_options = $map->getMapOptions();

    // Add the instance settings under the map id key.
    $instance_settings = array_merge_recursive($map_settings, $map_options);
    $element['#attached']['drupalSettings']['farm_map'][$map_id] = $instance_settings;

    // Create and dispatch a MapRenderEvent.
    $event = new MapRenderEvent($map, $element);
    \Drupal::service('event_dispatcher')->dispatch($event, MapRenderEvent::EVENT_NAME);

    // Return the element.
    return $event->element;
  }

}
