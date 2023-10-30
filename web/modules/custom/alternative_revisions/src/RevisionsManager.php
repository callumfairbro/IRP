<?php

namespace Drupal\alternative_revisions;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManager;

class RevisionsManager {

    /**
     * @var \Drupal\Core\Database\Connection
     */
    protected $database;

    /**
     * @var \Drupal\Core\Entity\EntityTypeManager
     */
    protected $entityTypeManager;

    /**
     * @var \Drupal\Core\Config\ConfigFactoryInterface
     */
    protected $configFactory;

    protected $schema;

    public function __construct(Connection $database, EntityTypeManager $entityTypeManager, ConfigFactoryInterface $configFactory) {
        $this->database = $database;
        $this->entityTypeManager = $entityTypeManager;
        $this->configFactory = $configFactory;
        $this->schema = $this->database->schema();
    }

    public function createDatabaseTable(String $field_name, String $field_type, String $table_name) {
        $spec = NULL;
        switch ($field_type) {
            case "text_long":
                $spec = $this->getTextLongSpec($field_name);
                break;
            case "text_with_summary":
                break;
            case "entity_reference":
                break;
            case "entity_reference_revisions":
                break;
            case "image":
                break;
            case "datetime":
                break;
            default:
        }
        if ($spec) {
            $this->schema->createTable($table_name, $spec);
        }
    }

    private function getTextLongSpec(String $field_name) {
        $spec = [
            'description' => 'Long text database table.',
            'fields' => [
              'id' => [
                'type' => 'serial',
                'not null' => TRUE,
              ],
              'entity_id' => [
                'type' => 'int',
                'not null' => TRUE,
                'default' => NULL,
                'unsigned' => TRUE,
              ],
              $field_name . '_value' => [
                'type' => 'longtext',
                'not null' => TRUE,
                'default' => NULL,
              ],
              $field_name . '_format' => [
                'type' => 'varchar',
                'not null' => FALSE,
                'length' => 255,
                'default' => NULL,
              ],
              'revision_date' => [
                'type' => 'int',
                'not null' => TRUE,
                'default' => time(),
              ],
            ],
            'primary key' => ['id'],
        ];
        return $spec;
    }

}