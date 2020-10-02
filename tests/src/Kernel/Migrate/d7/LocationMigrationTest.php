<?php

namespace Drupal\Tests\location_migration\Kernel\Migrate\d7;

use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Tests\location_migration\Traits\LocationMigrationAssertionsTrait;

/**
 * Tests location migrations.
 *
 * @group location_migration
 */
class LocationMigrationTest extends LocationMigrationTestBase {

  use LocationMigrationAssertionsTrait;

  /**
   * {@inheritdoc}
   */
  protected $profile = 'minimal';

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'address',
    'comment',
    'datetime',
    'editor',
    'field',
    'file',
    'filter',
    'geolocation',
    'location_migration',
    'migrate',
    'migrate_drupal',
    'node',
    'options',
    'system',
    'taxonomy',
    'text',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  public function getDatabaseFixtureFilePath(): string {
    return drupal_get_path('module', 'location_migration') . '/tests/fixtures/d7/drupal7_location.php';
  }

  /**
   * Tests the migration of Drupal 7 location fields.
   *
   * @dataProvider providerLocationMigrations
   */
  public function testLocationMigrations(bool $classic_node_migration) {
    if (!empty($modules_to_install)) {
      $module_installer = $this->container->get('module_installer');
      assert($module_installer instanceof ModuleInstallerInterface);
      $module_installer->install($modules_to_install);
    }

    // Execute the relevant migrations.
    $this->executeRelevantMigrations($classic_node_migration);
  }

  /**
   * Data provider for ::testLocationMigrations().
   *
   * @return array
   *   The test cases.
   */
  public function providerLocationMigrations() {
    $test_cases = [
      'Classic node migration' => [
        'Classic node migration' => TRUE,
      ],
      'Complete node migration' => [
        'Classic node migration' => FALSE,
      ],
    ];

    // Drupal 8.8.x only has 'classic' node migrations.
    // @see https://www.drupal.org/node/3105503
    if (version_compare(\Drupal::VERSION, '8.9', '<')) {
      $test_cases = array_filter($test_cases, function ($test_case) {
        return $test_case['Classic node migration'];
      });
    }

    return $test_cases;
  }

}
