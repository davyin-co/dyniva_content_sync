<?php

namespace Drupal\dyniva_content_sync\Normalizer;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\multiversion\Entity\WorkspaceInterface;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\replication\Normalizer\ContentEntityNormalizer as ContentEntityNormalizerBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\menu_link_content\MenuLinkContentInterface;
use Drupal\replication\Event\ReplicationContentDataAlterEvent;
use Drupal\replication\Event\ReplicationDataEvents;

class ContentEntityNormalizer extends ContentEntityNormalizerBase {
  
  /**
   * {@inheritdoc}
   */
  public function normalize($entity, $format = NULL, array $context = []) {
    $workspace = isset($entity->workspace->entity) ? $entity->workspace->entity : null;
    $rev_tree_index = $this->indexFactory->get('multiversion.entity_index.rev.tree', $workspace);
    
    $entity_type_id = $context['entity_type'] = $entity->getEntityTypeId();
    $entity_type = $this->entityManager->getDefinition($entity_type_id);
    
    $id_key = $entity_type->getKey('id');
    $revision_key = $entity_type->getKey('revision');
    $uuid_key = $entity_type->getKey('uuid');
    
    $entity_uuid = $entity->uuid();
    $entity_default_language = $entity->language();
    $entity_languages = $entity->getTranslationLanguages();
    
    $skiped_fields = isset($context['skiped_fields'])?$context['skiped_fields']:[];
    
    // Create the basic data array with JSON-LD data.
    $data = [
      '@context' => [
        '_id' => '@id',
        '@language' => $entity_default_language->getId(),
      ],
      '@type' => $entity_type_id,
      '_id' => $entity_uuid,
    ];
    
    // New or mocked entities might not have a rev yet.
    if (!empty($entity->_rev->value)) {
      $data['_rev'] = $entity->_rev->value;
    }
    
    // Loop through each language of the entity
    $field_definitions = $entity->getFieldDefinitions();
    foreach ($entity_languages as $entity_language) {
      $translation = $entity->getTranslation($entity_language->getId());
      // Add the default language
      $data[$entity_language->getId()] =
      [
        '@context' => [
          '@language' => $entity_language->getId(),
        ]
      ];
      foreach ($translation as $name => $field) {
        if(in_array($name, $skiped_fields)) {
          continue;
        }
        // Add data for each field (through the field's normalizer.
        $field_type = $field_definitions[$name]->getType();
        $items = $this->serializer->normalize($field, $format, $context);
        if ($field_type == 'password') {
          continue;
        }
        $data[$entity_language->getId()][$name] = $items;
      }
      // Override the normalization for the _deleted special field, just so that we
      // follow the API spec.
      if (isset($translation->_deleted->value) && $translation->_deleted->value == TRUE) {
        $data[$entity_language->getId()]['_deleted'] = TRUE;
        $data['_deleted'] = TRUE;
      }
      elseif (isset($data[$entity_language->getId()]['_deleted'])) {
        unset($data[$entity_language->getId()]['_deleted']);
      }
    }
    
    // @todo: Needs test.}
    // Normalize the $entity->_rev->revisions value.
    if (!empty($entity->_rev->revisions)) {
      $data['_revisions']['ids'] = $entity->_rev->revisions;
      $data['_revisions']['start'] = count($data['_revisions']['ids']);
    }
    
    if (!empty($context['query']['conflicts'])) {
      $conflicts = $rev_tree_index->getConflicts($entity_uuid);
      foreach ($conflicts as $rev => $status) {
        $data['_conflicts'][] = $rev;
      }
    }
    
    // Finally we remove certain fields that are "local" to this host.
    unset($data['workspace'], $data[$id_key], $data[$revision_key], $data[$uuid_key]);
    foreach ($entity_languages as $entity_language) {
      $langcode = $entity_language->getId();
      unset($data[$langcode]['workspace'], $data[$langcode][$id_key], $data[$langcode][$revision_key], $data[$langcode][$uuid_key]);
    }
    
    $event = new ReplicationContentDataAlterEvent($entity, $data, $format, $context);
    $this->dispatcher->dispatch(ReplicationDataEvents::ALTER_CONTENT_DATA, $event);
    
    return $event->getData();
  }
  /**
   * {@inheritdoc}
   */
  public function denormalize($data, $class, $format = NULL, array $context = []) {
    // Make sure these values start as NULL
    $entity_type_id = NULL;
    $entity_uuid = NULL;
    $entity_id = NULL;
    $revision_id = NULL;
    
    // Get the default language of the entity
    $default_langcode = $data['@context']['@language'];
    // Get all of the configured languages of the site
    $site_languages = $this->languageManager->getLanguages();
    
    // Resolve the UUID.
    if (empty($entity_uuid) && !empty($data['_id'])) {
      $entity_uuid = $data['_id'];
    }
    else {
      throw new UnexpectedValueException('The uuid value is missing.');
    }
    
    // Resolve the entity type ID.
    if (isset($data['@type'])) {
      $entity_type_id = $data['@type'];
    }
    elseif (!empty($context['entity_type'])) {
      $entity_type_id = $context['entity_type'];
    }
    
    // Map data from the UUID index.
    // @todo: {@link https://www.drupal.org/node/2599938 Needs test.}
    if (!empty($entity_uuid)) {
      $uuid_index = (isset($context['workspace']) && ($context['workspace'] instanceof WorkspaceInterface)) ? $this->indexFactory->get('multiversion.entity_index.uuid', $context['workspace']) : $this->indexFactory->get('multiversion.entity_index.uuid');
      if ($record = $uuid_index->get($entity_uuid)) {
        $entity_id = $record['entity_id'];
        $revision_id = $record['revision_id'];
        if (empty($entity_type_id)) {
          $entity_type_id = $record['entity_type_id'];
        }
        elseif ($entity_type_id != $record['entity_type_id']) {
          throw new UnexpectedValueException('The entity_type value does not match the existing UUID record.');
        }
      }else {
        $uuid_loads = $this->entityManager->getStorage($entity_type_id)->loadByProperties(['uuid' => $entity_uuid]);
        if(!empty($uuid_loads)) {
          $target_entity = reset($uuid_loads);
          $entity_id = $target_entity->id();
        }
      }
    }
    
    if (empty($entity_type_id)) {
      throw new UnexpectedValueException('The entity_type value is missing.');
    }
    
    // Add the _rev field to the $data array.
    $rev = null;
    if (isset($data['_rev'])) {
      $rev = $data['_rev'];
    }
    $revisions = [];
    if (isset($data['_revisions']['start']) && isset($data['_revisions']['ids'])) {
      $revisions = $data['_revisions'];
    }
    
    $entity_type = $this->entityManager->getDefinition($entity_type_id);
    $id_key = $entity_type->getKey('id');
    $revision_key = $entity_type->getKey('revision');
    $bundle_key = $entity_type->getKey('bundle');
    
    $translations = [];
    foreach ($data as $key => $translation) {
      // Skip any keys that start with '_' or '@'.
      if (in_array($key{0}, ['_', '@'])) {
        continue;
      }
      // When language is configured, undefined or not applicable go ahead with
      // denormalization.
      elseif (isset($site_languages[$key])
          || $key === LanguageInterface::LANGCODE_NOT_SPECIFIED
          || $key === LanguageInterface::LANGCODE_NOT_APPLICABLE) {
            $context['language'] = $key;
            $translations[$key] = $this->denormalizeTranslation($translation, $entity_id, $entity_uuid, $entity_type_id, $bundle_key, $entity_type, $id_key, $context, $rev, $revisions);
      }
      // Configure the language, then do denormalization.
      elseif (is_array($translation) && \Drupal::moduleHandler()->moduleExists('language')) {
        $language = ConfigurableLanguage::createFromLangcode($key);
        $language->save();
        $context['language'] = $key;
        $translations[$key] = $this->denormalizeTranslation($translation, $entity_id, $entity_uuid, $entity_type_id, $bundle_key, $entity_type, $id_key, $context, $rev, $revisions);
      }
      $translations[$key][$revision_key][0]['value'] = $revision_id;
    }
    
    // @todo {@link https://www.drupal.org/node/2599926 Use the passed $class to instantiate the entity.}
    
    $entity = NULL;
    if ($entity_id && $entity_type_id != 'file' && !empty($translations[$default_langcode])) {
      $entity = $this->createEntityInstance($translations[$default_langcode], $entity_type, $format, $context);
    }
    elseif ($entity_type_id == 'file' && !empty($translations[$default_langcode])) {
      unset($translations[$default_langcode][$id_key], $translations[$default_langcode][$revision_key]);
      $translations[$default_langcode]['status'][0]['value'] = FILE_STATUS_PERMANENT;
      $translations[$default_langcode]['uid'][0]['target_id'] = $this->usersMapping->getUidFromConfig();
      
      $entity = $this->createEntityInstance($translations[$default_langcode], $entity_type, $format, $context);
    }
    elseif (!empty($translations[$default_langcode])) {
      unset($translations[$default_langcode][$id_key], $translations[$default_langcode][$revision_key]);
      $entity = $this->createEntityInstance($translations[$default_langcode], $entity_type, $format, $context);
    }
    
    if ($entity instanceof ContentEntityInterface) {
      foreach ($site_languages as $site_language) {
        $langcode = $site_language->getId();
        if ($entity->language()->getId() != $langcode && isset($translations[$langcode]) && !$entity->hasTranslation($langcode)) {
          $entity->addTranslation($langcode, $translations[$langcode]);
        }
      }
    }
    if ($entity instanceof RevisionableInterface) {
      $entity->updateLoadedRevisionId();
    }
    
    if ($entity_id && $entity) {
      $entity->enforceIsNew(FALSE);
      $entity->setNewRevision(FALSE);
      $entity->_rev->is_stub = FALSE;
    }
    
    Cache::invalidateTags([$entity_type_id . '_list']);
    
    return $entity;
  }
  
