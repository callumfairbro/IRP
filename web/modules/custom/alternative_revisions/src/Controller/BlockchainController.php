<?php

namespace Drupal\alternative_revisions\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Database;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;

class BlockchainController extends ControllerBase implements ContainerInjectionInterface {

    /**
     * @var \Drupal\Core\Entity\EntityTypeManager
     */
    protected $entityTypeManager;

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container) {
        $instance = parent::create($container);
        $instance->entityTypeManager = $container->get('entity_type.manager');
        return $instance;
    }

    public function checkBlockchainIntegrity($nid) {
        try {
            Database::setActiveConnection('blockchain');
            $blockchain_database = Database::getConnection();
        } catch (Exception $e) {
            Database::setActiveConnection();
            \Drupal::logger('alternative_revisions')->error($e);
        }

        $blockchain_data_query = $blockchain_database->select('blockchain_data', 'bc');
        $blockchain_data_query->condition('nid', $nid, '=');
        $blockchain_data_query->fields('bc', ['hash', 'timestamp']);
        $blockchain_data_query->orderBy('timestamp', 'ASC');
        $blockhain_data_results = $blockchain_data_query->execute()->fetchAll();
        
        Database::setActiveConnection();
        $database = Database::getConnection();
        $tables = $database->query("SHOW TABLES LIKE :prefix", [':prefix' => "node_alt_revision__%"])->fetchCol();

        if (!$blockhain_data_results || !$tables) {
            $messenger = \Drupal::service('messenger');
            $messenger->addMessage('No data available!');
            return $this->redirect('<front>');
        } else {
            $build_data = [];
            for ($i = 0; $i < count($blockhain_data_results); $i++) {
                $data = [];
                $blockchain_data = $blockhain_data_results[$i];
                $timestamp = $blockchain_data->timestamp;
                $current_hash = $blockchain_data->hash;
                if ($i != 0) {
                    $previous_index = $i - 1;
                    $previous_hash = $blockhain_data_results[$previous_index]->hash;
                }
                foreach ($tables as $table) {
                    $data_query = $database->select($table, 't');
                    if ($table == 'node_alt_revision_field_data') {
                        $data_query->condition('nid', $nid, '=');
                    } else {
                        $data_query->condition('entity_id', $nid, '=');
                    }
                    $data_query->condition('revision_date', $timestamp, '=');
                    $data_query->fields('t');
                    $data_result = $data_query->execute()->fetchAll();
                    if ($data_result) {
                        $data[] = $data_result;
                    }
                }
                if (!$data) {
                    $build_data[] = [$timestamp, $previous_hash, $current_hash, 'NULL', 'YES'];
                } else {
                    if ($previous_hash) {
                        $hash_data = [$previous_hash, $data];
                        $serialized_data = serialize($hash_data);
                        $hash = hash('sha256', $serialized_data);
                    } else {
                        $serialized_data = serialize($data);
                        $hash = hash('sha256', $serialized_data);
                    }
                    if (isset($hash)) {
                        if ($hash != $current_hash) {
                            $build_data[] = [$timestamp, $current_hash, $hash, 'YES'];
                        } else {
                            $build_data[] = [$timestamp, $current_hash, $hash, 'NO'];
                        }
                        
                    }
                }
            }
        }

        usort($build_data, fn($a, $b) => $b[0] <=> $a[0]);

        $build = [];
        $build['#theme'] = 'alternative_revisions_blockchain_integrity';
        $build['#headers'] = ['Timestamp', 'Current Hash', 'Calculated Hash', 'Violation Detected'];   
        $build['#data'] = $build_data;
        return ($build);
    }

}