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
      if ($info['host'] == $hostname && str_contains($alias, $environment_name)) {
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
    $web_servers = [];
    foreach ($this->getSiteAliases() as $alias => $alias_info) {
      if (str_ends_with($alias, ".$environment_name") && str_contains($alias_info['host'], 'acquia')) {
        $web_servers[$alias_info['host']] = $alias_info['host'];
      }
    }

    if (file_exists(__DIR__ . '/failed.txt')) {
      unlink(__DIR__ . '/failed.txt');
    }
    $db_update_tasks = [];
    $config_update_tasks = [];

    foreach ($web_servers as $server) {
      if (!$this->checkKnownHosts($environment_name, $server)) {
        throw new \Exception('Unknown error when connecting to ' . $server);
      }
      // Database updates only.
      if (!$options['configs-only']) {
        $task = $this->blt()
          ->arg('sws:update-webhead')
          ->arg($environment_name)
          ->arg($server)
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
          ->arg($server)
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
      if ($info['host'] == $hostname && str_contains($alias, $environment_name)) {
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
      if ($info['host'] == $hostname && str_contains($alias, $environment_name)) {
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
   * To change how many parallel processes run, set an environment variable
   * `UPDATE_PARALLEL_PROCESSES` to a number of your choice. To send slack
   * notifications, set an environment variable `SLACK_NOTIFICATION_URL` with
   * the appropriate webhook url.
   *
   * @command sws:post-code-deploy
   *
   * @aliases sws:post-code-update
   *
   * @option no-slack Do not send slack notification
   * @option partial-config-import Run config:import --partial instead.
   * @option require-drupal-install If a site is not installed, consider it failed.
   */
  public function postCodeDeployUpdate($target_env, $deployed_tag, $options = ['no-slack' => FALSE, 'partial-config-import' => FALSE, 'require-drupal-install' => FALSE]) {
    $sites = $this->getConfigValue('multisites');
    $parallel_executions = (int) getenv('UPDATE_PARALLEL_PROCESSES') ?: 10;

    $i = 0;
    while ($sites) {
      $site = array_splice($sites, 0, 1);
      $site_chunks[$i][] = reset($site);
      $i++;
      if ($i >= $parallel_executions) {
        $i = 0;
      }
    }

    $commands = [];
    foreach ($site_chunks as $sites) {
      $command = $this->blt()
        ->arg('sws:update-sites')
        ->arg(implode(',', $sites));

      if ($options['partial-config-import']) {
        $command->option('partial-config-import');
      }

      if ($options['require-drupal-install']) {
        $command->option('require-drupal-install');
      }
      $commands[] = $command->getCommand();
    }

    file_put_contents(sys_get_temp_dir() . '/success-report.txt', '');
    file_put_contents(sys_get_temp_dir() . '/failed-report.txt', '');

    $this->taskExec(implode(" &\n", $commands) . PHP_EOL . 'wait')->run();

    $success_report = array_filter(explode("\n", file_get_contents(sys_get_temp_dir() . '/success-report.txt')));
    $failed_report = array_filter(explode("\n", file_get_contents(sys_get_temp_dir() . '/failed-report.txt')));

    $this->yell(sprintf('Updated %s sites successfully on "%s".', count($success_report), $target_env), 100);
    $slack_url = $options['no-slack'] ? FALSE : getenv('SLACK_NOTIFICATION_URL');

    if ($failed_report) {
      $this->yell(sprintf("Update failed on \"%s\" for the following sites:\n%s", $target_env, implode("\n", $failed_report)), 100, 'red');

      if ($slack_url) {
        $count = count($failed_report);
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
  public function updateSites($sites, $options = ['partial-config-import' => FALSE, 'require-drupal-install' => FALSE]) {
    $sites = explode(',', $sites);
    foreach ($sites as $site_name) {
      $this->switchSiteContext($site_name);
      if (!$this->isDrupalInstalled($site_name) && !$options['require-drupal-install']) {
        continue;
      }
      $task = $this->taskDrush();
      if ($options['partial-config-import']) {
        $task->drush('updatedb')
          ->drush('config:import')
          ->option('partial')
          ->drush('deploy:hook');
      }
      else {
        $task->drush('deploy');
      }

      if ($task->run()->wasSuccessful()) {
        file_put_contents(sys_get_temp_dir() . '/success-report.txt', $site_name . PHP_EOL, FILE_APPEND);
        continue;
      }

      file_put_contents(sys_get_temp_dir() . '/failed-report.txt', $site_name . PHP_EOL, FILE_APPEND);
    }
  }

    /**
   * Checks that Drupal is installed, caches result.
   *
   * Taken from \Acquia\Blt\Robo\Inspector\Inspector::isDrupalInstalled
   *
   * @return bool
   *   TRUE if Drupal is installed.
   */
  public function isDrupalInstalled($site) {
    $this->logger->debug("Verifying that $site has Drupal is installed...");
    return 'Successful' == ($this->getDrushStatus()['bootstrap'] ?? '');
  }

  /**
   * Gets the result of `drush status`.
   *
   * @return array
   *   The result of `drush status`.
   */
  public function getDrushStatus() {
    $docroot = $this->getConfigValue('docroot');
    $status_info = (array) json_decode($this->taskDrush()
      ->drush('status')
      ->option('format', 'json')
      ->option('fields', '*')
      ->option('root', $docroot)
      ->printOutput(FALSE)
      ->run()
      ->getMessage(), TRUE);

    return $status_info;
  }

}
