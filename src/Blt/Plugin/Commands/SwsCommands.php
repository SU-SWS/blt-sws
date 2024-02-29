<?php

namespace Sws\BltSws\Blt\Plugin\Commands;

use Acquia\Blt\Robo\BltTasks;
use Acquia\Blt\Robo\Exceptions\BltException;
use GuzzleHttp\Client;
use Robo\ResultData;

/**
 * Class SwsCommand.
 *
 * @package Sws\BltSws\Blt\Plugin\Commands
 */
class SwsCommands extends BltTasks {

  use SwsCommandTrait;

  /**
   * Keyed array of aliases.
   *
   * @var array
   */
  protected $siteAliases = [];

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
   * Clear cache for all sites on an environment.
   *
   * @command sws:rebuild-caches
   *
   * @param $environment_name
   *   Acquia environment machine name.
   */
  public function rebuildCaches($environment_name) {
    $aliases = $this->taskDrush()
      ->drush('sa')
      ->option('format', 'json')
      ->printOutput(FALSE)
      ->run()
      ->getMessage();
    $aliases = json_decode($aliases, TRUE);
    $web_servers = [];
    foreach ($aliases as $alias => $alias_info) {
      if (str_ends_with($alias, ".$environment_name")) {
        $web_servers[$alias_info['host']] = $alias_info['host'];
      }
    }

    if (file_exists(__DIR__ . '/failed.txt')) {
      unlink(__DIR__ . '/failed.txt');
    }
    $commands = [];

    foreach ($web_servers as $server) {
      if (!$this->checkKnownHosts($environment_name, $server)) {
        throw new \Exception('Unknown error when connecting to ' . $server);
      }
      $commands[] = $this->blt()
        ->arg('sws:rebuild-cache-webhead')
        ->arg($environment_name)
        ->arg($server)
        ->getCommand();
    }

    $this->taskExec(implode(" &\n", $commands) . PHP_EOL . 'wait')->run();
  }

  /**
   * Clear caches on sites with an alias that matches the webhead url.
   *
   * @command sws:rebuild-cache-webhead
   *
   * @param string $environment_name
   *   Acquia environment machine name.
   * @param string $hostname
   *   Drush alias host name.
   */
  public function rebuildCachesWebhead($environment_name, $hostname) {
    $aliases_to_update = [];
    foreach ($this->getSiteAliases() as $alias => $info) {
      if ($info['host'] == $hostname && strpos($alias, $environment_name) !== FALSE) {
        $aliases_to_update[] = $alias;
      }
    }

    foreach ($aliases_to_update as $position => $alias) {
      $percent = round($position / count($aliases_to_update) * 100);
      $message = sprintf('%s%% complete. Finished %s of %s sites on %s.', $percent, $position, count($aliases_to_update), $hostname);
      // Yell message for every 10th site.
      if ($percent % 5 == 0) {
        $this->yell($message);
      }
      else {
        $this->say($message);
      }

      $task = $this->taskDrush()
        ->alias(str_replace('@', '', $alias))
        ->drush('cache:rebuild')
        ->run();
    }
  }

