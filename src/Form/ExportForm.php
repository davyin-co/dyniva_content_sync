<?php

namespace Drupal\dyniva_content_sync\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\dyniva_content_sync\ContentSyncHelper;
use Drupal\Component\Serialization\Json;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class ConnectionBindForm.
 *
 * @package Drupal\dyniva_content_sync\Form
 */
class ExportForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'dyniva_content_sync_export_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $form['entitys'] = [
      '#type' => 'textarea',
      '#description' => t('Input the entitys to export, use [entity_type_id]:[entity_id] format, one item per line.'),
      '#required' => true,
    ];
    $form['skiped_fields'] = [
      '#title' => t('Skiped Fields'),
      '#type' => 'textarea',
      '#description' => t('Input the field id, one item per line.'),
      '#required' => false,
    ];
    
    $form['actions'] = [
      '#type' => 'actions',
    ];
    
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Export'),
      '#attributes' => [
        'class' => ['ignore-loading','button--primary']
      ]
    ];
    $form['#cache'] = array(
      'max-age' => 0
    );
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $values = explode("\n",$form_state->getValue('entitys',''));
    $values = array_map('trim',$values);
    
    $entitys = [];
    foreach ($values as $item) {
      list($entity_type_id, $entity_id) = explode(':', $item);
      if($entity_type_id && $entity_id) {
        $entity = \Drupal::entityTypeManager()->getStorage($entity_type_id)->load($entity_id);
        if($entity) {
          $entitys[] = $entity;
        }else {
          $form_state->setErrorByName('entitys',t('No entity found for @entity_type id @entity_id',['@entity_type' => $entity_type_id,'@entity_id' => $entity_id]));
        }
      }
    }
    $form_state->set('entitys', $entitys);
  }
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $entitys = $form_state->get('entitys');
    $view_mode = 'full';
    
    $skiped_fields = explode("\n",$form_state->getValue('skiped_fields',''));
    $skiped_fields = array_map('trim',$skiped_fields);
    
    $docs = [];
    foreach ($entitys as $entity) {
      $docs = array_merge($docs, ContentSyncHelper::exportEntity($entity, $skiped_fields));
    }
    $file = Json::encode($docs);
    $response =  new Response($file);
    $file_name = 'content_' . date('YmdHis');
    $response->headers->set('Content-Type', 'text/json; charset=utf-8');
    $response->headers->set('Content-Disposition', 'attachment;filename="' . $file_name . '.json"');
    $response->headers->set('Cache-Control','max-age=0');
    $form_state->setResponse($response);
  }

}
