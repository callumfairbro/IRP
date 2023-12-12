<?php

namespace Drupal\alternative_revisions\Commands;

use Drupal\Component\Utility\Random;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;
use Drush\Commands\DrushCommands;

class DrushCustomCommands extends DrushCommands {
    
    /**
     * Entity type service.
     *
     * @var \Drupal\Core\Entity\EntityTypeManagerInterface
     */
    private $entityTypeManager;

    public function __construct(EntityTypeManagerInterface $entityTypeManager) {
        $this->entityTypeManager = $entityTypeManager;
    }

    /**
     *
     *  @command alternative_revisions:generate_revisions
     *  @aliases generate_revisions
     *  @usage alternative_revisions:generate_revisions quantity
     */
    public function generateRevisions($quantity){
        $random = new Random();
        for($i = 0; $i < $quantity; $i++) {
            $query = \Drupal::entityQuery('node')->accessCheck(TRUE);
            $query->condition('type', 'page');
            $query->addTag('sort_by_random');
            $query->range(0, 1);
            $id = reset($query->execute());
            
            $node = Node::load($id);

            $fields_to_update = rand(1,5);
            $field_ids = range(1, 5);
            shuffle($field_ids);
            $distinctNumbers = array_slice($field_ids, 0, $fields_to_update);
            
            // echo("Changing {$fields_to_update} fields...\n");
            foreach ($distinctNumbers as $number) {
                switch ($number) {
                    case 1:
                        // echo("Updating text field...\n");
                        $randomText = $random->sentences(10);
                        $node->set('field_text', $randomText);
                        break;
                    case 2:
                        // echo("Updating date field...\n");
                        $startDate = strtotime('2000-01-01');
                        $endDate = strtotime('2023-01-01');
                        $randomTimestamp = mt_rand($startDate, $endDate);
                        $randomDateTime = DrupalDateTime::createFromTimestamp($randomTimestamp);
                        $formattedDate = $randomDateTime->format('Y-m-d\TH:i:s');
                        $node->set('field_date', $formattedDate);
                        break;
                    case 3:
                        // echo("Updating image field...\n");
                        $query = \Drupal::entityQuery('file')->accessCheck(TRUE);
                        $query->condition('filemime', ['image/jpeg', 'image/png'], 'IN');
                        $query->range(0, 1);
                        $query->addTag('rand');
                        $file_id = reset($query->execute());
                        if ($file_id) {
                            $node->set('field_basic_image', ['target_id' => $file_id]);
                        }
                        break;
                    case 4:
                        // echo("Updating media image field...\n");
                        $query = \Drupal::entityQuery('media')->accessCheck(TRUE);
                        $query->condition('bundle', 'image');
                        $query->range(0, 1);
                        $query->addTag('sort_by_random');
                        $result = reset($query->execute());
                        $node->set('field_media_image', $result);
                        break;
                    case 5:
                        // echo("Updating remote video field...\n");
                        $query = \Drupal::entityQuery('media')->accessCheck(TRUE);
                        $query->condition('bundle', 'remote_video');
                        $query->range(0, 1);
                        $query->addTag('sort_by_random');
                        $result = reset($query->execute());
                        $node->set('field_remote_video', $result);
                        break;
                }
            }
            echo("Saving node {$id}...\n");
            $node->setNewRevision(TRUE);
            $node->save();
        }
    }
}