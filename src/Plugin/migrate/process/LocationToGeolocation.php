<?php

namespace Drupal\location_migration\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Row;

/**
 * Process plugin that converts D7 location field values to D8|D9 geolocation.
 *
 * @MigrateProcessPlugin(
 *   id = "location_to_geolocation",
 *   handle_multiples = TRUE
 * )
 */
class LocationToGeolocation extends LocationProcessPluginBase {

  /**
   * The geographical coordinate value that should be considered as empty.
   *
   * @var string
   */
  const COORDINATE_EMPTY_VALUE = '0.000000';

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    if (empty($lids = $this->getLocationIds($value, $row))) {
      // Empty field.
      return NULL;
    }

    $processed_values = [];
    foreach ($lids as $lid) {
      $location_data = $this->getLocationProperties($lid);
      $latitude = $location_data['latitude'] ?? self::COORDINATE_EMPTY_VALUE;
      $longitude = $location_data['longitude'] ?? self::COORDINATE_EMPTY_VALUE;
      $geolocation_source = $location_data['source'] ?? 0;
      // The "0.000000" values are the default values in Drupal 7, but that's
      // also a valid coordinate. But if the geolocation source is unknown (so
      // "$geolocation_source" is zero), it means that these properties
      // are empty.
      $source_is_not_empty = ((string) $latitude) !== self::COORDINATE_EMPTY_VALUE || ((string) $longitude) !== self::COORDINATE_EMPTY_VALUE || $geolocation_source !== 0;

      $processed_values[] = $source_is_not_empty
        ? ['lat' => $latitude, 'lng' => $longitude]
        : NULL;
    }

    return $processed_values;
  }

}
