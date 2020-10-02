<?php

namespace Drupal\Tests\location_migration\Traits;

use Drupal\Core\Entity\EntityInterface;

/**
 * Trait for location migration test assertions.
 */
trait LocationMigrationAssertionsTrait {

  /**
   * Filters out unconcerned properties from an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   An entity instance.
   *
   * @return array
   *   The important entity property values as array.
   */
  protected function getImportantEntityProperties(EntityInterface $entity) {
    $entity_type_id = $entity->getEntityTypeId();
    $exploded = explode('_', $entity_type_id);
    $prop_prefix = count($exploded) > 1
      ? $exploded[0] . implode('', array_map('ucfirst', array_slice($exploded, 1)))
      : $entity_type_id;
    $property_filter_preset_property = "{$prop_prefix}UnconcernedProperties";
    $entity_array = $entity->toArray();
    $unconcerned_properties = property_exists(get_class($this), $property_filter_preset_property)
      ? $this->$property_filter_preset_property
      : [
        'uuid',
        'langcode',
        'dependencies',
        '_core',
      ];

    foreach ($unconcerned_properties as $item) {
      unset($entity_array[$item]);
    }

    return $entity_array;
  }

}
