<?php

namespace Drupal\dyniva_content_sync\Normalizer;

use Drupal\replication\Normalizer\BulkDocsNormalizer as BulkDocsNormalizerBase;
use Symfony\Component\Serializer\Exception\LogicException;
use Drupal\Component\Serialization\Json;

class BulkDocsNormalizer extends BulkDocsNormalizerBase {

  protected $supportedInterfaceOrClass = ['Drupal\dyniva_content_sync\BulkDocs\BulkDocs'];

  /**
   * {@inheritdoc}
   */
  public function denormalize($data, $class, $format = NULL, array $context = []) {
    if (!isset($context['workspace'])) {
      throw new LogicException('A \'workspace\' context is required to denormalize revision diff data.');
    }

    try {
      /** @var \Drupal\replication\BulkDocs\BulkDocsInterface $bulk_docs */
      $bulk_docs = \Drupal::service('dyniva_content_sync.bulkdocs_factory')->get($context['workspace']);

      if (
          (isset($data['new_edits']) && ($data['new_edits']) === FALSE) ||
          (isset($context['query']['new_edits']) && ($context['query']['new_edits']) === FALSE)
          ) {
            $bulk_docs->newEdits(FALSE);
          }

          $entities = [];
          if (isset($data['docs'])) {
            foreach ($data['docs'] as $doc) {
              if (!empty($doc)) {
                if (is_string($doc)) {
                  $doc = Json::decode($doc);
                }
                // @todo {@link https://www.drupal.org/node/2599934 Find a more generic way to denormalize.}
                if (!empty($doc['_id']) && strpos($doc['_id'], 'local') !== FALSE) {
                  // Denormalize replication_log entities. This is used when the
                  // replication_log entity format is not standard, for example when
                  // replicating content from PouchDB.
                  list($prefix, $entity_uuid) = explode('/', $doc['_id']);
                  if ($prefix == '_local' && $entity_uuid) {
                    $entity = $this->serializer->denormalize($doc, 'Drupal\replication\Entity\ReplicationLog', $format, $context);
                  }
                }
                // Check if the document is a valid Relaxed format.
                elseif (isset($doc['@context'])) {
                  /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
                  $entity = $this->serializer->denormalize($doc, 'Drupal\Core\Entity\ContentEntityInterface', $format, $context);
                  foreach($entity->getFields() as $name => $field) {
                    if($field->getFieldDefinition()->getType() == 'text_with_summary') {
                      if(strpos($field->value, 'data-entity-uuid') !== false) {
                        $html = \Drupal\Component\Utility\Html::load($field->value);
                        $elements = $html->getElementsByTagName('img');
                        /** @var \DOMElement $element */
                        foreach($elements as $element) {
                          $uuid = $element->getAttribute('data-entity-uuid');
                          $file = \Drupal::service('entity.repository')
                            ->loadEntityByUuid('file', $uuid);
                          if($file) {
                            $path = file_url_transform_relative(file_create_url($file->getFileUri()));
                            $element->setAttribute('src', $path);
                          }
                        }
                        $html_string = $html->saveHTML($html->getElementsByTagName('body')->item(0));
                        $html_string = str_replace('<body>', '', $html_string);
                        $html_string = trim(str_replace('</body>', '', $html_string), "\n");
                        $entity->get($name)->value = $html_string;
                      }
                    }
                  }
                }
                // Ensure a deleted doc really is marked as deleted. This may be
                // necessary when an entity is to be deleted only on certain
                // replication targets; e.g., due to filtered replication.
                if (!empty($doc['deleted']) && !empty($entity)) {
                  $entity->_deleted->value = TRUE;
                }
                if (!empty($entity)) {
                  $entities[] = $entity;
                }
              }
            }
          }
          $bulk_docs->setEntities($entities);
    }
    catch (\Exception $e) {
      watchdog_exception('Replication', $e);
    }

    return $bulk_docs;
  }

}
