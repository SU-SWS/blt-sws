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
   *
   * @command sws:update-environment
   *
   * @param $environment_name
   *   Acquia environment machine name.
   */
  public function updateEnvironment($environment_name) {
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

    $task = $this->taskParallelExec();
    foreach ($web_servers as $server) {
      $task->process(
        $this->blt()
          ->arg('sws:update-webhead')
          ->arg($environment_name)
          ->arg($server->hostname)
      );
    }
    return $task->run();
  }

  /**
   * @command sws:update-webhead
   *
   * @param $environment_name
   * @param $hostname
   */
  public function updateEnvironmentWebhead($environment_name, $hostname) {
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
        $this->taskDrush()
          ->alias($alias)
          ->drush('st')
          ->run();
      }
    }
  }

}
