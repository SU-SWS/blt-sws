<?php

namespace Sws\BltSws\Blt\Plugin\Commands;

use Acquia\Blt\Robo\BltTasks;
use Acquia\Blt\Robo\Exceptions\BltException;
use Robo\ResultData;

/**
 * Class SwsCommand.
 *
 * @package Sws\BltSws\Blt\Plugin\Commands
 */
class SwsCommands extends BltTasks {

  use SwsCommandTrait;

  /**
   * Clear out the domain 301 ("Site URL") redirect settings and clear caches.
   *
   * @command sws:unset-domain-301
   * @aliases sws301
   *
   * @options env Target a desired environment.
   *
   * @param string $site
   *   Site name to clear.
   * @param array $options
   *   Keyed array of command options.
   *
   * @throws \Robo\Exception\TaskException
   */
  public function unsetDomainRedirect($site, $options = ['env' => '01dev']) {
    $this->say('<info>Be sure you have the most recent drush alises by running <comment>blt aliases</comment> or <comment>ads run drush aliases:sync</comment>.</info>');
    $enable_nobots = FALSE;
    if ($this->input()->isInteractive()) {
      $enable_nobots = $this->askQuestion('Would you also like to enable nobots?');
    }

    $task = $this->taskDrush()
      ->alias("$site.{$options['env']}")
      ->drush('sqlq')
      ->arg('truncate table config_pages__su_site_url')
      ->drush('cr')
      ->drush('p:invalidate')
      ->arg('everything');

    if ($enable_nobots) {
      $task->drush('sset')->arg('nobots')->arg(1);
    }
    $task->run();
  }

  /**
   * Gets information about outdated composer packages, formatted for humans.
   *
   * @command sws:outdated
   * @description Checks with composer and reports about outdated dependencies
   */
  public function checkOutdatedDependencies() {
    $result = $this->taskExec('composer')
      ->arg('outdated')
      ->option('format', 'json', '=')
      ->printOutput(FALSE)
      ->run();
    $arr_deps = json_decode($result->getMessage(), TRUE);
    $outdated = '';
    foreach ($arr_deps['installed'] as $index => $dep) {
      if (($dep['latest-status'] != 'up-to-date') && ((strpos($dep['name'], 'drupal/') !== FALSE ||
          strpos($dep['name'], 'su-sws/') !== FALSE))) {
        $outdated .= 'Package: ' . $dep['name'] . ' is currently at: ' . $dep['version'] . ', latest version is ' . $dep['latest'] . PHP_EOL;
      }
    }
    if (!empty($outdated)) {
      $this->say($outdated);
      return new ResultData(1, $outdated);
    }
    return new ResultData(0, "No outdated dependencies exist.");
  }

  /**
   * Clear cache, update databse and import config on all sites.
   *
   * @command sws:update-environment
   * @option rebuild-node-access
   *   If node_access_rebuild() should be executed after the config import.
   *
   * @param $environment_name
   *   Acquia environment machine name.
   */
  public function updateEnvironment($environment_name, $options = ['rebuild-node-access' => FALSE]) {
    $this->connectAcquiaApi();;
    $environments = $this->acquiaEnvironments->getAll($this->appId);
    $environment_uuid = NULL;
    foreach ($environments as $environment) {
      if ($environment->name == $environment_name) {
        $environment_uuid = $environment->uuid;
      }
    }
    if (!$environment_uuid) {
      throw new \Exception('No environment found for ' . $environment_name);
    }

    $environment_servers = $this->acquiaServers->getAll($environment_uuid);
    $web_servers = array_filter($environment_servers->getArrayCopy(), function ($server) {
      return in_array('web', $server->roles);
    });

    $bash_lines = [];
    foreach ($web_servers as $server) {
      $task = $this->blt()
        ->arg('sws:update-webhead')
        ->arg($environment_name)
        ->arg($server->hostname);
      if ($options['rebuild-node-access']) {
        $task->option('rebuild-node-access');
      }

      $bash_lines[] = $task->getCommand();
    }
    $this->taskExec(implode(" &\n", $bash_lines))->run();
  }

  /**
   * Update all sites with an alias that matches the webhead url.
   *
   * @command sws:update-webhead
   *
   * @option rebuild-node-access
   *   If node_access_rebuild() should be executed after the config import.
   *
   * @param string $environment_name
   *   Acquia environment machine name.
   * @param string $hostname
   *   Drush alias host name.
   */
  public function updateEnvironmentWebhead($environment_name, $hostname, $options = ['rebuild-node-access' => FALSE]) {
    $aliases = $this->taskDrush()
      ->drush('sa')
      ->option('format', 'json', '=')
      ->printOutput(FALSE)
      ->run()
      ->getMessage();
    $aliases = json_decode($aliases, TRUE);

    foreach ($aliases as $alias => $info) {
      if ($info['host'] == $hostname) {
        $alias = str_replace('@', '', $alias);
        $task = $this->taskDrush()
          ->alias($alias)
          ->drush('cache:rebuild')
          ->drush('updatedb')
          ->drush('config:import');

        if ($options['rebuild-node-access']) {
          $task->drush('eval')->arg('node_access_rebuild();');
        }
        $task->run();
      }
    }
  }

}
