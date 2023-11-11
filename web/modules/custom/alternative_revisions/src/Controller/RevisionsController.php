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
            $data_date_query->fields('fd', ['revision_date']);
            $data_dates = $data_date_query->execute()->fetchAll();
            if ($data_dates) {
                foreach ($data_dates as $date_result) {
                    $revision_date = $date_result->revision_date;
                    $data_compare_query = $this->database->select('node_alt_revision_field_data', 'fd');
                    $data_compare_query->fields('fd', ['title', 'type', 'status', 'deleted', 'created', 'revision_date']);
                    $data_compare_query->orderBy('revision_date', 'DESC');
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
        $field_definitions = $node->getFieldDefinitions();
        $schema = $this->database->schema();
        foreach ($field_definitions as $key => $definition) {
            $field_type = $definition->getType();
            $revision_table_name = 'node_alt_revision__' . $key;
            
        }

    }

    public function restoreRevision($nid, $timestamp) {

    }

    public function viewDeletions() {

    }

    public function restoreDeletion($nid) {

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