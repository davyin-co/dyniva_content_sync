<?php

namespace Drupal\dyniva_content_sync;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldConfigInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Component\Utility\Html;

/**
 *
 * @author ziqiang
 *
 */
class ContentSyncHelper {

  /**
   * Export entity to serializer data.
   *
   * @param EntityInterface $entity
   * @param array $skiped_fields
   * @return NULL[]
   */
  public static function exportEntity(EntityInterface $entity, array $skiped_fields = []) {
    $docs = [];
    if(empty($entity)) return $docs;
    $serializer = \Drupal::service('serializer');
    if ($entity instanceof FieldableEntityInterface) {
      foreach ($entity->getFieldDefinitions() as $field_id => $field_definition) {
        if(in_array($field_id, $skiped_fields)) continue;
        if (self::fieldIsExportable($field_definition)) {
          $field = $entity->get($field_id);
          $fieldType = $field_definition->getType();
          foreach ($field as $index => $item) {
            if($fieldType == 'panelizer') {
              /**
               * @var Panelizer $panelizer
               */
              $panelizer = \Drupal::service('panelizer');
              $value = $item->getValue();
              $view_mode = $value['view_mode'];
              $panels_display = $panelizer->getPanelsDisplay($entity, $view_mode);

              $regions = $panels_display->getRegionAssignments();
              foreach ($regions as $region => $blocks) {
                if ($blocks) {
                  foreach ($blocks as $block_id => $block){
                    if ($block->getBaseId() == 'block_content') {
                      $uuid = $block->getDerivativeId();
                      $block_content = \Drupal::entityManager()->loadEntityByUuid('block_content', $uuid);
                      if($block_content){
                        $docs = array_merge($docs,static::exportEntity($block_content, $skiped_fields));
                      }
                    }
                  }
                }
              }
            }elseif(in_array($fieldType, ['text','text_long','text_with_summary'])) {
              if(!empty($item->value)) {
                $dom = Html::load($item->value);
                $xpath = new \DOMXPath($dom);
                foreach ($xpath->query('//*[@data-entity-type and @data-entity-uuid]') as $node) {
                  $type = $node->getAttribute('data-entity-type');
                  $uuid = $node->getAttribute('data-entity-uuid');
                  try {
                    $embed_entity = \Drupal::entityTypeManager()->getStorage($type)->loadByProperties(['uuid' => $uuid]);
                    if(!empty($embed_entity)){
                      $embed_entity = reset($embed_entity);
                      $docs = array_merge($docs,static::exportEntity($embed_entity, $skiped_fields));
                    }
                  } catch (\Exception $e) {
                  }
                }
              }
            }else{
              if(!empty($item->entity)) {
                $docs = array_merge($docs,static::exportEntity($item->entity, $skiped_fields));
              }
            }
          }
        }
      }
    }
    $docs[$entity->uuid()] = $serializer->normalize($entity, 'json', ['new_revision_id' => FALSE, 'skiped_fields' => $skiped_fields]);
    return $docs;
  }
  /**
   * Import json docs.
   *
   * @param array $docs
   */
  public static function importDocs(array $docs) {
    $serializer = \Drupal::service('serializer');
    $current_active = \Drupal::service('workspace.manager')->getActiveWorkspace();
    $bulk_docs = false;
    foreach($docs as $key => $doc) {
      $data = [
        'new_edits' => FALSE,
        'docs' => [$key => $doc],
      ];
      // Save all entities in bulk.
      $bulk_docs = $serializer->denormalize($data, 'Drupal\dyniva_content_sync\BulkDocs\BulkDocs', 'json', ['workspace' => $current_active]);
      $bulk_docs->save();
    }
    return $bulk_docs;
  }
  /**
   * Determines if a field is exportable.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field definition.
   *
   * @return bool
   *   TRUE if th field is exportable; FALSE otherwise.
   */
  public static function fieldIsExportable(FieldDefinitionInterface $field_definition) {
    $clonable_field_types = [
      'entity_reference',
      'entity_reference_revisions',
      'panelizer',
      'file',
      'image',
      'text',
      'text_long',
      'text_with_summary',
    ];

    $type_is_clonable = in_array($field_definition->getType(), $clonable_field_types, TRUE);
    if (($field_definition instanceof FieldConfigInterface) && $type_is_clonable) {
      $settings = $field_definition->getSettings();
      if(!empty($settings['target_type']) && in_array($settings['target_type'], ['user'])) {
        return FALSE;
      }
      return TRUE;
    }
    return FALSE;
  }
}
