<?php

namespace Drupal\Tests\location_migration\Kernel\Migrate\d7;

use Drupal\Core\Site\Settings;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Plugin\MigrationPluginManagerInterface;
use Drupal\Tests\migrate_drupal\Kernel\MigrateDrupalTestBase;

/**
 * Base class for Location Migration's kernel tests.
 */
abstract class LocationMigrationTestBase extends MigrateDrupalTestBase {

  /**
   * Returns the drupal-relative path to the database fixture file.
   *
   * @return string
   *   The path to the database file.
   */
  abstract public function getDatabaseFixtureFilePath(): string;

  /**
   * Returns the absolute path to the file system fixture directory.
   *
   * @return string|null
   *   The absolute path to the file system fixture directory.
   */
  public function getFilesystemFixturePath(): ?string {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();
    $this->loadFixture($this->getDatabaseFixtureFilePath());
    $module_handler = \Drupal::moduleHandler();

    if ($module_handler->moduleExists('file')) {
      $this->installEntitySchema('file');
      $this->installSchema('file', 'file_usage');
    }

    if ($module_handler->moduleExists('node')) {
      $this->installSchema('node', 'node_access');
    }

    if ($module_handler->moduleExists('comment')) {
      $this->installSchema('comment', 'comment_entity_statistics');
    }

    // Let's install all default configuration.
    $module_list = array_keys($module_handler->getModuleList());
    $this->installConfig($module_list);
  }

  /**
   * Sets the type of the node migration.
   *
   * @param bool $classic_node_migration
   *   Whether nodes should be migrated with the 'classic' way. If this is
   *   FALSE, and the current Drupal instance has the 'complete' migration, then
   *   the complete node migration will be used.
   */
  protected function setClassicNodeMigration(bool $classic_node_migration): void {
    $current_method = Settings::get('migrate_node_migrate_type_classic', FALSE);

    if ($current_method !== $classic_node_migration) {
      $this->setSetting('migrate_node_migrate_type_classic', $classic_node_migration);
    }
  }

  /**
   * Executes the relevant migrations.
   *
   * @param bool $classic_node_migration
   *   Whether node migrations should be executed with the classic node
   *   migration or not.
   */
  protected function executeRelevantMigrations(bool $classic_node_migration = FALSE): void {
    // Execute file migrations if fixture path is provided.
    if ($fs_fixture_path = $this->getFilesystemFixturePath()) {
      foreach (['d7_file', 'd7_file_private'] as $file_migration_plugin_id) {
        $file_migration = $this->getMigration($file_migration_plugin_id);
        $source = $file_migration->getSourceConfiguration();
        $source['constants']['source_base_path'] = $fs_fixture_path;
        $file_migration->set('source', $source);
        $this->executeMigration($file_migration);
      }
    }

    // Ignore irrelevant errors.
    $this->startCollectingMessages();
    $this->executeMigrations([
      'd7_view_modes',
      'd7_field',
      'd7_node_type',
      'd7_field_instance',
      'd7_field_formatter_settings',
      'd7_field_instance_widget_settings',
      'd7_filter_format',
    ]);
    $this->stopCollectingMessages();

    $this->startCollectingMessages();
    $this->executeMigrations([
      'd7_taxonomy_vocabulary',
      'd7_entity_location:taxonomy_term',
      'd7_entity_location_instance:taxonomy_term:vocabulary_1',
      'd7_entity_location_widget_settings:taxonomy_term:vocabulary_1',
      'd7_taxonomy_term:vocabulary_1',
      'd7_entity_location_formatter_settings:taxonomy_term:vocabulary_1',
    ]);
    $this->stopCollectingMessages();
    $this->assertEmpty($this->migrateMessages);

    $this->startCollectingMessages();
    $this->executeMigrations([
      'd7_user_role',
      'd7_entity_location:user',
      'd7_entity_location_instance:user:user',
      'd7_entity_location_widget_settings:user:user',
      'd7_user',
      'd7_entity_location_formatter_settings:user:user',
    ]);
    $this->stopCollectingMessages();
    $this->assertEmpty($this->migrateMessages);

    $this->startCollectingMessages();
    $this->executeMigrationWithDependencies($classic_node_migration ? 'd7_node' : 'd7_node_complete');
    $this->stopCollectingMessages();
    $this->assertEmpty($this->migrateMessages);

    $this->startCollectingMessages();
    $this->executeMigrations([
      'd7_field_location_widget:node',
      'd7_field_location_formatter:node',
    ]);
    $this->stopCollectingMessages();
    $this->assertEmpty($this->migrateMessages);

    $this->startCollectingMessages();
    $this->executeMigrations([
      'd7_entity_location_widget_settings:node',
      'd7_entity_location_formatter_settings:node',
    ]);
    $this->stopCollectingMessages();
    $this->assertEmpty($this->migrateMessages);
  }

  /**
   * Execute a migration's dependencies followed by the migration.
   *
   * @param string $plugin_id
   *   The migration id to execute.
   */
  protected function executeMigrationWithDependencies(string $plugin_id): void {
    $migration_plugin_manager = $this->container->get('plugin.manager.migration');
    assert($migration_plugin_manager instanceof MigrationPluginManagerInterface);
    $migrations = $migration_plugin_manager->createInstances($plugin_id);
    foreach ($migrations as $migration) {
      $this->executeMigrationDependencies($migration);
      $this->executeMigration($migration);
    }
  }

  /**
   * Find and execute a migration's dependencies.
   *
   * @param \Drupal\migrate\Plugin\MigrationInterface $migration
   *   The Migration from which to execute dependencies.
   */
  protected function executeMigrationDependencies(MigrationInterface $migration): void {
    $dependencies = $migration->getMigrationDependencies();
    foreach ($dependencies['required'] as $dependency) {
      $plugin = $this->getMigration($dependency);
      if (!$plugin->allRowsProcessed()) {
        $this->executeMigrationDependencies($plugin);
        $this->executeMigration($plugin);
      }
    }
    foreach ($dependencies['optional'] as $dependency) {
      if ($plugin = $this->getMigration($dependency)) {
        if (!$plugin->allRowsProcessed()) {
          $this->executeMigrationDependencies($plugin);
          $this->executeMigration($plugin);
        }
      }
    }
  }

}
