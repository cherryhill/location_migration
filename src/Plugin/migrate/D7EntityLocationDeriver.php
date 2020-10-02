<?php

namespace Drupal\location_migration\Plugin\migrate;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\location_migration\LocationMigration;
use Drupal\migrate\Exception\RequirementsException;
use Drupal\migrate\Plugin\MigrationDeriverTrait;
use Drupal\migrate\Row;
use Drupal\migrate_drupal\Plugin\migrate\source\DrupalSqlBase;

/**
 * Deriver geolocation field config migrations of Drupal 7 Location CCK fields.
 *
 * This deriver class derives field storage, field instance and field widget
 * migrations of geolocation fields while the source field is mapped to an
 * address field.
 */
class D7EntityLocationDeriver extends DeriverBase {

  use StringTranslationTrait;
  use MigrationDeriverTrait;

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    $source = $this->getSourcePlugin($base_plugin_definition['source']['plugin']);
    assert($source instanceof DrupalSqlBase);

    try {
      $source->checkRequirements();
    }
    catch (RequirementsException $e) {
      // If the source plugin requirements failed, that means we do not have a
      // Drupal source database configured - there is nothing to generate.
      return $this->derivatives;
    }

    $derivatives = [];
    try {
      foreach ($source as $row) {
        assert($row instanceof Row);

        $entity_type = $row->getSourceProperty('entity_type');
        $bundle = $row->getSourceProperty('bundle');
        $values = [
          'entity_type' => $entity_type,
          'bundle' => $bundle,
        ];
        $derivative_id = $bundle !== NULL
          ? $entity_type . PluginBase::DERIVATIVE_SEPARATOR . $bundle
          : $entity_type;
        $derivatives[$derivative_id] = $values;
      }
    }
    catch (\Exception $e) {
      return $this->derivatives;
    }

    // Using the same derivative for field storage and field instance
    // migrations. Field storage migrations are always derived by their parent
    // entity type. Field instance migrations are derived by parent entity type
    // and by bundle. For bundle-less entities (like user), the bundle will be
    // the entity type, like "d7_entity_location_instance:user:user".
    foreach ($derivatives as $derivative_id => $values) {
      [
        'entity_type' => $entity_type,
        'bundle' => $bundle,
      ] = $values;
      $derivative_definition = $base_plugin_definition;
      $derivative_definition['source']['entity_type'] = $entity_type;
      $derivative_definition['migration_tags'][] = LocationMigration::ENTITY_LOCATION_MIGRATION_TAG;

      if ($bundle !== NULL) {
        $derivative_definition['source']['bundle'] = $bundle;
      }

      // Process dependencies.
      $migration_required_deps = $derivative_definition['migration_dependencies']['required'] ?? [];
      $storage_migration_dep_key = array_search('d7_entity_location', $migration_required_deps);
      if ($storage_migration_dep_key !== FALSE) {
        $derivative_definition['migration_dependencies']['required'][$storage_migration_dep_key] .= PluginBase::DERIVATIVE_SEPARATOR . $entity_type;
      }

      $instance_migration_dep_key = array_search('d7_entity_location_instance', $migration_required_deps);
      if ($instance_migration_dep_key !== FALSE) {
        $derivative_definition['migration_dependencies']['required'][$instance_migration_dep_key] .= implode(PluginBase::DERIVATIVE_SEPARATOR, array_filter([
          $entity_type,
          $bundle,
        ]));
      }

      if ($entity_type === 'node') {
        $derivative_definition['migration_dependencies']['required'][] = 'd7_node_type';
      }

      if ($entity_type === 'taxonomy_term') {
        $derivative_definition['migration_dependencies']['required'][] = 'd7_taxonomy_vocabulary';
      }

      $derivative_definition['label'] = !empty($bundle)
        ? $this->t('@label (@entity-type, @bundle)', [
          '@label' => $derivative_definition['label'],
          '@entity-type' => $entity_type,
          '@bundle' => $bundle,
        ])
        : $this->t('@label (@entity-type)', [
          '@label' => $derivative_definition['label'],
          '@entity-type' => $entity_type,
        ]);

      $this->derivatives[$derivative_id] = $derivative_definition;
    }

    return $this->derivatives;
  }

}
