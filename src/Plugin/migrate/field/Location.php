<?php

namespace Drupal\location_migration\Plugin\migrate\field;

use Drupal\Core\Plugin\PluginBase;
use Drupal\location_migration\LocationMigration;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate_drupal\Plugin\migrate\field\FieldPluginBase;
use Drupal\migrate_drupal\Plugin\migrate\source\DrupalSqlBase;

/**
 * Migration process plugin for migrations related to Drupal 7 location fields.
 *
 * @MigrateField(
 *   id = "location",
 *   core = {7},
 *   type_map = {
 *    "location" = "address"
 *   },
 *   source_module = "location_cck",
 *   destination_module = "address"
 * )
 */
class Location extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function getFieldFormatterMap() {
    return [
      'location_default' => 'address_default',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFieldWidgetMap() {
    return [
      'location' => 'address_default',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function alterFieldInstanceMigration(MigrationInterface $migration) {
    parent::alterFieldInstanceMigration($migration);
    $migration->mergeProcessOfProperty('settings', [
      'plugin' => 'location_to_address_field_settings',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function defineValueProcessPipeline(MigrationInterface $migration, $field_name, $data) {
    $migration->mergeProcessOfProperty($field_name, [
      'plugin' => 'location_to_address',
      'source' => $field_name,
    ]);

    $migration_dependencies = $migration->getMigrationDependencies() + ['required' => []];

    // Address cannot store geographical locations, so we need a separate
    // geolocation field.
    $migration->mergeProcessOfProperty(LocationMigration::getGeolocationFieldName($field_name), [
      'plugin' => 'location_to_geolocation',
      'source' => $field_name,
    ]);
    // These processes only make sense if the corresponding source (and
    // destination) modules are enabled, but it seems that they do not cause any
    // kind of violations.
    $migration->mergeProcessOfProperty(LocationMigration::getEmailFieldName($field_name), [
      'plugin' => 'location_email_to_email',
      'source' => $field_name,
    ]);
    $migration->mergeProcessOfProperty(LocationMigration::getFaxFieldName($field_name), [
      'plugin' => 'location_fax_to_telephone',
      'source' => $field_name,
    ]);
    $migration->mergeProcessOfProperty(LocationMigration::getPhoneFieldName($field_name), [
      'plugin' => 'location_phone_to_telephone',
      'source' => $field_name,
    ]);
    $migration->mergeProcessOfProperty(LocationMigration::getWwwFieldName($field_name), [
      'plugin' => 'location_www_to_link',
      'source' => $field_name,
    ]);

    // Add the extra field's migrations as required dependencies.
    $required_dependency_base_plugin_ids = [
      'd7_field_location_instance',
      'd7_field_location_widget',
    ];
    $derivative_suffixes = [
      $data['entity_type'],
      $data['bundle'],
    ];
    $this->mergeDerivedRequiredDependencies($migration_dependencies, $required_dependency_base_plugin_ids, $derivative_suffixes);
    $migration->set('migration_dependencies', $migration_dependencies);
  }

  /**
   * Merges derivative migration dependencies.
   *
   * @param array $migration_dependencies
   *   The array of the migration dependencies.
   * @param string[] $base_plugin_ids
   *   An array of base plugin IDs of the required, additional migration
   *   dependencies.
   * @param string[] $derivative_pieces
   *   An array of the derivative pieces.
   */
  protected function mergeDerivedRequiredDependencies(array &$migration_dependencies, array $base_plugin_ids, array $derivative_pieces): void {
    $dependencies_to_add = [];
    $derivative_suffix = implode(PluginBase::DERIVATIVE_SEPARATOR, $derivative_pieces);
    foreach ($base_plugin_ids as $base_plugin_id) {
      $dependencies_to_add[] = implode(PluginBase::DERIVATIVE_SEPARATOR, [
        $base_plugin_id,
        $derivative_suffix,
      ]);
    }

    $migration_dependencies['required'] = array_unique(
      array_merge(
        array_values($migration_dependencies['required']),
        $dependencies_to_add
      )
    );
  }

  /**
   * Checks if a given module is enabled in the source Drupal database.
   *
   * @param \Drupal\migrate\Plugin\MigrationInterface $migration
   *   The current migration.
   * @param string $module_name
   *   Name of module to check.
   *
   * @return bool
   *   TRUE if module is enabled on the origin system, FALSE if not.
   */
  protected function moduleExistsInSource(MigrationInterface $migration, string $module_name): bool {
    $source = $migration->getSourcePlugin();
    assert($source instanceof DrupalSqlBase);
    $system_data = $source->getSystemData();
    return !empty($system_data['module'][$module_name]['status']);
  }

}
