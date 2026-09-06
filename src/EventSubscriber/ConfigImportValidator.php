<?php

namespace Drupal\postmark_webhooks\EventSubscriber;

use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ConfigImporterEvent;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Applies the form's configuration limits to imported settings too.
 */
final class ConfigImportValidator implements EventSubscriberInterface {

  /**
   * Constructs the import validator.
   */
  public function __construct(private readonly TypedConfigManagerInterface $typedConfig) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [ConfigEvents::IMPORT_VALIDATE => 'validate'];
  }

  /**
   * Rejects imports with settings that violate the configuration schema.
   */
  public function validate(ConfigImporterEvent $event): void {
    $importer = $event->getConfigImporter();
    $name = 'postmark_webhooks.settings';
    $changes = array_merge($event->getChangelist('create'), $event->getChangelist('update'));
    if (!in_array($name, $changes, TRUE)) {
      return;
    }
    $data = $importer->getStorageComparer()->getSourceStorage()->read($name);
    if ($data === FALSE) {
      return;
    }
    $violations = $this->typedConfig->createFromNameAndData($name, $data)->validate();
    if (count($violations)) {
      $importer->logError('Postmark Webhooks settings violate the configuration schema. Check suppression and retention limits.');
    }
  }

}