  /**
   * Clear cache, update databse and import config on all sites.
   *
   * @command sws:update-environment
   * @option rebuild-node-access
   *   If node_access_rebuild() should be executed after the config import.
   * @option database-only
   *   Only run database updates
   * @option configs-only
   *   Only run config imports.
   *
   * @param $environment_name
   *   Acquia environment machine name.
   */
  public function updateEnvironment($environment_name, $options = [
    'rebuild-node-access' => FALSE,
    'database-only' => FALSE,
    'configs-only' => FALSE,
  ]) {
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

    if (file_exists(__DIR__ . '/failed.txt')) {
      unlink(__DIR__ . '/failed.txt');
    }
    $db_update_tasks = [];
    $config_update_tasks = [];

    foreach ($web_servers as $server) {
      if (!$this->checkKnownHosts($environment_name, $server->hostname)) {
        throw new \Exception('Unknown error when connecting to ' . $server->hostname);
      }
      // Database updates only.
      if (!$options['configs-only']) {
        $task = $this->blt()
          ->arg('sws:update-webhead')
          ->arg($environment_name)
          ->arg($server->hostname)
          ->option('database-only');
        if ($options['rebuild-node-access']) {
          $task->option('rebuild-node-access');
        }
        $db_update_tasks[] = $task->getCommand();
      }

      // Config updates & imports.
      if (!$options['database-only']) {
        $task = $this->blt()
          ->arg('sws:update-webhead')
          ->arg($environment_name)
          ->arg($server->hostname)
          ->option('configs-only');
        $config_update_tasks[] = $task->getCommand();
      }
    }

    $this->taskExec(implode(" &\n", $db_update_tasks) . PHP_EOL . 'wait')->run();
    $this->taskExec(implode(" &\n", $config_update_tasks) . PHP_EOL . 'wait')->run();

    if (file_exists(__DIR__ . '/failed.txt')) {
      $sites = array_filter(explode("\n", file_get_contents(__DIR__ . '/failed.txt')));
      throw new \Exception('Some sites failed to update: ' . implode(', ', $sites) . "\n\nManually run `drush deploy` at these aliases to resolve them.");
    }
  }

  /**
   * Run a site status to check if the connection works on the give hostname.
   *
   * @param string $environment_name
   *   Acquia environment machine name.
   * @param string $hostname
   *   Alias host name.
   *
   * @return bool
   *   If successful.
   *
   * @throws \Robo\Exception\TaskException
   */
  protected function checkKnownHosts($environment_name, $hostname) {
    $this->say('Checking connection to webhead ' . $hostname);
    foreach ($this->getSiteAliases() as $alias => $info) {
      if ($info['host'] == $hostname && strpos($alias, $environment_name) !== FALSE) {
        return $this->taskDrush()->alias(str_replace('@', '', $alias))
          ->drush('st')
          ->printOutput(FALSE)
          ->run()
          ->wasSuccessful();
      }
    }
  }

  /**
   * Update all sites with an alias that matches the webhead url.
   *
   * @command sws:update-webhead
   *
   * @option rebuild-node-access
   *   If node_access_rebuild() should be executed after the config import.
   * @option database-only
   *   Only run database updates. Do not run configuration imports.
   * @option configs-only
   *   Only import configs.
   *
   * @param string $environment_name
   *   Acquia environment machine name.
   * @param string $hostname
   *   Drush alias host name.
   */
  public function updateEnvironmentWebhead($environment_name, $hostname, $options = [
    'rebuild-node-access' => FALSE,
    'database-only' => FALSE,
    'configs-only' => FALSE,
  ]) {
    $aliases_to_update = [];
    foreach ($this->getSiteAliases() as $alias => $info) {
      if ($info['host'] == $hostname && strpos($alias, $environment_name) !== FALSE) {
        $aliases_to_update[] = $alias;
      }
    }

    foreach ($aliases_to_update as $position => $alias) {
      $success = FALSE;

      $percent = round($position / count($aliases_to_update) * 100);
      $message = sprintf('%s%% complete. Finished %s of %s sites on %s.', $percent, $position, count($aliases_to_update), $hostname);
      // Yell message for every 10th site.
      if ($percent % 5 == 0) {
        $this->yell($message);
      }
      else {
        $this->say($message);
      }

      $attempts = 0;
      // Try 3 times for each site update.
      while ($attempts < 3) {
        $attempts++;

        $task = $this->taskDrush()
          ->alias(str_replace('@', '', $alias));

        if ($options['rebuild-node-access']) {
          $task->drush('eval')->arg('node_access_rebuild();');
        }

        if (!$options['configs-only']) {
          $task->drush('updatedb');
        }
        if (!$options['database-only']) {
          $task->drush('config:import');
        }

        $task->drush('state:set')->arg('system.maintenance_mode')->arg(0);
        if ($task->run()->wasSuccessful()) {
          $success = TRUE;
          $attempts = 999;
        }
      }
      if (!$success) {
        file_put_contents(__DIR__ . '/failed.txt', $alias . PHP_EOL, FILE_APPEND);
      }
    }
  }

