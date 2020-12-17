<?php

namespace Drupal\dyniva_content_sync;

use Drupal\replication\BulkDocsFactory as BulkDocsFactoryBase;
use Drupal\dyniva_content_sync\BulkDocs\BulkDocs;
use Drupal\multiversion\Entity\WorkspaceInterface;

class BulkDocsFactory extends BulkDocsFactoryBase {

  /**
   * @inheritDoc
   */
  public function get(WorkspaceInterface $workspace) {
    return new BulkDocs(
      $this->workspaceManager,
      $workspace,
      $this->uuidIndex,
      $this->revIndex,
      $this->entityTypeManager,
      $this->lock,
      $this->logger,
      $this->state,
      $this->config
    );
  }

}
