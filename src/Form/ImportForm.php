<?php

namespace Drupal\dyniva_content_sync\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\dyniva_content_sync\ContentSyncHelper;
use Drupal\Component\Serialization\Json;
use Symfony\Component\HttpFoundation\Response;
use Drupal\file\Entity\File;

/**
 * Class ConnectionBindForm.
 *
 * @package Drupal\dyniva_content_sync\Form
 */
class ImportForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'dyniva_content_sync_import_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $uppath = 'public://content_sync/';
    $validators = [
      'file_validate_extensions' => ['json'],
    ];
    $form['file'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Content Json'),
      '#field_name' => 'file',
      '#upload_location' => $uppath,
      '#upload_validators' => $validators,
      '#description' => t('Please select a content json file.'),
      '#required' => TRUE,
    ];
    
    $form['actions'] = [
      '#type' => 'actions',
    ];
    
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import'),
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
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $file = $form_state->getValue('file');
    $flag = true;
    if (!empty($file)) {
      $file = File::load($file['0']);
      $uri = $file->getFileUri();
      
      $docs = Json::decode(file_get_contents($uri));
      if($docs) {
        $bluk_docs = ContentSyncHelper::importDocs($docs);
        if($bluk_docs) {
          foreach ($bluk_docs->getResult() as $result) {
            if (isset($result['error'])) {
              \Drupal::messenger()->addError("{$result['error']}:{$result['reason']}:{$result['id']}:{$result['rev']}");
              $flag = false;
            }
          }
        }else {
          $flag = false;
          \Drupal::messenger()->addError(t("Import content failure."));
        }
      }
    }
    if($flag){
      \Drupal::messenger()->addStatus(t("Import content successfuly."));
    }
  }
}
