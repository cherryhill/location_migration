<?php

namespace Drupal\location_migration;

use Drupal\location_migration\Plugin\migrate\source\EntityLocationFieldInstance;
use Drupal\migrate\Plugin\MigrationDeriverTrait;
use Drupal\migrate\Row;

/**
 * Class for altering migration plugins.
 *
 * @see location_migration_migration_plugins_alter()
 */
class MigrationPluginAlterer {

  use MigrationDeriverTrait;

  /**
   * Adds the required location dependencies to content entity migrations.
   *
   * @param array[] $definitions
   *   An array of the available migration plugin definitions, keyed by their
   *   ID.
   */
  public static function alterMigrationPlugins(array &$definitions) {
    $d7_definitions = array_filter($definitions, function (array $migration_plugin) {
      $migration_tags = $migration_plugin['migration_tags'] ?? [];
      return in_array('Drupal 7', $migration_tags, TRUE);
    });

    // Location Migration creates "user", "node" or "taxonomy_term" derivatives
    // for migrations using "d7_entity_location" source only when the
    // corresponding location submodule is enabled.
    $entity_location_migrations = array_filter($d7_definitions, function (array $migration_plugin) {
      $migration_tags = $migration_plugin['migration_tags'] ?? [];
      return in_array(LocationMigration::ENTITY_LOCATION_MIGRATION_TAG, $migration_tags, TRUE);
    });
    $entity_location_migrations_per_type_and_bundle = [];
    foreach ($entity_location_migrations as $entity_location_migration_plugin_definition) {
      $entity_type_id = $entity_location_migration_plugin_definition['source']['entity_type'];
      $bundle = $entity_location_migration_plugin_definition['source']['bundle'] ?? NULL;

      if ($bundle) {
        $entity_location_migrations_per_type_and_bundle[$entity_type_id][$bundle] = $bundle;
      }
    }

    $node_migration_ids = array_keys(
      array_filter($d7_definitions, function (array $migration_plugin) {
        $migration_tags = $migration_plugin['migration_tags'] ?? [];
        $destination_plugin = $migration_plugin['destination']['plugin'] ?? NULL;
        return $destination_plugin &&
          !in_array(LocationMigration::LOCATION_MIGRATION_ALTER_DONE, $migration_tags, TRUE) &&
          in_array($destination_plugin, [
            'entity:node',
            'entity_revision:node',
            'entity_complete:node',
          ], TRUE);
      })
    );
    $taxonomy_migration_ids = array_keys(
      array_filter($d7_definitions, function (array $migration_plugin) {
        $migration_tags = $migration_plugin['migration_tags'] ?? [];
        $destination_plugin = $migration_plugin['destination']['plugin'] ?? NULL;
        return $destination_plugin &&
          !in_array(LocationMigration::LOCATION_MIGRATION_ALTER_DONE, $migration_tags, TRUE) &&
          $destination_plugin === 'entity:taxonomy_term';
      })
    );
    $user_migration_ids = array_keys(
      array_filter($d7_definitions, function (array $migration_plugin) {
        $migration_tags = $migration_plugin['migration_tags'] ?? [];
        $destination_plugin = $migration_plugin['destination']['plugin'] ?? NULL;
        return $destination_plugin &&
          !in_array(LocationMigration::LOCATION_MIGRATION_ALTER_DONE, $migration_tags, TRUE) &&
          $destination_plugin === 'entity:user';
      })
    );

    $node_migration_ids_per_bundle = [];
    foreach ($node_migration_ids as $node_migration_id) {
      $bundle = $d7_definitions[$node_migration_id]['source']['node_type'] ?? NULL;

      if ($bundle) {
        $node_migration_ids_per_bundle[$bundle][] = $node_migration_id;
      }
    }

    $taxonomy_migration_ids_per_bundle = [];
    foreach ($taxonomy_migration_ids as $taxonomy_migration_id) {
      $bundle = $d7_definitions[$taxonomy_migration_id]['source']['bundle'] ?? NULL;

      if ($bundle) {
        $taxonomy_migration_ids_per_bundle[$bundle][] = $taxonomy_migration_id;
      }
    }

    // Add "entity location" field config migration dependencies to those
    // content migrations that needs them.
    $entity_migration_plugin_ids_with_entity_location = [];
    foreach ($entity_location_migrations as $entity_location_migration_plugin_id => $entity_location_migration_plugin_def) {
      $entity_type_id = $entity_location_migration_plugin_def['source']['entity_type'];
      $bundle = $entity_location_migration_plugin_def['source']['bundle'] ?? NULL;
      $migration_ids_to_extend = [];

      if (empty($entity_location_migrations_per_type_and_bundle[$entity_type_id])) {
        continue;
      }

      switch ($entity_type_id) {
        case 'node':
          if ($bundle && !empty($node_migration_ids_per_bundle[$bundle])) {
            $migration_ids_to_extend = $node_migration_ids_per_bundle[$bundle];
          }
          else {
            foreach ($entity_location_migrations_per_type_and_bundle['node'] as $node_type) {
              $migration_ids_to_extend = array_unique(
                array_merge(
                  $migration_ids_to_extend,
                  $node_migration_ids_per_bundle[$node_type]
                )
              );
            }
          }
          break;

        case 'taxonomy_term':
          if ($bundle && !empty($taxonomy_migration_ids_per_bundle[$bundle])) {
            $migration_ids_to_extend = $taxonomy_migration_ids_per_bundle[$bundle];
          }
          else {
            foreach ($entity_location_migrations_per_type_and_bundle['taxonomy_term'] as $vocabulary_id) {
              $migration_ids_to_extend = array_unique(
                array_merge(
                  $migration_ids_to_extend,
                  $taxonomy_migration_ids_per_bundle[$vocabulary_id]
                )
              );
            }
          }
          break;

        case 'user':
          $migration_ids_to_extend = $user_migration_ids;
          break;
      }

      if (!empty($migration_ids_to_extend)) {
        $preexisting_ids = $entity_migration_plugin_ids_with_entity_location[$entity_type_id] ?? [];
        $entity_migration_plugin_ids_with_entity_location[$entity_type_id] = array_unique(
          array_merge($preexisting_ids, $migration_ids_to_extend)
        );
      }
      foreach ($migration_ids_to_extend as $migration_id_to_extend) {
        $definition_tags = $definitions[$migration_id_to_extend]['migration_tags'] ?? [];
        $definitions[$migration_id_to_extend]['migration_dependencies']['required'][] = $entity_location_migration_plugin_id;
        $definitions[$migration_id_to_extend]['migration_tags'] = array_unique(
          array_merge($definition_tags, [LocationMigration::LOCATION_MIGRATION_ALTER_DONE])
        );
      }
    }

    // We have to determine which entity locations might have multiple values.
    $entity_location_cardinalities = [];
    $elfc_source = static::getSourcePlugin('d7_entity_location_field_instance');
    assert($elfc_source instanceof EntityLocationFieldInstance);
    foreach ($elfc_source as $elfc_source_row) {
      assert($elfc_source_row instanceof Row);
      // It its enough to check the address field's row.
      if ($elfc_source_row->getSourceProperty('type') !== 'address') {
        continue;
      }
      [
        'entity_type' => $elfc_entity_type,
        'bundle' => $elfc_bundle,
        'cardinality' => $elfc_cardinality,
      ] = $elfc_source_row->getSource();
      $entity_location_cardinalities[$elfc_entity_type][$elfc_bundle] = $elfc_cardinality;
    }

    // Add the field value processes to the content entity migrations that needs
    // them.
    foreach ($entity_migration_plugin_ids_with_entity_location as $entity_type_id => $content_migration_plugin_ids) {

      foreach ($content_migration_plugin_ids as $content_migration_plugin_id) {
        $definition = &$definitions[$content_migration_plugin_id];
        $bundle = $definition['source']['node_type'] ?? $definition['source']['bundle'] ?? 'user';
        $entity_location_cardinality = $entity_location_cardinalities[$entity_type_id][$bundle];
        $base_name = LocationMigration::getEntityLocationFieldBaseName($entity_type_id, $entity_location_cardinality);
        $process_base = ['entity_type_id' => $entity_type_id];

        // Location to address field.
        $definition['process'][$base_name] = [
          'plugin' => 'location_to_address',
        ] + $process_base;
        // Location to geolocation field.
        $definition['process'][LocationMigration::getGeolocationFieldName($base_name)] = [
          'plugin' => 'location_to_geolocation',
        ] + $process_base;
        // Location email to email field.
        $definition['process'][LocationMigration::getEmailFieldName($base_name)] = [
          'plugin' => 'location_email_to_email',
        ] + $process_base;
        // Location fax to telephone field.
        $definition['process'][LocationMigration::getFaxFieldName($base_name)] = [
          'plugin' => 'location_fax_to_telephone',
        ] + $process_base;
        // Location phone to telephone field.
        $definition['process'][LocationMigration::getPhoneFieldName($base_name)] = [
          'plugin' => 'location_phone_to_telephone',
        ] + $process_base;
        // Location "www" to link field.
        $definition['process'][LocationMigration::getWwwFieldName($base_name)] = [
          'plugin' => 'location_www_to_link',
        ] + $process_base;
      }
    }
  }

}