  /**
   * @param $translation
   * @param int $entity_id
   * @param string $entity_uuid
   * @param string $entity_type_id
   * @param $bundle_key
   * @param $entity_type
   * @param $id_key
   * @param $context
   * @param $rev
   * @param array $revisions
   * @return mixed
   */
  private function denormalizeTranslation($translation, $entity_id, $entity_uuid, $entity_type_id, $bundle_key, $entity_type, $id_key, $context, $rev = null, array $revisions = []) {
    $multiversion_enabled = \Drupal::service('multiversion.manager')->isEnabledEntityType($entity_type);
    // Add the _rev field to the $translation array.
    if (isset($rev)) {
      $translation['_rev'] = [['value' => $rev]];
    }
    if (isset($revisions['start']) && isset($revisions['ids'])) {
      $translation['_rev'][0]['revisions'] = $revisions['ids'];
    }
    if (isset($entity_uuid)) {
      $translation['uuid'][0]['value'] = $entity_uuid;
    }
    
    // We need to nest the data for the _deleted field in its Drupal-specific
    // structure since it's un-nested to follow the API spec when normalized.
    // @todo {@link https://www.drupal.org/node/2599938 Needs test for situation when a replication overwrites delete.}
    $deleted = isset($translation['_deleted']) ? $translation['_deleted'] : FALSE;
    if($multiversion_enabled) {
      $translation['_deleted'] = [['value' => $deleted]];
    }else {
      unset($translation['_deleted']);
    }
    
    if ($entity_id) {
      // @todo {@link https://www.drupal.org/node/2599938 Needs test.}
      $translation[$id_key] = ['value' => $entity_id];
    }
    
    $bundle_id = $entity_type_id;
    if ($entity_type->hasKey('bundle')) {
      if (!empty($translation[$bundle_key][0]['value'])) {
        // Add bundle info when entity is not new.
        $bundle_id = $translation[$bundle_key][0]['value'];
        $translation[$bundle_key] = $bundle_id;
      }
      elseif (!empty($translation[$bundle_key][0]['target_id'])) {
        // Add bundle info when entity is new.
        $bundle_id = $translation[$bundle_key][0]['target_id'];
        $translation[$bundle_key] = $bundle_id;
      }
    }

    // Denormalize entity reference fields.
    foreach ($translation as $field_name => $field_info) {
      if (!is_array($field_info)) {
        continue;
      }
      $fields = $this->entityManager->getFieldDefinitions($entity_type_id, $bundle_id);
      if(!isset($fields[$field_name])) {
        unset($translation[$field_name]);
        continue;
      }
      foreach ($field_info as $delta => $item) {
        if (!is_array($item)) {
          continue;
        }
        if (isset($item['target_uuid'])) {
          $translation[$field_name][$delta] = $item;
          // Figure out what bundle we should use when creating the stub.
          $settings = $fields[$field_name]->getSettings();
          
          // Find the target entity type and target bundle IDs and figure out if
          // the referenced entity exists or not.
          $target_entity_uuid = $item['target_uuid'];
          
          // Denormalize link field type as an entity reference field if it
          // has info about 'target_uuid' and 'entity_type_id'. These are used
          // to denormalize 'uri' in formats like 'entity:ENTITY_TYPE/ID'.
          $type = $fields[$field_name]->getType();
          
          if ($type == 'link') {
            $url = parse_url($item['uri']);
            if (isset($url['scheme']) && $url['scheme'] == 'entity') {
              list($target_entity_type_id, ) = explode('/', parse_url($item['uri'], PHP_URL_PATH), 2);
            }
            elseif (isset($item['entity_type_id'])) {
              $target_entity_type_id = $item['entity_type_id'];
            }
          }
          elseif (isset($settings['target_type'])) {
            $target_entity_type_id = $settings['target_type'];
          }
          
          if (!$target_entity_type_id) {
            throw new UnexpectedValueException('The target entity type value is missing.');
          }
          
          if ($target_entity_type_id === 'user') {
            $translation[$field_name] = $this->usersMapping->mapReferenceField($translation, $field_name);
            continue;
          }
          
          if (isset($settings['handler_settings']['target_bundles'])) {
            $target_bundle_id = reset($settings['handler_settings']['target_bundles']);
          }
          else {
            // @todo: Update when {@link https://www.drupal.org/node/2412569
            // this setting is configurable}.
            $bundles = $this->entityManager->getBundleInfo($target_entity_type_id);
            $target_bundle_id = key($bundles);
          }
          $target_entity = null;
          $target_entity_type = \Drupal::entityTypeManager()->getDefinition($target_entity_type_id);
          $target_multiversion_enabled = \Drupal::service('multiversion.manager')->isEnabledEntityType($target_entity_type);
          if($target_multiversion_enabled) {
            $uuid_index = (isset($context['workspace']) && ($context['workspace'] instanceof WorkspaceInterface)) ? $this->indexFactory->get('multiversion.entity_index.uuid', $context['workspace']) : $this->indexFactory->get('multiversion.entity_index.uuid');
            if ($target_entity_info = $uuid_index->get($target_entity_uuid)) {
              $storage = $this->entityManager
              ->getStorage($target_entity_info['entity_type_id']);
              if ($context['workspace'] && $context['workspace'] instanceof WorkspaceInterface) {
                $storage->useWorkspace($context['workspace']->id());
              }
              $target_entity = $storage->load($target_entity_info['entity_id']);
            }
          }else {
            $uuid_loads = $this->entityManager->getStorage($target_entity_type_id)->loadByProperties(['uuid' => $target_entity_uuid]);
            if(!empty($uuid_loads)) {
              $target_entity = reset($uuid_loads);
            }
          }
          
          // Try to get real bundle.
          if (!empty($item['entity_type_id'])) {
            $bundle_key = $this->entityManager->getStorage($item['entity_type_id'])->getEntityType()->getKey('bundle');
            if (!empty($item[$bundle_key])) {
              $target_bundle_id = $item[$bundle_key];
            }
          }
          
          // This set the correct uri for link field if the target entity
          // already exists.
          if ($type == 'link' && $target_entity) {
            $id = $target_entity->id();
            $translation[$field_name][$delta]['uri'] = "entity:$target_entity_type_id/$id";
          }
          elseif ($target_entity) {
            $translation[$field_name][$delta]['target_id'] = $target_entity->id();
            // Special handling for Entity Reference Revisions, it needs the
            // revision ID in addition to the primary entity ID.
            if ($type === 'entity_reference_revisions') {
              $revision_key = $target_entity->getEntityType()->getKey('revision');
              $translation[$field_name][$delta]['target_revision_id'] = $target_entity->{$revision_key}->value;
            }
          }
          elseif(isset($item['target_label'])) {
            $label_key = $this->entityManager->getStorage($item['entity_type_id'])->getEntityType()->getKey('label');
            $bundle_key = $this->entityManager->getStorage($item['entity_type_id'])->getEntityType()->getKey('bundle');
            if($label_key && $bundle_key) {
              $label_loads = $this->entityManager->getStorage($target_entity_type_id)->loadByProperties([
                $label_key => $item['target_label'],
                $bundle_key => $target_bundle_id,
              ]);
              if(!empty($label_loads)) {
                $target_entity = reset($label_loads);
                $translation[$field_name][$delta]['target_id'] = $target_entity->id();
              }
            }
          }
          if (isset($translation[$field_name][$delta]['entity_type_id'])) {
            unset($translation[$field_name][$delta]['entity_type_id']);
          }
          if (isset($translation[$field_name][$delta]['target_uuid'])) {
            unset($translation[$field_name][$delta]['target_uuid']);
          }
          if (isset($translation[$field_name][$delta]['target_label'])) {
            unset($translation[$field_name][$delta]['target_label']);
          }
        }
      }
    }
    
    // Denormalize parent field for menu_link_content entity type.
    if ($entity_type_id == 'menu_link_content' && !empty($translation['parent'][0]['value'])) {
      $translation['parent'][0]['value'] = $this->denormalizeMenuLinkParent($translation['parent'][0]['value'], $context, $translation['@context']['@language']);
    }
    
    // Unset the comment field item if CID is NULL.
    if (!empty($translation['comment']) && is_array($translation['comment'])) {
      foreach ($translation['comment'] as $delta => $item) {
        if (empty($item['cid'])) {
          unset($translation['comment'][$delta]);
        }
      }
    }
    
    // Exclude "name" field (the user name) for comment entity type because
    // we'll change it during replication if it's a duplicate.
    if ($entity_type_id == 'comment' && isset($translation['name'])) {
      unset($translation['name']);
    }
    
    // Clean-up attributes we don't needs anymore.
    // Remove changed info, otherwise we can get validation errors when the
    // 'changed' value for existing entity is higher than for the new entity (revision).
    // @see \Drupal\Core\Entity\Plugin\Validation\Constraint\EntityChangedConstraintValidator::validate().
    foreach (['@context', '@type', '_id', '_revisions', 'changed'] as $key) {
      if (isset($translation[$key])) {
        unset($translation[$key]);
      }
    }

    return $translation;
  }
  /**
   * Handles entity creation for fieldable and non-fieldable entities.
   *
   * This makes sure denormalization runs on field items.
   *
   * @param array $data
   * @param EntityTypeInterface $entity_type
   * @param $format
   * @param array $context
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *
   * @see \Drupal\serialization\Normalizer\FieldableEntityNormalizerTrait
   */
  private function createEntityInstance(array $data, EntityTypeInterface $entity_type, $format, array $context = []) {
    // The bundle property will be required to denormalize a bundleable
    // fieldable entity.
    if ($entity_type->entityClassImplements(FieldableEntityInterface::class)) {
      // Extract bundle data to pass into entity creation if the entity type uses
      // bundles.
      if ($entity_type->hasKey('bundle')) {
        // Get an array containing the bundle only. This also remove the bundle
        // key from the $data array.
        $create_params = $this->extractBundleData($data, $entity_type);
      }
      else {
        $create_params = [];
      }
      
      // Create the entity from bundle data only, then apply field values after.
      $entity = $this->entityManager->getStorage($entity_type->id())->create($create_params);
      $this->denormalizeFieldData($data, $entity, $format, $context);
    }
    else {
      // Create the entity from all data.
      $entity = $this->entityManager->getStorage($entity_type->id())->create($data);
    }
    
    return $entity;
  }
  /**
   * @inheritdoc
   */
  protected function denormalizeFieldData(array $data, FieldableEntityInterface $entity, $format, array $context) {
    foreach ($data as $field_name => $field_data) {
      if($entity->hasField($field_name)) {
        $field_item_list = $entity->get($field_name);
        
        // Remove any values that were set as a part of entity creation (e.g
        // uuid). If the incoming field data is set to an empty array, this will
        // also have the effect of emptying the field in REST module.
        $field_item_list->setValue([]);
        $field_item_list_class = get_class($field_item_list);
        
        if ($field_data) {
          // The field instance must be passed in the context so that the field
          // denormalizer can update field values for the parent entity.
          $context['target_instance'] = $field_item_list;
          $this->serializer->denormalize($field_data, $field_item_list_class, $format, $context);
        }
      }
    }
  }
  /**
   * @param $data
   * @param $context
   * @param $langcode
   * @return string
   */
  protected function denormalizeMenuLinkParent($data, $context, $langcode) {
    if (strpos($data, 'menu_link_content') === 0) {
      list($type, $uuid) = explode(':', $data);
      if ($type === 'menu_link_content' && $uuid) {
        $storage = $this->entityManager->getStorage('menu_link_content');
        $parent = $storage->loadByProperties(['uuid' => $uuid]);
        $parent = reset($parent);
        if ($parent instanceof MenuLinkContentInterface) {
          return $type . ':' . $uuid;
        }
        elseif (!$parent) {
          // Create a new menu link as stub.
          $parent = $storage->create([
            'uuid' => $uuid,
            'link' => 'internal:/',
            'langcode' => $langcode,
          ]);
          
          // Set the target workspace if we have it in context.
          if (isset($context['workspace'])
            && ($context['workspace'] instanceof WorkspaceInterface)) {
              $parent->workspace->target_id = $context['workspace']->id();
            }
            // Indicate that this revision is a stub.
            $parent->_rev->is_stub = TRUE;
            $parent->save();
            return $type . ':' . $uuid;
        }
      }
    }
    return $data;
  }
}
