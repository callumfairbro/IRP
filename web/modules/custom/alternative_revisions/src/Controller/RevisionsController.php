<?php

namespace Drupal\alternative_revisions\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldConfig;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

class RevisionsController extends ControllerBase implements ContainerInjectionInterface {

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

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container) {
        $instance = parent::create($container);
        $instance->database = $container->get('database');
        $instance->entityTypeManager = $container->get('entity_type.manager');
        $instance->configFactory = $container->get('config.factory');
        return $instance;
    }

    public function viewRevisions($nid) {
        $build = [];
        $data = [];
        $node = Node::load($nid);
        if (!$node) {
            return $this->redirect('<front>');
        }
        $field_definitions = $node->getFieldDefinitions();
        $schema = $this->database->schema();
        foreach ($field_definitions as $key => $definition) {
            $field_type = $definition->getType();
            $revision_table_name = 'node_alt_revision__' . $key;
            if (str_starts_with($key, 'field_') && $schema->tableExists($revision_table_name)) {
                $date_query = $this->database->select($revision_table_name, 'ar');
                $date_query->condition('entity_id', $nid, '=');
                $date_query->fields('ar', ['revision_date', 'delta']);
                $date_results = $date_query->execute()->fetchAll();
                if ($date_results) {
                    $columns = $this->database->query("DESCRIBE {" . $revision_table_name . "}")->fetchCol();
                    foreach ($date_results as $date_result) {
                        $revision_date = $date_result->revision_date;
                        $delta = $date_result->delta;

                        $compare_query = $this->database->select($revision_table_name, 'ar');
                        $compare_query->fields('ar', $columns);
                        $compare_query->orderBy('revision_date', 'DESC');
                        $compare_query->condition('entity_id', $nid, '=');
                        $compare_query->condition('revision_date', $revision_date, '<=');
                        $compare_query->condition('delta', $delta, '=');
                        $compare_query->range(0,2);
                        $comparison_results = $compare_query->execute()->fetchAll();
                        
                        if (count($comparison_results) == 1 || $comparison_results[1]->deleted == 1) {
                            $type = 'New';
                        } elseif($comparison_results[0]->deleted == 1) {
                            $type = 'Deleted';
                        } else {
                            $type = 'Changed';
                        }

                        $changes = [];
                        switch ($field_type) {
                            case 'text_with_summary':
                                $changes = $this->getTextSummaryChanges($key, $comparison_results);
                                break; 
                            case 'text_long':
                                $changes = $this->getTextLongChanges($key, $comparison_results);
                                break;
                            case 'image':
                                $changes = $this->getBasicImageChanges($key, $comparison_results);
                                break;
                            case 'datetime':
                                $changes = $this->getDateTimeChanges($key, $comparison_results);
                                break;
                            case 'entity_reference':
                                $changes = $this->getEntityReferenceChanges($key, $comparison_results);
                                break;
                        }     

                        $data[$revision_date][$key][$delta] = [$type, $changes];
                    }
                }
            }
        }

        if ($schema->tableExists('node_alt_revision_field_data')) {
            $data_date_query = $this->database->select('node_alt_revision_field_data', 'fd');
            $data_date_query->condition('nid', $nid, '=');
            $data_date_query->fields('fd', ['revision_date']);
            $data_dates = $data_date_query->execute()->fetchAll();
            if ($data_dates) {
                foreach ($data_dates as $date_result) {
                    $revision_date = $date_result->revision_date;
                    $data_compare_query = $this->database->select('node_alt_revision_field_data', 'fd');
                    $data_compare_query->fields('fd', ['title', 'type', 'status', 'deleted', 'created', 'revision_date']);
                    $data_compare_query->orderBy('revision_date', 'DESC');
                    $data_compare_query->condition('nid', $nid, '=');
                    $data_compare_query->condition('revision_date', $revision_date, '<=');
                    $data_compare_query->range(0,2);
                    $data_compare_results = $data_compare_query->execute()->fetchAll();

                    $changes = [];
                    if (count($data_compare_results) == 1) {
                        $type = 'New';
                        $changes['title'] = ['', $data_compare_results[0]->title];
                        $changes['type'] = ['', $data_compare_results[0]->type];
                        $changes['status'] = ['', $data_compare_results[0]->status];
                        $changes['created'] = ['', $data_compare_results[0]->created];
                    } else {
                        if ($data_compare_results[0]->deleted) {
                            $type = 'Deleted';
                        } else {
                            $type = 'Changed';
                        }
                        if ($data_compare_results[0]->title != $data_compare_results[1]->title) {
                            $changes['title'] = [$data_compare_results[1]->title, $data_compare_results[0]->title];
                        }
                        if ($data_compare_results[0]->type != $data_compare_results[1]->type) {
                            $changes['type'] = [$data_compare_results[1]->type, $data_compare_results[0]->type];
                        }
                        if ($data_compare_results[0]->status != $data_compare_results[1]->status) {
                            $changes['status'] = [$data_compare_results[1]->status, $data_compare_results[0]->status];
                        }
                        if ($data_compare_results[0]->created != $data_compare_results[1]->created) {
                            $changes['created'] = [$data_compare_results[1]->created, $data_compare_results[0]->created];
                        }
                    }
                    $data[$revision_date]['data'][0] = [$type, $changes];
                }
            }
        }

        krsort($data);
        $build['#theme'] = 'alternative_revisions_view_revisions';
        $build['#headers'] = ['Date', 'Field name', 'Delta', 'Type of change', 'Field', 'Previous', 'New', 'View'];
        $build['#nid'] = $nid;
        $build['#data'] = $data;
        return $build;
    }

    public function viewRevision($nid, $timestamp) {

        $node = Node::load($nid);
        if (!$node) {
            return $this->redirect('<front>');
        }
        $field_definitions = $node->getFieldDefinitions();
        $schema = $this->database->schema();
        $data = [];
        
        $revision_node_data = $this->getNodeData($nid, $timestamp);
        $current_node_data = $this->getNodeData($nid);
        
        $data['node_data']['revision'][] = $revision_node_data;
        $data['node_data']['current'][] = $current_node_data;
        $data['node_data']['data_rows'] = 5;

        foreach ($field_definitions as $key => $definition) {
            $field_type = $definition->getType();
            $revision_table_name = 'node_alt_revision__' . $key;
            if (str_starts_with($key, 'field_') && $schema->tableExists($revision_table_name)) {
                switch ($field_type) {
                    case 'text_with_summary':
                        $revision_data = $this->getTextSummaryData($nid, $key, $timestamp);
                        $current_data = $this->getTextSummaryData($nid, $key);
                        $data_rows = 3;
                        break; 
                    case 'text_long':
                        $revision_data = $this->getTextLongData($nid, $key, $timestamp);
                        $current_data = $this->getTextLongData($nid, $key);
                        $data_rows = 2;
                        break;
                    case 'image':
                        $revision_data = $this->getBasicImageData($nid, $key, $timestamp);
                        $current_data = $this->getBasicImageData($nid, $key);
                        $data_rows = 5;
                        break;
                    case 'datetime':
                        $revision_data = $this->getDateTimeData($nid, $key, $timestamp);
                        $current_data = $this->getDateTimeData($nid, $key);
                        $data_rows = 1;
                        break;
                    case 'entity_reference':
                        $revision_data = $this->getEntityReferenceData($nid, $key, $timestamp);
                        $current_data = $this->getEntityReferenceData($nid, $key);
                        $data_rows = 1;
                        break;
                }     
                $data[$key]['revision'] = $revision_data;
                $data[$key]['current'] = $current_data;
                $data[$key]['data_rows'] = $data_rows;
            }            
        }

        $build['#headers'] = ['Field', 'Delta', 'Data', 'Current content', 'Revision Content'];
        $build['#data'] = $data;
        $build['#timestamp'] = $timestamp;
        $build['#nid'] = $node->id();
        $build['#theme'] = 'alternative_revisions_view_revision';
        return $build;

    }

    public function restoreRevision($nid, $timestamp) {
        $node = Node::load($nid);
        if (!$node) {
            return $this->redirect('<front>');
        }
        $field_definitions = $node->getFieldDefinitions();
        $schema = $this->database->schema();

        $node_data = $this->getNodeData($nid, $timestamp);
        foreach ($node_data as $key => $value) {
            $node->set($key, $value);
        }

        foreach ($field_definitions as $key => $definition) {
            $revision_table_name = 'node_alt_revision__' . $key;
            if (str_starts_with($key, 'field_') && $schema->tableExists($revision_table_name)) {
                $field_type = $definition->getType();
                switch ($field_type) {
                    case 'text_with_summary':
                        $revision_data = $this->getTextSummaryData($nid, $key, $timestamp);
                        $node->set($key, $revision_data);
                        break; 
                    case 'text_long':
                        $revision_data = $this->getTextLongData($nid, $key, $timestamp);
                        $node->set($key, $revision_data);
                        break;
                    case 'image':
                        $revision_data = $this->getBasicImageData($nid, $key, $timestamp);
                        $node->set($key, $revision_data);
                        break;
                    case 'datetime':
                        $revision_data = $this->getDateTimeData($nid, $key, $timestamp);
                        $node->set($key, $revision_data);
                        break;
                    case 'entity_reference':
                        $revision_data = $this->getEntityReferenceData($nid, $key, $timestamp);
                        $node->set($key, $revision_data);
                        break;
                }
            }
        }
        $node->save();
 
        // Redirect to previous page
        $current_path = \Drupal::service('path.current')->getPath();
        $current_url = Url::fromUri('internal:' . $current_path);
        $referer = \Drupal::request()->headers->get('referer');
        $messenger = \Drupal::service('messenger');
        $messenger->addMessage('Revision restored!');
        if (!empty($referer) && $referer != $current_url->toString()) {
            return new RedirectResponse($referer);
        } else {
            return $this->redirect('<front>');
        }
    }

    public function viewDeletions() {
        $deleted_nodes_query = $this->database->select('node_alt_revision_field_data', 'fd');
        $deleted_nodes_query->condition('deleted', 1);
        $deleted_nodes_query->fields('fd', ['nid', 'type', 'title', 'created', 'revision_date']);
        $deleted_nodes_data = $deleted_nodes_query->execute()->fetchAll();

        $build['#headers'] = ['NID', 'Title', 'Type', 'Created', 'Deleted', 'View'];
        $build['#data'] = $deleted_nodes_data;
        $build['#theme'] = 'alternative_revisions_view_deletions';
        return $build;

    }

    public function viewDeletion($nid) {

        // Check node is deleted
        $deleted_check_query = $this->database->select('node_alt_revision_field_data', 'fd');
        $deleted_check_query->fields('fd', ['nid']);
        $deleted_check_query->condition('nid', $nid, '=');
        $deleted_check_query->condition('deleted', 1, '=');
        $result = $deleted_check_query->execute()->fetchAll();
        if(!$result) {
            $messenger = \Drupal::service('messenger');
            $messenger->addMessage('Content is not deleted!');
            return $this->redirect('<front>');
        }

        $data = [];
        $deleted_node_data = $this->getNodeData($nid);
        $data['node_data']['deleted'][] = $deleted_node_data;
        $data['node_data']['data_rows'] = 5;
        
        $content_type = $data['node_data']['deleted'][0]['type'];
        if (!$content_type) {
            return $this->redirect('<front>');
        }
        $field_definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', $content_type);
        $schema = $this->database->schema();
        foreach ($field_definitions as $key => $definition) {
            $field_type = $definition->getType();
            $revision_table_name = 'node_alt_revision__' . $key;
            if (str_starts_with($key, 'field_') && $schema->tableExists($revision_table_name)) {
                switch ($field_type) {
                    case 'text_with_summary':
                        $deleted_data = $this->getTextSummaryData($nid, $key);
                        $data_rows = 3;
                        break; 
                    case 'text_long':
                        $deleted_data = $this->getTextLongData($nid, $key);
                        $data_rows = 2;
                        break;
                    case 'image':
                        $deleted_data = $this->getBasicImageData($nid, $key);
                        $data_rows = 5;
                        break;
                    case 'datetime':
                        $deleted_data = $this->getDateTimeData($nid, $key);
                        $data_rows = 1;
                        break;
                    case 'entity_reference':
                        $deleted_data = $this->getEntityReferenceData($nid, $key);
                        $data_rows = 1;
                        break;
                }     
                $data[$key]['deleted'] = $deleted_data;
                $data[$key]['data_rows'] = $data_rows;
            }            
        }
        
        $build['#headers'] = ['Field', 'Delta', 'Data', 'Deleted content'];
        $build['#data'] = $data;
        $build['#nid'] = $nid;
        $build['#theme'] = 'alternative_revisions_view_deletion';
        return $build;
    }

    public function restoreDeletion($nid) {

        // Check node is deleted
        $deleted_check_query = $this->database->select('node_alt_revision_field_data', 'fd');
        $deleted_check_query->fields('fd', ['nid']);
        $deleted_check_query->condition('nid', $nid, '=');
        $deleted_check_query->condition('deleted', 1, '=');
        $result = $deleted_check_query->execute()->fetchAll();
        if(!$result) {
            $messenger = \Drupal::service('messenger');
            $messenger->addMessage('Content is not deleted!');
            return $this->redirect('<front>');
        }

        $deleted_node_data = $this->getNodeData($nid);
        $content_type = $deleted_node_data['type'];
        if (!$content_type) {
            return $this->redirect('<front>');
        }
        $node = Node::create(['type' => $content_type]);

        $node->set('nid', $nid);
        foreach ($deleted_node_data as $key => $value) {
            $node->set($key, $value);
        }

        $field_definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', $content_type);
        foreach ($field_definitions as $key => $definition) {
            $revision_table_name = 'node_alt_revision__' . $key;
            $schema = $this->database->schema();
            if (str_starts_with($key, 'field_') && $schema->tableExists($revision_table_name)) {
                $field_type = $definition->getType();
                switch ($field_type) {
                    case 'text_with_summary':
                        $revision_data = $this->getTextSummaryData($nid, $key);
                        $node->set($key, $revision_data);
                        break; 
                    case 'text_long':
                        $revision_data = $this->getTextLongData($nid, $key);
                        $node->set($key, $revision_data);
                        break;
                    case 'image':
                        $revision_data = $this->getBasicImageData($nid, $key);
                        $node->set($key, $revision_data);
                        break;
                    case 'datetime':
                        $revision_data = $this->getDateTimeData($nid, $key);
                        $node->set($key, $revision_data);
                        break;
                    case 'entity_reference':
                        $revision_data = $this->getEntityReferenceData($nid, $key);
                        $node->set($key, $revision_data);
                        break;
                }
            }
        }
        $node->save();

        $update_deleted_query = $this->database->update('node_alt_revision_field_data');
        $update_deleted_query->fields(['deleted' => 0]);
        $update_deleted_query->condition('nid', $nid, '=');
        $update_deleted_query->condition('deleted', 1, '=');
        $update_deleted_query->execute();

        // Redirect to previous page
        $current_path = \Drupal::service('path.current')->getPath();
        $current_url = Url::fromUri('internal:' . $current_path);
        $referer = \Drupal::request()->headers->get('referer');
        $messenger = \Drupal::service('messenger');
        $messenger->addMessage('Deleted content restored!');
        if (!empty($referer) && $referer != $current_url->toString()) {
            return new RedirectResponse($referer);
        } else {
            return $this->redirect('<front>');
        }
    }

    private function getNodeData($nid, $timestamp = NULL) {
        if (!$timestamp) { 
            $timestamp = time();
        }
        $node_data_query = $this->database->select('node_alt_revision_field_data', 'fd');
        $node_data_query->fields('fd', ['title', 'type', 'status', 'created']);
        $node_data_query->orderBy('revision_date', 'DESC');
        $node_data_query->condition('nid', $nid, '=');
        $node_data_query->condition('revision_date', $timestamp, '<=');
        $node_data_query->range(0,1);
        $node_data_query_result = reset($node_data_query->execute()->fetchAll());
        
        return (array) $node_data_query_result;
    }

    private function getTextSummaryData($nid, $field_name, $timestamp = NULL) {
        if (!$timestamp) { 
            $timestamp = time();
        }
        $columns = [$field_name . '_value', $field_name . '_format', $field_name . '_summary'];
        $revision_table_name = 'node_alt_revision__' . $field_name;

        $delta_query = $this->database->select($revision_table_name, 'rt');
        $delta_query->fields('rt', ['delta']);
        $delta_query->orderBy('delta', 'DESC');
        $delta_query->condition('entity_id', $nid, '=');
        $delta_query->range(0, 1);
        $deltas = reset($delta_query->execute()->fetchCol());
        
        $result = [];
        for($i = 0; $i <= $deltas; $i++) {
            $revision_query = $this->database->select($revision_table_name, 'rt');
            $revision_query->fields('rt', $columns);
            $revision_query->orderBy('revision_date', 'DESC');
            $revision_query->condition('delta', $i, '=');
            $revision_query->condition('entity_id', $nid, '=');
            $revision_query->condition('revision_date', $timestamp, '<=');
            $revision_query->range(0,1);
            $revision_query_result = reset($revision_query->execute()->fetchAll());
            $result_array = (array) $revision_query_result;
            foreach ($result_array as $key => $value) {
                if (str_starts_with($key, ($field_name . "_"))) {
                    $result_array = array_combine(
                        array_map(function($key) use ($field_name) {
                            return str_replace(($field_name . "_"), "", $key);
                        }, array_keys($result_array)),
                        $result_array
                    );
                }
            }
            $result[$i] = $result_array;
        }
        return $result;
    }

    private function getTextLongData($nid, $field_name, $timestamp = NULL) {
        if (!$timestamp) { 
            $timestamp = time();
        }
        $columns = [$field_name . '_value', $field_name . '_format'];
        $revision_table_name = 'node_alt_revision__' . $field_name;

        $delta_query = $this->database->select($revision_table_name, 'rt');
        $delta_query->fields('rt', ['delta']);
        $delta_query->orderBy('delta', 'DESC');
        $delta_query->condition('entity_id', $nid, '=');
        $delta_query->range(0, 1);
        $deltas = reset($delta_query->execute()->fetchCol());

        $result = [];
        for($i = 0; $i <= $deltas; $i++) {
            $revision_query = $this->database->select($revision_table_name, 'rt');
            $revision_query->fields('rt', $columns);
            $revision_query->orderBy('revision_date', 'DESC');
            $revision_query->condition('entity_id', $nid, '=');
            $revision_query->condition('delta', $i, '=');
            $revision_query->condition('revision_date', $timestamp, '<=');
            $revision_query->range(0,1);
            $revision_query_result = reset($revision_query->execute()->fetchAll());
            $result_array = (array) $revision_query_result;
            foreach ($result_array as $key => $value) {
                if (str_starts_with($key, ($field_name . "_"))) {
                    $result_array = array_combine(
                        array_map(function($key) use ($field_name) {
                            return str_replace(($field_name . "_"), "", $key);
                        }, array_keys($result_array)),
                        $result_array
                    );
                }
            }
            $result[$i] = $result_array;
        }
        return $result;
    }

    private function getBasicImageData($nid, $field_name, $timestamp = NULL) {
        if (!$timestamp) { 
            $timestamp = time();
        }
        $target_id = $field_name . '_target_id';
        $alt = $field_name . '_alt';
        $title = $field_name . '_title';
        $width = $field_name . '_width';
        $height = $field_name . '_height';
        $columns = [$target_id, $alt, $title, $width, $height];
        $revision_table_name = 'node_alt_revision__' . $field_name;

        $delta_query = $this->database->select($revision_table_name, 'rt');
        $delta_query->condition('entity_id', $nid, '=');
        $delta_query->fields('rt', ['delta']);
        $delta_query->orderBy('delta', 'DESC');
        $delta_query->range(0, 1);
        $deltas = reset($delta_query->execute()->fetchCol());
        
        $result = [];
        for($i = 0; $i <= $deltas; $i++) {
            $revision_query = $this->database->select($revision_table_name, 'rt');
            $revision_query->fields('rt', $columns);
            $revision_query->orderBy('revision_date', 'DESC');
            $revision_query->condition('entity_id', $nid, '=');
            $revision_query->condition('delta', $i, '=');
            $revision_query->condition('revision_date', $timestamp, '<=');
            $revision_query->range(0,1);
            $revision_query_result = reset($revision_query->execute()->fetchAll());
            $result_array = (array) $revision_query_result;
            foreach ($result_array as $key => $value) {
                if (str_starts_with($key, ($field_name . "_"))) {
                    $result_array = array_combine(
                        array_map(function($key) use ($field_name) {
                            return str_replace(($field_name . "_"), "", $key);
                        }, array_keys($result_array)),
                        $result_array
                    );
                }
            }
            $result[$i] = $result_array;
        }
        return $result;
    }

    private function getDateTimeData($nid, $field_name, $timestamp = NULL) {
        if (!$timestamp) { 
            $timestamp = time();
        }
        $columns = [$field_name . '_value'];
        $revision_table_name = 'node_alt_revision__' . $field_name;

        $delta_query = $this->database->select($revision_table_name, 'rt');
        $delta_query->fields('rt', ['delta']);
        $delta_query->orderBy('delta', 'DESC');
        $delta_query->condition('entity_id', $nid, '=');
        $delta_query->range(0, 1);
        $deltas = reset($delta_query->execute()->fetchCol());
        
        $result = [];
        for($i = 0; $i <= $deltas; $i++) {
            $revision_query = $this->database->select($revision_table_name, 'rt');
            $revision_query->fields('rt', $columns);
            $revision_query->orderBy('revision_date', 'DESC');
            $revision_query->condition('entity_id', $nid, '=');
            $revision_query->condition('delta', $i, '=');
            $revision_query->condition('revision_date', $timestamp, '<=');
            $revision_query->range(0,1);
            $revision_query_result = reset($revision_query->execute()->fetchAll());
            $result_array = (array) $revision_query_result;
            foreach ($result_array as $key => $value) {
                if (str_starts_with($key, ($field_name . "_"))) {
                    $result_array = array_combine(
                        array_map(function($key) use ($field_name) {
                            return str_replace(($field_name . "_"), "", $key);
                        }, array_keys($result_array)),
                        $result_array
                    );
                }
            }
            $result[$i] = $result_array;
        }
        return $result;
    }

    private function getEntityReferenceData($nid, $field_name, $timestamp = NULL) {
        if (!$timestamp) { 
            $timestamp = time();
        }
        $columns = [$field_name . '_target_id'];
        $revision_table_name = 'node_alt_revision__' . $field_name;

        $delta_query = $this->database->select($revision_table_name, 'rt');
        $delta_query->fields('rt', ['delta']);
        $delta_query->condition('entity_id', $nid, '=');
        $delta_query->orderBy('delta', 'DESC');
        $delta_query->range(0, 1);
        $deltas = reset($delta_query->execute()->fetchCol());
        
        $result = [];
        for($i = 0; $i <= $deltas; $i++) {
            $revision_query = $this->database->select($revision_table_name, 'rt');
            $revision_query->fields('rt', $columns);
            $revision_query->orderBy('revision_date', 'DESC');
            $revision_query->condition('entity_id', $nid, '=');
            $revision_query->condition('delta', $i, '=');
            $revision_query->condition('revision_date', $timestamp, '<=');
            $revision_query->range(0,1);
            $revision_query_result = reset($revision_query->execute()->fetchAll());
            $result_array = (array) $revision_query_result;
            foreach ($result_array as $key => $value) {
                if (str_starts_with($key, ($field_name . "_"))) {
                    $result_array = array_combine(
                        array_map(function($key) use ($field_name) {
                            return str_replace(($field_name . "_"), "", $key);
                        }, array_keys($result_array)),
                        $result_array
                    );
                }
            }
            $result[$i] = $result_array;
        }
        return $result;
    }

    private function getTextSummaryChanges($field_name, $comparison_results) {
        $value_field = $field_name . '_value';
        $format_field = $field_name . '_format';
        $summary_field = $field_name . '_summary';
        $changes = [];
        if (count($comparison_results) == 1) {
            $changes[$value_field] = ['', $comparison_results[0]->$value_field];
            $changes[$format_field] = ['', $comparison_results[0]->$format_field];
            $changes[$summary_field] = ['', $comparison_results[0]->$summary_field];
        } else {
            if ($comparison_results[0]->$value_field != $comparison_results[1]->$value_field) {
                $changes[$value_field] = [$comparison_results[1]->$value_field, $comparison_results[0]->$value_field];
            }
            if ($comparison_results[0]->$format_field != $comparison_results[1]->$format_field) {
                $changes[$format_field] = [$comparison_results[1]->$format_field, $comparison_results[0]->$format_field];
            }
            if ($comparison_results[0]->$summary_field != $comparison_results[1]->$summary_field) {
                $changes[$summary_field] = [$comparison_results[1]->$summary_field, $comparison_results[0]->$summary_field];
            }
        }
        return $changes;
    }

    private function getTextLongChanges($field_name, $comparison_results) {
        $value_field = $field_name . '_value';
        $format_field = $field_name . '_format';
        $changes = [];
        if (count($comparison_results) == 1) {
            $changes[$value_field] = ['', $comparison_results[0]->$value_field];
            $changes[$format_field] = ['', $comparison_results[0]->$format_field];
        } else {
            if ($comparison_results[0]->$value_field != $comparison_results[1]->$value_field) {
                $changes[$value_field] = [$comparison_results[1]->$value_field, $comparison_results[0]->$value_field];
            }
            if ($comparison_results[0]->$format_field != $comparison_results[1]->$format_field) {
                $changes[$format_field] = [$comparison_results[1]->$format_field, $comparison_results[0]->$format_field];
            }
        }
        return $changes;
    }

    private function getDateTimeChanges($field_name, $comparison_results) {
        $value_field = $field_name . '_value';
        $changes = [];
        if (count($comparison_results) == 1) {
            $changes[$value_field] = ['', $comparison_results[0]->$value_field];
        } else {
            if ($comparison_results[0]->$value_field != $comparison_results[1]->$value_field) {
                $changes[$value_field] = [$comparison_results[1]->$value_field, $comparison_results[0]->$value_field];
            }
        }
        return $changes;
    }

    private function getBasicImageChanges($field_name, $comparison_results) {
        $target_id = $field_name . '_target_id';
        $alt = $field_name . '_alt';
        $title = $field_name . '_title';
        $width = $field_name . '_width';
        $height = $field_name . '_height';
        $changes = [];
        if (count($comparison_results) == 1) {
            $changes[$target_id] = ['', $comparison_results[0]->$target_id];
            $changes[$alt] = ['', $comparison_results[0]->$alt];
            $changes[$title] = ['', $comparison_results[0]->$title];
            $changes[$width] = ['', $comparison_results[0]->$width];
            $changes[$height] = ['', $comparison_results[0]->$height];
        } else {
            if ($comparison_results[0]->$target_id != $comparison_results[1]->$target_id) {
                $changes[$target_id] = [$comparison_results[1]->$target_id, $comparison_results[0]->$target_id];
            }
            if ($comparison_results[0]->$alt != $comparison_results[1]->$alt) {
                $changes[$alt] = [$comparison_results[1]->$alt, $comparison_results[0]->$alt];
            }
            if ($comparison_results[0]->$title != $comparison_results[1]->$title) {
                $changes[$title] = [$comparison_results[1]->$title, $comparison_results[0]->$title];
            }
            if ($comparison_results[0]->$width != $comparison_results[1]->$width) {
                $changes[$width] = [$comparison_results[1]->$width, $comparison_results[0]->$width];
            }
            if ($comparison_results[0]->$height != $comparison_results[1]->$height) {
                $changes[$height] = [$comparison_results[1]->$height, $comparison_results[0]->$height];
            }
        }
        return $changes;
    }

    private function getEntityReferenceChanges($field_name, $comparison_results) {
        $target_id = $field_name . '_target_id';
        $changes = [];
        if (count($comparison_results) == 1) {
            $changes[$target_id] = ['', $comparison_results[0]->$target_id];
        } else {
            if ($comparison_results[0]->$target_id != $comparison_results[1]->$target_id) {
                $changes[$target_id] = [$comparison_results[1]->$target_id, $comparison_results[0]->$target_id];
            }
        }
        return $changes;
    }

}