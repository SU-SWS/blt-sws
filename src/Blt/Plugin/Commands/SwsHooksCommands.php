<?php

namespace Sws\BltSws\Blt\Plugin\Commands;

use Acquia\Blt\Robo\BltTasks;
use Drupal\Component\Utility\Crypt;
use Robo\Contract\VerbosityThresholdInterface;

/**
 * All command hooks for BLT.
 *
 * @package Example\Blt\Plugin\Commands
 */
class SwsHooksCommands extends BltTasks {

  /**
   * Resets the opcache before the post code deploy command runs.
   *
   * @hook pre-command artifact:ac-hooks:post-code-deploy
   */
  public function prePostCodeUpdate() {
    opcache_reset();
  }

  /**
   * Create new salt value on a deploy.
   *
   * @hook post-command artifact:build
   */
  public function postArtifactBuild(){
    $this->taskWriteToFile($this->getConfigValue('deploy.dir') . '/salt.txt')
      ->text(Crypt::randomBytesBase64())
      ->run();
  }

  /**
   * Things to do prior to building simplesamlphp-config.
   *
   * @hook pre-command source:build:simplesamlphp-config
   */
  public function preSamlConfigCopy() {
    $task = $this->taskFilesystemStack()
      ->stopOnFail()
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_VERBOSE);
    $repo_root = $this->getConfigValue('repo.root');
    $copy_map = [
      $repo_root . '/simplesamlphp/config/default.local.config.php' => $repo_root . '/simplesamlphp/config/local.config.php',
      $repo_root . '/simplesamlphp/config/default.local.authsources.php' => $repo_root . '/simplesamlphp/config/local.authsources.php',
    ];
    foreach ($copy_map as $from => $to) {
      if (!file_exists($to)) {
        $task->copy($from, $to);
      }
    }
    $task->run();
    foreach ($copy_map as $to) {
      $this->getConfig()->expandFileProperties($to);
    }
  }

  /**
   * Deletes any local related file from artifact after BLT copies them over.
   *
   * @hook post-command artifact:build:simplesamlphp-config
   */
  public function postArtifactSamlConfigCopy() {
    $deploy_dir = $this->getConfigValue('deploy.dir');
    $files = glob("$deploy_dir/vendor/simplesamlphp/simplesamlphp/config/*local.*");
    $task = $this->taskFileSystemStack();
    foreach ($files as $file) {
      $task->remove($file);
    }
    $task->run();
  }

  /**
   * Install local settings for all multisites.
   *
   * If there is a default.local.settings.php file in the
   * docroot/sites/settings directory and we are in a local
   * environment, copy it to docroot/sites/settings/local.settings.php.
   *
   * @hook post-command blt:init:settings
   */
  public function postInitSettings() {
    $all_sites_settings_path = $this->getConfigValue('docroot') . '/sites/settings';
    if (is_readable($all_sites_settings_path . '/default.local.settings.php')
      && !file_exists($all_sites_settings_path . '/local.settings.php')) {
      $this->taskFilesystemStack()
        ->stopOnFail()
        ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_VERBOSE)
        ->copy($all_sites_settings_path . '/default.local.settings.php', $all_sites_settings_path . '/local.settings.php')
        ->run();
    }
  }

}
