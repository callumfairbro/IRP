<?php

namespace Drupal\alternative_revisions\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
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

    }

    public function restoreRevision($nid, $revision_id) {

    }

    public function viewDeletions() {
        
    }

    public function restoreDeletion($nid) {

    }

}