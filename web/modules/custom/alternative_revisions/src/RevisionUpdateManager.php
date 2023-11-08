<?php

namespace Drupal\alternative_revisions;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManager;

class RevisionUpdateManager {

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

    public function checkForRevisions($node, $original) {
        $nid = $node->id();
        $saved_timestamp = $node->getChangedTime();
        $this->checkFieldData($nid, $saved_timestamp);
        $fields = $node->getFields();
        foreach ($fields as $field_name => $field) {
            $revision_table_name = 'node_alt_revision__' . $field_name;
            if ($this->schema->tableExists($revision_table_name)) {
                $field_type = $field->getFieldDefinition()->getType();
                $original_deltas = NULL;
                if ($original->$field_name) {
                    $original_deltas = $original->$field_name->count();
                }       
                switch ($field_type) {
                    case 'text_long':
                        $this->checkTextLong($nid, $field_name, $revision_table_name, $original_deltas, $saved_timestamp);
                        break;
                    case 'text_with_summary':
                        $this->checkTextSummary($nid, $field_name, $revision_table_name, $original_deltas, $saved_timestamp);
                        break;
                    case 'datetime':
                        $this->checkDateTime($nid, $field_name, $revision_table_name, $original_deltas, $saved_timestamp);
                        break;
                    case 'image':
                        $this->checkBasicImage($nid, $field_name, $revision_table_name, $original_deltas, $saved_timestamp);
                        break;
                    case 'entity_reference':
                        $this->checkEntityReference($nid, $field_name, $revision_table_name, $original_deltas, $saved_timestamp);
                        break;
                }
            }
        }
    }

    private function checkFieldData($nid, $saved_timestamp) {
        $new_data_query = $this->database->select('node_field_data', 'fd');
        $new_data_query->fields('fd', ['nid', 'type', 'title', 'status', 'created']);
        $new_data_query->condition('nid', $nid, '=');
        $new_data = $new_data_query->execute()->fetchAll();
        if ($new_data) {
            $new_data = reset($new_data);
        } 

        $original_data_query = $this->database->select('node_alt_revision_field_data', 'fd');
        $original_data_query->fields('fd', ['nid', 'type', 'title', 'status', 'created']);
        $original_data_query->condition('nid', $nid, '=');
        $original_data_query->orderBy('revision_date', 'DESC');
        $original_data_query->range(0,1);
        $original_data = $original_data_query->execute()->fetchAll();
        if ($original_data && is_array($original_data)) {
            $original_data = reset($original_data);
        }

        if (!$original_data) {
            $insert_query = $this->database->insert('node_alt_revision_field_data');
            $insert_query->fields([
                'nid' => $nid,
                'type' => $new_data->type,
                'title' => $new_data->title,
                'deleted' => 0,
                'status' => $new_data->status,
                'created' => $new_data->created,
                'revision_date' => $saved_timestamp,
            ]);
            $insert_query->execute();
        } elseif (!$new_data) {
            $insert_query = $this->database->insert('node_alt_revision_field_data');
            $insert_query->fields([
                'nid' => $nid,
                'type' => $original_data->type,
                'title' => $original_data->title,
                'deleted' => 1,
                'status' => $original_data->status,
                'created' => $original_data->created,
                'revision_date' => $saved_timestamp,
            ]);
            $insert_query->execute();
        } else {
            if (
                $new_data->nid != $original_data->nid ||
                $new_data->type != $original_data->type ||
                $new_data->title != $original_data->title ||
                $new_data->type != $original_data->type ||
                $new_data->created != $original_data->created
            ) {
                $insert_query = $this->database->insert('node_alt_revision_field_data');
                $insert_query->fields([
                    'nid' => $nid,
                    'type' => $new_data->type,
                    'title' => $new_data->title,
                    'deleted' => 0,
                    'status' => $new_data->status,
                    'created' => $new_data->created,
                    'revision_date' => $saved_timestamp,
                ]);
                $insert_query->execute();
            }
        }
    }

