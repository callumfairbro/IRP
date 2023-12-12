<?php

$databases = [];

$settings['hash_salt'] = file_get_contents('sites/default/hash_salt.txt');

$settings['file_scan_ignore_directories'] = [
  'node_modules',
  'bower_components',
];

$settings['config_sync_directory'] = '../configuration/platform/sync';

$databases['default']['default'] = [
  'database' => 'drupal',
  'username' => 'drupal',
  'password' => 'drupal',
  'prefix' => '',
  'host' => 'mysql',
  'port' => '3306',
  'namespace' => 'Drupal\\Core\\Database\\Driver\\mysql',
  'driver' => 'mysql',
];

$databases['blockchain']['default'] = [
  'database' => 'blockchain',
  'username' => 'drupal',
  'password' => 'drupal',
  'prefix' => '',
  'host' => 'mysql',
  'port' => '3306',
  'namespace' => 'Drupal\\Core\\Database\\Driver\\mysql',
  'driver' => 'mysql',
];

$settings['file_temp_path'] = '/tmp';

//Twig develpment mode
$settings['container_yamls'][] = 'sites/default/development.services.yml';

//Disable twig cache
$settings['cache']['bins']['render'] = 'cache.backend.null';
$settings['cache']['bins']['dynamic_page_cache'] = 'cache.backend.null';
$settings['cache']['bins']['page'] = 'cache.backend.null';

$settings['file_files_path'] = 'sites/default/files';
$settings['file_private_path'] = 'sites/default/private/files';
