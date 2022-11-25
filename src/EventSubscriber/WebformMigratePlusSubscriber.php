<?php

namespace Drupal\webform_migrate_plus\EventSubscriber;


use Drupal\migrate_plus\Event\MigrateEvents as MigratePlusEvents;
use Drupal\migrate_plus\Event\MigratePrepareRowEvent;

use Drupal\migrate\MigrateSkipRowException;

use Drupal\webform\Utility\WebformYaml;

use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;



/**
 * Webform Migrate Plus event subscriber.
 */
class WebformMigratePlusSubscriber implements EventSubscriberInterface {

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs event subscriber.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   */
  public function __construct(MessengerInterface $messenger) {
    $this->messenger = $messenger;    
  }


  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
        MigratePlusEvents::PREPARE_ROW => ['onPrepareRow'],
    ];
  }

 /**
   * React to a new row.
   *
   * @param \Drupal\migrate_plus\Event\MigratePrepareRowEvent $event
   *   The prepare-row event.
   *
   * @throws \Drupal\migrate\MigrateSkipRowException
   */
  public function onPrepareRow(MigratePrepareRowEvent $event) {

    $migration = $event->getMigration();
    $row = $event->getRow();
    $migration_id = $migration->id();

    // First check migration ids for exact matches
    // - then we look for pattern matches
    switch($migration_id) {
    case 'upgrade_d7_webform':
      $this->migrateWebform($row);
      break;
    }
  }

  /**
   * Migrates a webform.
   *
   * @param \Drupal\migrate\Row $row
   *   The prepare-row event.
   *
   * @throws \Drupal\migrate\MigrateSkipRowException
   */
  public function migrateWebform(\Drupal\migrate\Row $row) {
    // Fields come in as a yaml string - convert to Array then
    $elements = $row->get('elements');
    if (!is_array($elements)) {      
      $elements = WebformYaml::decode($elements);
    }

    // We can use the same logic as finding children to filter out any
    // root elements that are properties.
    $element_keys = WebformMigratePlusSubscriber::getChildKeys($elements);

    foreach($element_keys as $key) {
      $element = $elements[$key];
      $elements[$key] = $this->migrateWebformElement($key, $element, $row->get('nid'));
    }

    $row->setSourceProperty('elements', $elements);
  }


  /**
   * Apply custom migration fixes to a webform element, and any children.
   *
   * @param string $key The key of the element
   * @param array $element Array that describes a Webform Component missing CiviCRM specific data.
   * @param int $nid  The node we are migrating.
   */
  public function migrateWebformElement(string $key, array $element,int $nid) {
    // First apply migrations to any children
    $element = $this->migrateWebformElementChildren($key,$element, $nid);


    // Apply any generic migrations
    $element = $this->migrateElement($key, $element, $nid);
    // Do we have any migrations to apply to this element.
    if (empty($element['#type'])) {
      $data = WebformMigratePlusSubscriber::getWebformComponentData($nid, $key);
      $element['#type'] = $data->type ?? '';
    }
    $migration_function = $this->getElementMigrationFunction($element['#type']);
    
    if (empty($migration_function)) {
      return $element;
    }
    else if (method_exists($this, $migration_function)) {
      return $this->$migration_function($element, ['key'=> $key, 'nid' => $nid]);
    }
    else {
      return;
    }
  }

  /**
   * Apply custom migration fixes to a webform element, and any children.
   *
   * @param string $key The key of the element
   * @param array $element Array that describes a Webform Component missing CiviCRM specific data.
   * @param int $nid  The node we are migrating.
   */
  public function migrateWebformElementChildren(string $key, array $elements, int $nid) {
    $child_keys = WebformMigratePlusSubscriber::getChildKeys($elements);
    foreach($child_keys as $child_key) {
      if (!empty($child_key)) {        
        $elements[$child_key] = $this->migrateWebformElement($child_key, $elements[$child_key], $nid);
      }
    }
    return $elements;
  }
    
  public static function getChildKeys(array $element) {
    return array_filter(array_keys($element),
                        function($key) {
                          return ($key === '' || $key[0] !== '#');
                        }
    );
  }

  public function getElementMigrationFunction(string $type) {
    $types_migrating = [
      'processed_text' => 'migrateMarkupField',
    ];    
    return $types_migrating[$type] ?? NULL;
  }  


  public function migrateMarkupField($element) {
    $mapping = [
      '#title' => '#admin_title',
    ];
    foreach($mapping as $from => $to) {
      $element[$to] = $element[$from];
      unset($element[$from]);
    }
    return $element;
  }

  public function migrateElement($key, $element, $nid, $extra = NULL) {
    if (empty($extra)) {
      $extra = WebformMigratePlusSubscriber::getWebformComponentExtraData($nid, $key);
    }
    if (!empty($extra['private'])) {
      $element['#private'] = TRUE;
    }
    
    return $element;
  }


  /**
   * Helper function to get the component data associated with a webform
   * component from the webform_component table.
   *
   * @param int $nid Node Id in D7
   * @param string $form_key Form Key of component - should be the same as in d7 as in d9 might have depth prepended to uniquify, if fieldset might be munged
   *
   * @returns array Unserialized data from d7 database.
   */
  public static function getWebformComponentData(int $nid, string $form_key, bool $recurse = TRUE){
    $conn = \Drupal\Core\Database\Database::getConnection('default', 'migrate');
    $qry_str = "select extra from {webform_component} where nid = :nid and form_key like :form_key";
    
    $query = $conn->select('webform_component', 'wc')
                  ->fields('wc')
                  ->condition('nid', $nid, '=')
                  ->condition('form_key', $form_key, '=');
    $result = $query->execute();
    $rows = $result->fetchAll();

    if (count($rows) != 1) {
      if ($recurse) {
        $patterns = [
          '/fieldset_/' => '', // Fieldsets in d7 might not have fieldset in key
          '/_[0-9]+$/'  => '', // Child components get _N appended to key where N = depth by webform_migrate module.
        ];
        foreach($patterns as $pattern => $replacement) {
          $stripped = preg_replace($pattern, $replacement, $form_key);
          if ($stripped != $form_key ) {           
            try {
              return WebformMigratePlusSubscriber::getWebformComponentExtraData($nid, $stripped, FALSE);
            } catch (MigrateSkipRowException $e ) {
              // We catch exeception as we want to try the next
              // pattern. If all fail we'll throw then.
              continue;
            }
          }
        }
      }      
      // We have 0 or more than 1 matches to d7 webform component.
      // Our attempts to pattern match on potential keys have
      // failed.
      // Error out.
      throw new MigrateSkipRowException("Failed to match form key. Attempted to match key: $form_key with nid: $nid in d7 webform_component table - found " . count($rows) . ' rows');
    }    
    return $rows[0]; // We only have one row per node.
  }
  
  
  /**
   * Helper function to get the extra data associated with a webform
   * component from the webform_component table.
   *
   * @param int $nid Node Id in D7
   * @param string $form_key Form Key of component - should be the same as in d7 as in d9 might have depth prepended to uniquify, if fieldset might be munged
   *
   * @returns array Unserialized data from d7 database.
   */
  public static function getWebformComponentExtraData(int $nid, string $form_key, bool $recurse = TRUE){
    $row = WebformMigratePlusSubscriber::getWebformComponentData($nid, $form_key, $recurse);
    return unserialize($row->extra);
  }

}
