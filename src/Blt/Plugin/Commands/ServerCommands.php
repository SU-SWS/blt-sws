<?php

namespace Sws\BltSws\Blt\Plugin\Commands;

use Acquia\Blt\Robo\Commands\Artifact\AcHooksCommand;

/**
 * Class CardinalsitesServerCommand.
 */
class ServerCommands extends AcHooksCommand {

  use SwsCommandTrait;

  /**
   * Get encryption keys from acquia.
   *
   * @command sws:keys
   * @description Get encryption keys from acquia's servers
   */
  public function cardinalsitesKeys() {
    $keys_dir = $this->getConfigValue('repo.root') . '/keys';
    if (!is_dir($keys_dir)) {
      mkdir($keys_dir, 0777, TRUE);
    }
    $ssh = $this->getConfigValue('keys_rsync.ssh');
    foreach ($this->getConfigValue('keys_rsync.files') as $from_path) {
      $tasks[] = $this->taskRsync()
        ->fromPath("$ssh:$from_path")
        ->toPath($keys_dir)
        ->recursive()
        ->excludeVcs()
        ->verbose()
        ->progress()
        ->humanReadable()
        ->stats();
    }
    return $this->collectionBuilder()->addTaskList($tasks)->run();
  }

  /**
   * This will be called after the drupal:install command.
   *
   * @hook post-command drupal:install
   */
  public function postDrupalInstallHook() {
    try {
      $this->invokeCommand('drupal:toggle:modules');
    }
    catch (\Throwable $e) {
      $this->say($e->getMessage());
    }
  }

}
