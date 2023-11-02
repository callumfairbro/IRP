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

    public function checkForRevisions($node, $original) {
        $nid = $node->id();
        $saved_timestamp = $node->getChangedTime();
        $fields = $node->getFields();
        foreach ($fields as $field_name => $field) {
            $revision_table_name = 'node_alt_revision__' . $field_name;
            if ($this->schema->tableExists($revision_table_name)) {
                $field_type = $field->getFieldDefinition()->getType();
                switch ($field_type) {
                    case 'text_long':
                        $this->checkTextLong($nid, $field_name, $revision_table_name, $saved_timestamp);
                        break;
                }
            }
        }
    }

    private function checkTextLong($nid, $field_name, $revision_table_name, $saved_timestamp) {
        $value_field = $field_name . '_value';
        $format_field = $field_name . '_format';

        $new_data_query = $this->database->select('node__' . $field_name, 'tl');
        $new_data_query->fields('tl', ['entity_id', 'bundle',' delta', $value_field, $format_field]);
        $new_data_query->condition('entity_id', $nid, '=');
        $new_data = $new_data_query->execute()->fetchAll(); 

        $original_data_query = $this->database->select($revision_table_name, 'tl');
        $original_data_query->fields('tl', ['entity_id', 'bundle',' delta', $value_field, $format_field]);
        $original_data_query->condition('entity_id', $nid, '=');
        $original_data = $original_data_query->execute()->fetchAll();

        if (!$original_data) {
            foreach($new_data as $item) {
                $insert_query = $this->database->insert($revision_table_name);
                $insert_query->fields([
                    'entity_id' => $item->entity_id,
                    'bundle' => $item->bundle,
                    'delta' => $item->delta,
                    'deleted' => 0,
                    $value_field => $item->$value_field,
                    $format_field => $item->$format_field,
                    'revision_date' => $saved_timestamp,
                ]);
                $insert_query->execute();
            }   
        } elseif (count($new_data) == count($original_data)) {
            for ($i = 0; $i < count($new_data); $i++) {
                $new = $new_data[$i];
                $original = $original_data[$i];
                if (
                    $new->value_field != $original->value_field ||
                    $new->value_field != $original->value_field
                ) {
                    $insert_query = $this->database->insert($revision_table_name);
                    $insert_query->fields([
                        'entity_id' => $new->entity_id,
                        'bundle' => $new->bundle,
                        'delta' => $new->delta,
                        'deleted' => 0,
                        $value_field => $new->$value_field,
                        $format_field => $new->$format_field,
                        'revision_date' => $saved_timestamp,
                    ]);
                    $insert_query->execute();
                }
            }
        } elseif (count($new_data) < count($original_data)) {
            
        } elseif (count($new_data) > count($original_data)) {

        }
    }

}