<?php

namespace Drupal\alternative_revisions;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManager;

class TableCreationManager {

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

    public function createNodeFieldDataTable() {
        $spec = [
            'description' => 'Node field data database table.',
            'fields' => [
            'id' => [
                'type' => 'serial',
                'not null' => TRUE,
            ],
            'nid' => [
                'type' => 'int',
                'not null' => TRUE,
                'unsigned' => TRUE,
            ],
            'type' => [
                'type' => 'varchar',
                'length' => 32,
                'not null' => TRUE,
            ],
            'title' => [
                'type' => 'varchar',
                'length' => 255,
                'not null' => TRUE,
            ],
            'status' => [
                'type' => 'tinyint',
                'not null' => TRUE,
            ],
            'created' => [
                'type' => 'int',
                'not null' => TRUE,
                'default' => 0,
            ],
            'revision_date' => [
                'type' => 'int',
                'not null' => TRUE,
                'default' => 0,
            ],
            ],
            'primary key' => ['id'],
        ];
        $this->schema->createTable('node_alt_revision_field_data', $spec);
    }

    public function createDatabaseTable(String $field_name, String $field_type, String $table_name) {
        $spec = NULL;
        switch ($field_type) {
            case "text_long":
                $spec = $this->getTextLongSpec($field_name);
                break;
            case "text_with_summary":
                $spec = $this->getTextSummarySpec($field_name);
                break;
            case "entity_reference":
                $spec = $this->getEntityReferenceSpec($field_name);
                break;
            case "entity_reference_revisions":
                // Add support for paragraphs
                break;
            case "image":
                $spec = $this->getBasicImageSpec($field_name);
                break;
            case "datetime":
                $spec = $this->getDateTimeSpec($field_name);
                break;
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
                'unsigned' => TRUE,
              ],
              'bundle' => [
                'type' => 'varchar',
                'length' => 128,
                'not null' => TRUE,
              ],
              'delta' => [
                'type' => 'int',
                'not null' => TRUE,
              ],
              'deleted' => [
                'type' => 'int',
                'not null' => TRUE,
              ],
              $field_name . '_value' => [
                'type' => 'text',
                'size' => 'big',
                'not null' => TRUE,
              ],
              $field_name . '_format' => [
                'type' => 'varchar',
                'length' => 255,
                'not null' => FALSE,
                'default' => NULL,
              ],
              'revision_date' => [
                'type' => 'int',
                'not null' => TRUE,
                'default' => 0,
              ],
            ],
            'primary key' => ['id'],
        ];
        return $spec;
    }

    private function getTextSummarySpec(String $field_name) {
        $spec = [
            'description' => 'Text with summary database table.',
            'fields' => [
              'id' => [
                'type' => 'serial',
                'not null' => TRUE,
              ],
              'entity_id' => [
                'type' => 'int',
                'not null' => TRUE,
                'unsigned' => TRUE,
              ],
              'bundle' => [
                'type' => 'varchar',
                'length' => 128,
                'not null' => TRUE,
              ],
              'delta' => [
                'type' => 'int',
                'not null' => TRUE,
              ],
              'deleted' => [
                'type' => 'int',
                'not null' => TRUE,
              ],
              $field_name . '_value' => [
                'type' => 'text',
                'size' => 'big',
                'not null' => TRUE,
              ],
              $field_name . '_summary' => [
                'type' => 'text',
                'size' => 'big',
                'not null' => FALSE,
                'default' => NULL,
              ],
              $field_name . '_format' => [
                'type' => 'varchar',
                'length' => 255,
                'not null' => FALSE,
                'default' => NULL,
              ],
              'revision_date' => [
                'type' => 'int',
                'not null' => TRUE,
                'default' => 0,
              ],
            ],
            'primary key' => ['id'],
        ];
        return $spec;
    }

    private function getBasicImageSpec(String $field_name) {
        $spec = [
            'description' => 'Image database table.',
            'fields' => [
              'id' => [
                'type' => 'serial',
                'not null' => TRUE,
              ],
              'entity_id' => [
                'type' => 'int',
                'not null' => TRUE,
                'unsigned' => TRUE,
              ],
              'bundle' => [
                'type' => 'varchar',
                'length' => 128,
                'not null' => FALSE,
                'default' => NULL,
              ],
              'delta' => [
                'type' => 'int',
                'not null' => TRUE,
                'unsigned' => TRUE,
              ],
              'deleted' => [
                'type' => 'int',
                'not null' => TRUE,
              ],
              $field_name . '_target_id' => [
                'type' => 'int',
                'not null' => TRUE,
                'unsigned' => TRUE,
              ],
              $field_name . '_alt' => [
                'type' => 'varchar',
                'length' => 512,
                'not null' => FALSE,
                'default' => NULL,
              ],
              $field_name . '_title' => [
                'type' => 'varchar',
                'length' => 1024,
                'not null' => FALSE,
                'default' => NULL,
              ],
              $field_name . '_width' => [
                'type' => 'int',
                'not null' => FALSE,
                'default' => NULL,
                'unsigned' => TRUE,
              ],
              $field_name . '_height' => [
                'type' => 'int',
                'not null' => FALSE,
                'default' => NULL,
                'unsigned' => TRUE,
              ],
              'revision_date' => [
                'type' => 'int',
                'not null' => TRUE,
                'default' => 0,
              ],
            ],
            'primary key' => ['id'],
        ];
        return $spec;
    }

    private function getEntityReferenceSpec(String $field_name) {
        $spec = [
            'description' => 'Entity reference database table.',
            'fields' => [
              'id' => [
                'type' => 'serial',
                'not null' => TRUE,
              ],
              'entity_id' => [
                'type' => 'int',
                'not null' => TRUE,
                'unsigned' => TRUE,
              ],
              'bundle' => [
                'type' => 'varchar',
                'length' => 128,
                'not null' => FALSE,
                'default' => NULL,
              ],
              'delta' => [
                'type' => 'int',
                'not null' => TRUE,
                'unsigned' => TRUE,
              ],
              'deleted' => [
                'type' => 'int',
                'not null' => TRUE,
              ],
              $field_name . '_target_id' => [
                'type' => 'int',
                'not null' => TRUE,
                'unsigned' => TRUE,
              ],
              'revision_date' => [
                'type' => 'int',
                'not null' => TRUE,
                'default' => 0,
              ],
            ],
            'primary key' => ['id'],
        ];
        return $spec;
    }

    private function getDateTimeSpec(String $field_name) {
        $spec = [
            'description' => 'Date time database table.',
            'fields' => [
              'id' => [
                'type' => 'serial',
                'not null' => TRUE,
              ],
              'entity_id' => [
                'type' => 'int',
                'not null' => TRUE,
                'unsigned' => TRUE,
              ],
              'bundle' => [
                'type' => 'varchar',
                'length' => 128,
                'not null' => TRUE,
              ],
              'delta' => [
                'type' => 'int',
                'not null' => TRUE,
              ],
              'deleted' => [
                'type' => 'int',
                'not null' => TRUE,
              ],
              $field_name . '_value' => [
                'type' => 'varchar',
                'length' => 20,
                'not null' => TRUE,
              ],
              'revision_date' => [
                'type' => 'int',
                'not null' => TRUE,
                'default' => 0,
              ],
            ],
            'primary key' => ['id'],
        ];
        return $spec;
    }

}