    private function checkTextLong($nid, $field_name, $revision_table_name, $original_deltas, $saved_timestamp) {
        $value_field = $field_name . '_value';
        $format_field = $field_name . '_format';

        $new_data_query = $this->database->select('node__' . $field_name, 'tl');
        $new_data_query->fields('tl', ['entity_id', 'bundle', 'delta', $value_field, $format_field]);
        $new_data_query->condition('entity_id', $nid, '=');
        $new_data = $new_data_query->execute()->fetchAll(); 

        $original_data = [];
        if ($original_deltas) {
            for ($i = 0; $i < $original_deltas; $i++) {
                $original_data_query = $this->database->select($revision_table_name, 'tl');
                $original_data_query->fields('tl', ['entity_id', 'bundle', 'delta', $value_field, $format_field]);
                $original_data_query->condition('entity_id', $nid, '=');
                $original_data_query->condition('delta', $i);
                $original_data_query->orderBy('revision_date', 'DESC');
                $original_data_query->range(0,1);
                $result = $original_data_query->execute()->fetchAll();
                if ($result) {
                    $original_data[] = reset($result);
                }
            }
        }

        if (!$original_data) {
            foreach($new_data as $item) {
                $insert_query = $this->database->insert($revision_table_name);
                $insert_query->fields([
                    'entity_id' => $nid,
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
                    $new->$value_field != $original->$value_field ||
                    $new->$format_field != $original->$format_field
                ) {
                    $insert_query = $this->database->insert($revision_table_name);
                    $insert_query->fields([
                        'entity_id' => $nid,
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
            $count_diff = count($original_data) - count($new_data);
            $original_max = count($original_data) - 1;
            $new_max = count($original_data) - 1 - $count_diff;
            
            // Mark deleted deltas as deleted
            for ($i = $original_max; $i > $new_max; $i--) {
                $original = $original_data[$i];
                $insert_query = $this->database->insert($revision_table_name);
                $insert_query->fields([
                    'entity_id' => $nid,
                    'bundle' => $original->bundle,
                    'delta' => $i,
                    'deleted' => 1,
                    $value_field => '',
                    $format_field => '',
                    'revision_date' => $saved_timestamp,
                ]);
                $insert_query->execute();
            }

            // Update remaining deltas
            for ($i = 0; $i <= $new_max; $i++) {
                $new = $new_data[$i];
                $original = $original_data[$i];
                if (
                    $new->$value_field != $original->$value_field ||
                    $new->$format_field != $original->$format_field
                ) {
                    $insert_query = $this->database->insert($revision_table_name);
                    $insert_query->fields([
                        'entity_id' => $nid,
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
        } elseif (count($new_data) > count($original_data)) {
            $count_diff = count($new_data) - count($original_data);
            $new_max = count($new_data) - 1;
            $original_max = count($new_data) - 1 - $count_diff;

            // Adding new fields
            for ($i = $original_max + 1; $i <= $new_max; $i++) {
                $new = $new_data[$i];
                $insert_query = $this->database->insert($revision_table_name);
                $insert_query->fields([
                    'entity_id' => $nid,
                    'bundle' => $new->bundle,
                    'delta' => $new->delta,
                    'deleted' => 0,
                    $value_field => $new->$value_field,
                    $format_field => $new->$format_field,
                    'revision_date' => $saved_timestamp,
                ]);
                $insert_query->execute();
            }

            // Checking for updates on original fields
            for ($i = 0; $i <= $original_max; $i++) {
                $new = $new_data[$i];
                $original = $original_data[$i];
                if (
                    $new->$value_field != $original->$value_field ||
                    $new->$format_field != $original->$format_field
                ) {
                    $insert_query = $this->database->insert($revision_table_name);
                    $insert_query->fields([
                        'entity_id' => $nid,
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
        }
    }

    private function checkTextSummary($nid, $field_name, $revision_table_name, $original_deltas, $saved_timestamp) {
        $value_field = $field_name . '_value';
        $format_field = $field_name . '_format';
        $summary_field = $field_name . '_summary';

        $new_data_query = $this->database->select('node__' . $field_name, 'ts');
        $new_data_query->fields('ts', ['entity_id', 'bundle', 'delta', $value_field, $format_field, $summary_field]);
        $new_data_query->condition('entity_id', $nid, '=');
        $new_data = $new_data_query->execute()->fetchAll(); 

        $original_data = [];
        if ($original_deltas) {
            for ($i = 0; $i < $original_deltas; $i++) {
                $original_data_query = $this->database->select($revision_table_name, 'ts');
                $original_data_query->fields('ts', ['entity_id', 'bundle','delta', $value_field, $format_field, $summary_field]);
                $original_data_query->condition('entity_id', $nid, '=');
                $original_data_query->condition('delta', $i);
                $original_data_query->orderBy('revision_date', 'DESC');
                $original_data_query->range(0,1);
                $result = $original_data_query->execute()->fetchAll();
                if ($result) {
                    $original_data[] = reset($result);
                }
            }
        }

        if (!$original_data) {
            foreach($new_data as $item) {
                $insert_query = $this->database->insert($revision_table_name);
                $insert_query->fields([
                    'entity_id' => $nid,
                    'bundle' => $item->bundle,
                    'delta' => $item->delta,
                    'deleted' => 0,
                    $value_field => $item->$value_field,
                    $format_field => $item->$format_field,
                    $summary_field => $item->$summary_field,
                    'revision_date' => $saved_timestamp,
                ]);
                $insert_query->execute();
            }   
        } elseif (count($new_data) == count($original_data)) {
            for ($i = 0; $i < count($new_data); $i++) {
                $new = $new_data[$i];
                $original = $original_data[$i];
                if (
                    $new->$value_field != $original->$value_field ||
                    $new->$format_field != $original->$format_field ||
                    $new->$summary_field != $original->$summary_field
                ) {
                    $insert_query = $this->database->insert($revision_table_name);
                    $insert_query->fields([
                        'entity_id' => $nid,
                        'bundle' => $new->bundle,
                        'delta' => $new->delta,
                        'deleted' => 0,
                        $value_field => $new->$value_field,
                        $format_field => $new->$format_field,
                        $summary_field => $new->$summary_field,
                        'revision_date' => $saved_timestamp,
                    ]);
                    $insert_query->execute();
                }
            }
        } elseif (count($new_data) < count($original_data)) {           
            $count_diff = count($original_data) - count($new_data);
            $original_max = count($original_data) - 1;
            $new_max = count($original_data) - 1 - $count_diff;
            
            // Mark deleted deltas as deleted
            for ($i = $original_max; $i > $new_max; $i--) {
                $original = $original_data[$i];
                $insert_query = $this->database->insert($revision_table_name);
                $insert_query->fields([
                    'entity_id' => $nid,
                    'bundle' => $original->bundle,
                    'delta' => $i,
                    'deleted' => 1,
                    $value_field => '',
                    $format_field => '',
                    $summary_field => '',
                    'revision_date' => $saved_timestamp,
                ]);
                $insert_query->execute();
            }

            // Update remaining deltas
            for ($i = 0; $i <= $new_max; $i++) {
                $new = $new_data[$i];
                $original = $original_data[$i];
                if (
                    $new->$value_field != $original->$value_field ||
                    $new->$format_field != $original->$format_field ||
                    $new->$summary_field != $original->$summary_field
                ) {
                    $insert_query = $this->database->insert($revision_table_name);
                    $insert_query->fields([
                        'entity_id' => $nid,
                        'bundle' => $new->bundle,
                        'delta' => $new->delta,
                        'deleted' => 0,
                        $value_field => $new->$value_field,
                        $format_field => $new->$format_field,
                        $summary_field => $new->$summary_field,
                        'revision_date' => $saved_timestamp,
                    ]);
                    $insert_query->execute();
                }
            }
        } elseif (count($new_data) > count($original_data)) {
            $count_diff = count($new_data) - count($original_data);
            $new_max = count($new_data) - 1;
            $original_max = count($new_data) - 1 - $count_diff;

            // Adding new fields
            for ($i = $original_max + 1; $i <= $new_max; $i++) {
                $new = $new_data[$i];
                $insert_query = $this->database->insert($revision_table_name);
                $insert_query->fields([
                    'entity_id' => $nid,
                    'bundle' => $new->bundle,
                    'delta' => $new->delta,
                    'deleted' => 0,
                    $value_field => $new->$value_field,
                    $format_field => $new->$format_field,
                    $summary_field => $new->$summary_field,
                    'revision_date' => $saved_timestamp,
                ]);
                $insert_query->execute();
            }

            // Checking for updates on original fields
            for ($i = 0; $i <= $original_max; $i++) {
                $new = $new_data[$i];
                $original = $original_data[$i];
                if (
                    $new->$value_field != $original->$value_field ||
                    $new->$format_field != $original->$format_field ||
                    $new->$summary_field != $original->$summary_field
                ) {
                    $insert_query = $this->database->insert($revision_table_name);
                    $insert_query->fields([
                        'entity_id' => $nid,
                        'bundle' => $new->bundle,
                        'delta' => $new->delta,
                        'deleted' => 0,
                        $value_field => $new->$value_field,
                        $format_field => $new->$format_field,
                        $summary_field => $new->$summary_field,
                        'revision_date' => $saved_timestamp,
                    ]);
                    $insert_query->execute();
                }
            }
        }
    }

    private function checkDateTime($nid, $field_name, $revision_table_name, $original_deltas, $saved_timestamp) {
        $value_field = $field_name . '_value';

        $new_data_query = $this->database->select('node__' . $field_name, 'dt');
        $new_data_query->fields('dt', ['entity_id', 'bundle', 'delta', $value_field]);
        $new_data_query->condition('entity_id', $nid, '=');
        $new_data = $new_data_query->execute()->fetchAll(); 

        $original_data = [];
        if ($original_deltas) {
            for ($i = 0; $i < $original_deltas; $i++) {
                $original_data_query = $this->database->select($revision_table_name, 'dt');
                $original_data_query->fields('dt', ['entity_id', 'bundle', 'delta', $value_field]);
                $original_data_query->condition('entity_id', $nid, '=');
                $original_data_query->condition('delta', $i);
                $original_data_query->orderBy('revision_date', 'DESC');
                $original_data_query->range(0,1);
                $result = $original_data_query->execute()->fetchAll();
                if ($result) {
                    $original_data[] = reset($result);
                }
            }
        }

        if (!$original_data) {
            foreach($new_data as $item) {
                $insert_query = $this->database->insert($revision_table_name);
                $insert_query->fields([
                    'entity_id' => $nid,
                    'bundle' => $item->bundle,
                    'delta' => $item->delta,
                    'deleted' => 0,
                    $value_field => $item->$value_field,
                    'revision_date' => $saved_timestamp,
                ]);
                $insert_query->execute();
            }   
        } elseif (count($new_data) == count($original_data)) {
            for ($i = 0; $i < count($new_data); $i++) {
                $new = $new_data[$i];
                $original = $original_data[$i];
                if ($new->$value_field != $original->$value_field) {
                    $insert_query = $this->database->insert($revision_table_name);
                    $insert_query->fields([
                        'entity_id' => $nid,
                        'bundle' => $new->bundle,
                        'delta' => $new->delta,
                        'deleted' => 0,
                        $value_field => $new->$value_field,
                        'revision_date' => $saved_timestamp,
                    ]);
                    $insert_query->execute();
                }
            }
        } elseif (count($new_data) < count($original_data)) {           
            $count_diff = count($original_data) - count($new_data);
            $original_max = count($original_data) - 1;
            $new_max = count($original_data) - 1 - $count_diff;
            
            // Mark deleted deltas as deleted
            for ($i = $original_max; $i > $new_max; $i--) {
                $original = $original_data[$i];
                $insert_query = $this->database->insert($revision_table_name);
                $insert_query->fields([
                    'entity_id' => $nid,
                    'bundle' => $original->bundle,
                    'delta' => $i,
                    'deleted' => 1,
                    $value_field => '',
                    'revision_date' => $saved_timestamp,
                ]);
                $insert_query->execute();
            }

            // Update remaining deltas
            for ($i = 0; $i <= $new_max; $i++) {
                $new = $new_data[$i];
                $original = $original_data[$i];
                if ($new->$value_field != $original->$value_field) {
                    $insert_query = $this->database->insert($revision_table_name);
                    $insert_query->fields([
                        'entity_id' => $nid,
                        'bundle' => $new->bundle,
                        'delta' => $new->delta,
                        'deleted' => 0,
                        $value_field => $new->$value_field,
                        'revision_date' => $saved_timestamp,
                    ]);
                    $insert_query->execute();
                }
            }
        } elseif (count($new_data) > count($original_data)) {
            $count_diff = count($new_data) - count($original_data);
            $new_max = count($new_data) - 1;
            $original_max = count($new_data) - 1 - $count_diff;

            // Adding new fields
            for ($i = $original_max + 1; $i <= $new_max; $i++) {
                $new = $new_data[$i];
                $insert_query = $this->database->insert($revision_table_name);
                $insert_query->fields([
                    'entity_id' => $nid,
                    'bundle' => $new->bundle,
                    'delta' => $new->delta,
                    'deleted' => 0,
                    $value_field => $new->$value_field,
                    'revision_date' => $saved_timestamp,
                ]);
                $insert_query->execute();
            }

            // Checking for updates on original fields
            for ($i = 0; $i <= $original_max; $i++) {
                $new = $new_data[$i];
                $original = $original_data[$i];
                if ($new->$value_field != $original->$value_field) {
                    $insert_query = $this->database->insert($revision_table_name);
                    $insert_query->fields([
                        'entity_id' => $nid,
                        'bundle' => $new->bundle,
                        'delta' => $new->delta,
                        'deleted' => 0,
                        $value_field => $new->$value_field,
                        'revision_date' => $saved_timestamp,
                    ]);
                    $insert_query->execute();
                }
            }
        }
    }

    private function checkBasicImage($nid, $field_name, $revision_table_name, $original_deltas, $saved_timestamp) {
        $target_id = $field_name . '_target_id';
        $alt_text = $field_name . '_alt';
        $title = $field_name . '_title';
        $width = $field_name . '_width';
        $height = $field_name . '_height';

        $new_data_query = $this->database->select('node__' . $field_name, 'bi');
        $new_data_query->fields('bi', ['entity_id', 'bundle', 'delta', $target_id, $alt_text, $title, $width, $height]);
        $new_data_query->condition('entity_id', $nid, '=');
        $new_data = $new_data_query->execute()->fetchAll(); 

        $original_data = [];
        if ($original_deltas) {
            for ($i = 0; $i < $original_deltas; $i++) {
                $original_data_query = $this->database->select($revision_table_name, 'bi');
                $original_data_query->fields('bi', ['entity_id', 'bundle', 'delta', $target_id, $alt_text, $title, $width, $height]);
                $original_data_query->condition('entity_id', $nid, '=');
                $original_data_query->condition('delta', $i);
                $original_data_query->orderBy('revision_date', 'DESC');
                $original_data_query->range(0,1);
                $result = $original_data_query->execute()->fetchAll();
                if ($result) {
                    $original_data[] = reset($result);
                }
            }
        }

        if (!$original_data) {
            foreach($new_data as $item) {
                $insert_query = $this->database->insert($revision_table_name);
                $insert_query->fields([
                    'entity_id' => $nid,
                    'bundle' => $item->bundle,
                    'delta' => $item->delta,
                    'deleted' => 0,
                    $target_id => $item->$target_id,
                    $alt_text => $item->$alt_text,
                    $title => $item->$title,
                    $width => $item->$width,
                    $height => $item->$height,
                    'revision_date' => $saved_timestamp,
                ]);
                $insert_query->execute();
            }   
        } elseif (count($new_data) == count($original_data)) {
            for ($i = 0; $i < count($new_data); $i++) {
                $new = $new_data[$i];
                $original = $original_data[$i];
                if (
                    $new->$target_id != $original->$target_id ||
                    $new->$alt_text != $original->$alt_text ||
                    $new->$title != $original->$title ||
                    $new->$width != $original->$width ||
                    $new->$height != $original->$height
                ) {
                    $insert_query = $this->database->insert($revision_table_name);
                    $insert_query->fields([
                        'entity_id' => $nid,
                        'bundle' => $new->bundle,
                        'delta' => $new->delta,
                        'deleted' => 0,
                        $target_id => $new->$target_id,
                        $alt_text => $new->$alt_text,
                        $title => $new->$title,
                        $width => $new->$width,
                        $height => $new->$height,
                        'revision_date' => $saved_timestamp,
                    ]);
                    $insert_query->execute();
                }
            }
        } elseif (count($new_data) < count($original_data)) {           
            $count_diff = count($original_data) - count($new_data);
            $original_max = count($original_data) - 1;
            $new_max = count($original_data) - 1 - $count_diff;
            
            // Mark deleted deltas as deleted
            for ($i = $original_max; $i > $new_max; $i--) {
                $original = $original_data[$i];
                $insert_query = $this->database->insert($revision_table_name);
                $insert_query->fields([
                    'entity_id' => $nid,
                    'bundle' => $original->bundle,
                    'delta' => $i,
                    'deleted' => 1,
                    $target_id => '',
                    $alt_text => '',
                    $title => '',
                    $width => '',
                    $height => '',
                    'revision_date' => $saved_timestamp,
                ]);
                $insert_query->execute();
            }

            // Update remaining deltas
            for ($i = 0; $i <= $new_max; $i++) {
                $new = $new_data[$i];
                $original = $original_data[$i];
                if (
                    $new->$target_id != $original->$target_id ||
                    $new->$alt_text != $original->$alt_text ||
                    $new->$title != $original->$title ||
                    $new->$width != $original->$width ||
                    $new->$height != $original->$height
                ) {
                    $insert_query = $this->database->insert($revision_table_name);
                    $insert_query->fields([
                        'entity_id' => $nid,
                        'bundle' => $new->bundle,
                        'delta' => $new->delta,
                        'deleted' => 0,
                        $target_id => $new->$target_id,
                        $alt_text => $new->$alt_text,
                        $title => $new->$title,
                        $width => $new->$width,
                        $height => $new->$height,
                        'revision_date' => $saved_timestamp,
                    ]);
                    $insert_query->execute();
                }
            }
        } elseif (count($new_data) > count($original_data)) {
            $count_diff = count($new_data) - count($original_data);
            $new_max = count($new_data) - 1;
            $original_max = count($new_data) - 1 - $count_diff;

            // Adding new fields
            for ($i = $original_max + 1; $i <= $new_max; $i++) {
                $new = $new_data[$i];
                $insert_query = $this->database->insert($revision_table_name);
                $insert_query->fields([
                    'entity_id' => $nid,
                    'bundle' => $new->bundle,
                    'delta' => $new->delta,
                    'deleted' => 0,
                    $target_id => $new->$target_id,
                    $alt_text => $new->$alt_text,
                    $title => $new->$title,
                    $width => $new->$width,
                    $height => $new->$height,
                    'revision_date' => $saved_timestamp,
                ]);
                $insert_query->execute();
            }

            // Checking for updates on original fields
            for ($i = 0; $i <= $original_max; $i++) {
                $new = $new_data[$i];
                $original = $original_data[$i];
                if (
                    $new->$target_id != $original->$target_id ||
                    $new->$alt_text != $original->$alt_text ||
                    $new->$title != $original->$title ||
                    $new->$width != $original->$width ||
                    $new->$height != $original->$height
                ) {
                    $insert_query = $this->database->insert($revision_table_name);
                    $insert_query->fields([
                        'entity_id' => $nid,
                        'bundle' => $new->bundle,
                        'delta' => $new->delta,
                        'deleted' => 0,
                        $target_id => $new->$target_id,
                        $alt_text => $new->$alt_text,
                        $title => $new->$title,
                        $width => $new->$width,
                        $height => $new->$height,
                        'revision_date' => $saved_timestamp,
                    ]);
                    $insert_query->execute();
                }
            }
        }
    }

    private function checkEntityReference($nid, $field_name, $revision_table_name, $original_deltas, $saved_timestamp) {
        $target_id = $field_name . '_target_id';

        $new_data_query = $this->database->select('node__' . $field_name, 'er');
        $new_data_query->fields('er', ['entity_id', 'bundle', 'delta', $target_id]);
        $new_data_query->condition('entity_id', $nid, '=');
        $new_data = $new_data_query->execute()->fetchAll(); 

        $original_data = [];
        if ($original_deltas) {
            for ($i = 0; $i < $original_deltas; $i++) {
                $original_data_query = $this->database->select($revision_table_name, 'er');
                $original_data_query->fields('er', ['entity_id', 'bundle', 'delta', $target_id]);
                $original_data_query->condition('entity_id', $nid, '=');
                $original_data_query->condition('delta', $i);
                $original_data_query->orderBy('revision_date', 'DESC');
                $original_data_query->range(0,1);
                $result = $original_data_query->execute()->fetchAll();
                if ($result) {
                    $original_data[] = reset($result);
                }
            }
        }

        if (!$original_data) {
            foreach($new_data as $item) {
                $insert_query = $this->database->insert($revision_table_name);
                $insert_query->fields([
                    'entity_id' => $nid,
                    'bundle' => $item->bundle,
                    'delta' => $item->delta,
                    'deleted' => 0,
                    $target_id => $item->$target_id,
                    'revision_date' => $saved_timestamp,
                ]);
                $insert_query->execute();
            }   
        } elseif (count($new_data) == count($original_data)) {
            for ($i = 0; $i < count($new_data); $i++) {
                $new = $new_data[$i];
                $original = $original_data[$i];
                if ($new->$target_id != $original->$target_id) {
                    $insert_query = $this->database->insert($revision_table_name);
                    $insert_query->fields([
                        'entity_id' => $nid,
                        'bundle' => $new->bundle,
                        'delta' => $new->delta,
                        'deleted' => 0,
                        $target_id => $new->$target_id,
                        'revision_date' => $saved_timestamp,
                    ]);
                    $insert_query->execute();
                }
            }
        } elseif (count($new_data) < count($original_data)) {           
            $count_diff = count($original_data) - count($new_data);
            $original_max = count($original_data) - 1;
            $new_max = count($original_data) - 1 - $count_diff;
            
            // Mark deleted deltas as deleted
            for ($i = $original_max; $i > $new_max; $i--) {
                $original = $original_data[$i];
                $insert_query = $this->database->insert($revision_table_name);
                $insert_query->fields([
                    'entity_id' => $nid,
                    'bundle' => $original->bundle,
                    'delta' => $i,
                    'deleted' => 1,
                    $target_id => '',
                    'revision_date' => $saved_timestamp,
                ]);
                $insert_query->execute();
            }

            // Update remaining deltas
            for ($i = 0; $i <= $new_max; $i++) {
                $new = $new_data[$i];
                $original = $original_data[$i];
                if ($new->$target_id != $original->$target_id) {
                    $insert_query = $this->database->insert($revision_table_name);
                    $insert_query->fields([
                        'entity_id' => $nid,
                        'bundle' => $new->bundle,
                        'delta' => $new->delta,
                        'deleted' => 0,
                        $target_id => $new->$target_id,
                        'revision_date' => $saved_timestamp,
                    ]);
                    $insert_query->execute();
                }
            }
        } elseif (count($new_data) > count($original_data)) {
            $count_diff = count($new_data) - count($original_data);
            $new_max = count($new_data) - 1;
            $original_max = count($new_data) - 1 - $count_diff;

            // Adding new fields
            for ($i = $original_max + 1; $i <= $new_max; $i++) {
                $new = $new_data[$i];
                $insert_query = $this->database->insert($revision_table_name);
                $insert_query->fields([
                    'entity_id' => $nid,
                    'bundle' => $new->bundle,
                    'delta' => $new->delta,
                    'deleted' => 0,
                    $target_id => $new->$target_id,
                    'revision_date' => $saved_timestamp,
                ]);
                $insert_query->execute();
            }

            // Checking for updates on original fields
            for ($i = 0; $i <= $original_max; $i++) {
                $new = $new_data[$i];
                $original = $original_data[$i];
                if ($new->$target_id != $original->$target_id) {
                    $insert_query = $this->database->insert($revision_table_name);
                    $insert_query->fields([
                        'entity_id' => $nid,
                        'bundle' => $new->bundle,
                        'delta' => $new->delta,
                        'deleted' => 0,
                        $target_id => $new->$target_id,
                        'revision_date' => $saved_timestamp,
                    ]);
                    $insert_query->execute();
                }
            }
        }
    }

}