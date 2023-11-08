<?php

namespace Drupal\alternative_revisions\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
        $field_definitions = $node->getFieldDefinitions();
        $schema = $this->database->schema();
        foreach ($field_definitions as $key => $definition) {
            $field_type = $definition->getType();
            $revision_table_name = 'node_alt_revision__' . $key;
            if (str_starts_with($key, 'field_') && $schema->tableExists($revision_table_name)) {
                $date_query = $this->database->select($revision_table_name, 'ar');
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
                            case 'text_long':
                                $changes = $this->getTextLongChanges($key, $comparison_results);
                                break;
                            case 'image':
                                break;
                            case 'datetime':
                                break;
                            case 'entity_reference':
                                break;
                            case 'text_with_summary':
                                break; 
                        }     

                        $data[$revision_date][$key][$delta] = [$type, $changes];
                    }
                }
                
            }
        }
        $build['#theme'] = 'alternative_revisions_view_revisions';
        $build['#headers'] = ['Field name', 'Delta', 'Type of change', 'Changes', 'Date', 'View'];
        $build['nid'] = $nid;
        $build['#data'] = $data;
        return $build;
    }

    public function viewRevision($nid, $timestamp) {

    }

    public function restoreRevision($nid, $timestamp) {

    }

    public function viewDeletions() {

    }

    public function restoreDeletion($nid) {

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

}