<?php
namespace Drupal\dyniva_content_sync\Plugin\ManagedEntity;

use Drupal\dyniva_core\Plugin\ManagedEntityPluginBase;
use Drupal\dyniva_core\Entity\ManagedEntity;
use Drupal\Core\Entity\EntityInterface;
use Drupal\dyniva_content_sync\ContentSyncHelper;
use Drupal\Component\Serialization\Json;
use Symfony\Component\HttpFoundation\Response;

/**
 * ManagedEntity Plugin.
 *
 * @ManagedEntityPlugin(
 *  id = "entity_export",
 *  label = @Translation("Export"),
 *  weight = 0
 * )
 *
 */
class EntityExport extends ManagedEntityPluginBase{
  /**
   * @inheritdoc
   */
  public function buildPage(ManagedEntity $managedEntity, EntityInterface $entity){
    $skiped_fields = [''];
    
    $docs = ContentSyncHelper::exportEntity($entity, $skiped_fields);
    $file = Json::encode($docs);
    $response =  new Response($file);
    $file_name = $entity->label() . '_' . date('YmdHis');
    $response->headers->set('Content-Type', 'text/json; charset=utf-8');
    $response->headers->set('Content-Disposition', 'attachment;filename="' . $file_name . '.json"');
    $response->headers->set('Cache-Control','max-age=0');
    return $response;
  }
  /**
   * @inheritdoc
   */
  public function getPageTitle(ManagedEntity $managedEntity, EntityInterface $entity){
    return $this->pluginDefinition['label'] . ' ' . $entity->label();
  }
  /**
   * @inheritdoc
   */
  public function isMenuTask(ManagedEntity $managedEntity){
    return FALSE;
  }
  /**
   * @inheritdoc
   */
  public function isMenuAction(ManagedEntity $managedEntity){
    return FALSE;
  }
}