  /**
   * Get the list of all site aliases available.
   *
   * @return array
   *   Keyed array of site aliases.
   *
   * @throws \Robo\Exception\TaskException
   */
  protected function getSiteAliases() {
    if (!empty($this->siteAliases)) {
      return $this->siteAliases;
    }

    $aliases = $this->taskDrush()
      ->drush('sa')
      ->option('format', 'json', '=')
      ->printOutput(FALSE)
      ->run()
      ->getMessage();
    $this->siteAliases = json_decode($aliases, TRUE);
    return $this->siteAliases;
  }

  /**
   * After code deployed, update all sites on the stack.
   *
   * @command sws:post-code-deploy
   *
   * @aliases sws:post-code-update
   */
  public function postCodeDeployUpdate($target_env, $deployed_tag) {
    $sites = $this->getConfigValue('multisites');
    $parallel_executions = (int) getenv('UPDATE_PARALLEL_PROCESSES') ?: 10;

    $site_chunks = array_chunk($sites, ceil(count($sites) / $parallel_executions));
    $commands = [];
    foreach ($site_chunks as $sites) {
      $commands[] = $this->blt()
        ->arg('sws:update-sites')
        ->arg(implode(',', $sites))
        ->getCommand();
    }
    file_put_contents(sys_get_temp_dir() . '/update-report.txt', '');
    $this->taskExec(implode(" &\n", $commands) . PHP_EOL . 'wait')->run();
    $report = array_filter(explode("\n", file_get_contents(sys_get_temp_dir() . '/update-report.txt')));

    $success = [];
    $failed = [];
    foreach ($report as $line) {
      [$site, $status] = explode(':', $line);
      if ((int) $status) {
        $success[] = $site;
      }
      else {
        $failed[] = $site;
      }
    }
    unlink(sys_get_temp_dir() . '/update-report.txt');

    $this->yell(sprintf('Updated %s sites successfully.', count($success)), 100);
    $slack_url = getenv('SLACK_NOTIFICATION_URL');

    if ($failed) {
      $this->yell(sprintf("Update failed for the following sites:\n%s", implode("\n", $failed)), 100, 'red');

      if ($slack_url) {
        $count = count($failed);
        $this->sendSlackNotification($slack_url, "A new deployment has been made to *$target_env* using *$deployed_tag*.\n\n*$count* sites failed to update.");
      }
      throw new \Exception('Failed update');
    }

    if ($slack_url) {
      $this->sendSlackNotification($slack_url, "A new deployment has been made to *$target_env* using *$deployed_tag*.");
    }
  }

  /**
   * Send out a slack notification.
   *
   * @param string $message
   *   Slack message.
   */
  protected function sendSlackNotification(string $slack_webhook, string $message) {
    $client = new Client();
    $client->post($slack_webhook, [
      'form_params' => [
        'payload' => json_encode([
          'username' => 'Acquia Cloud',
          'text' => $message,
          'icon_emoji' => 'information_source',
        ]),
      ],
    ]);
  }

  /**
   * Run db updates and config imports to a list of sites.
   *
   * @command sws:update-sites
   *
   * @var string $sites
   *   Comma delimited list of sites to update.
   */
  public function updateSites($sites, $options = ['partial-config-import' => FALSE]) {
    $sites = explode(',', $sites);
    foreach ($sites as $site_name) {
      $this->switchSiteContext($site_name);
      $task = $this->taskDrush();
      if (!$options['partial-config-import']) {
        $task->drush('updatedb')
          ->drush('config:import')
          ->option('partial');
      }
      else {
        $task->drush('deploy');
      }
      $success = $task->run()->wasSuccessful();
      file_put_contents(sys_get_temp_dir() . '/update-report.txt', $site_name . ($success ? ':1' : ':0') . PHP_EOL, FILE_APPEND);
    }
  }

}
