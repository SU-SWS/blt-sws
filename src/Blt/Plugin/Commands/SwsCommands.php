<?php

namespace Sws\BltSws\Blt\Plugin\Commands;

use Acquia\Blt\Robo\BltTasks;
use Acquia\Blt\Robo\Exceptions\BltException;
use Drupal\Core\Serialization\Yaml;
use Robo\ResultData;
use Symfony\Component\Finder\Finder;

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

    if (file_exists(__DIR__ . '/failed.txt')) {
      unlink(__DIR__ . '/failed.txt');
    }
    foreach ($web_servers as $server) {
      if (!$this->checkKnownHosts($environment_name, $server->hostname)) {
        throw new \Exception('Unknown error when connecting to ' . $server->hostname);
      }
      $task = $this->blt()
        ->arg('sws:update-webhead')
        ->arg($environment_name)
        ->arg($server->hostname);
      if ($options['rebuild-node-access']) {
        $task->option('rebuild-node-access');
      }

      $bash_lines[] = $task->getCommand();
    }
    $this->taskExec(implode(" &\n", $bash_lines) . PHP_EOL . 'wait')->run();
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
   *
   * @param string $environment_name
   *   Acquia environment machine name.
   * @param string $hostname
   *   Drush alias host name.
   */
  public function updateEnvironmentWebhead($environment_name, $hostname, $options = ['rebuild-node-access' => FALSE]) {
    foreach ($this->getSiteAliases() as $alias => $info) {
      $success = FALSE;
      if ($info['host'] == $hostname && strpos($alias, $environment_name) !== FALSE) {
        //        $attempts = 0;
        //        // Try 3 times for each site update.
        //        while ($attempts < 3) {
        //          $attempts++;
        //
        //          $task = $this->taskDrush()
        //            ->alias(str_replace('@', '', $alias))
        //            ->drush('deploy');
        //
        //          if ($options['rebuild-node-access']) {
        //            $task->drush('eval')->arg('node_access_rebuild();');
        //          }
        //
        //          if ($task->run()->wasSuccessful()) {
        //            $success = TRUE;
        //            $attempts = 999;
        //          }
        //        }
        if (!$success) {
          file_put_contents(__DIR__ . '/failed.txt', $alias . PHP_EOL, FILE_APPEND);
        }
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
   * Create releases for all custom packages.
   *
   * @command sws:releases
   *
   * @option packages
   *   Comma separated list of packages to create lists for.
   */
  public function swsReleases($options = ['packages' => '']) {
    $docroot = $this->getConfigValue('docroot');
    foreach (glob("$docroot/*/custom/*/*info.yml") as $package) {
      $package_name = basename($package, '.info.yml');

      if (!empty($options['packages'])) {
        $packages = explode(',', $options['packages']);
        if (!in_array($package_name, $packages)) {
          continue;
        }
      }

      $this->say(sprintf('Creating release for %s', $package_name));
      $this->createRelease($package);
    }
  }

  /**
   * Edit the Changelog and create a pull request for a package.
   *
   * @param string $dir
   */
  protected function createRelease($info_path) {
    $dir = dirname($info_path);
    $base_branch = 'master';
    $branch_name = $this->taskGit()
      ->dir($dir)
      ->exec('rev-parse --abbrev-ref HEAD')
      ->printOutput(FALSE)
      ->run()
      ->getMessage();
    if ($branch_name == 'HEAD') {
      throw new \Exception('Invalid branch name for ' . $dir);
    }

    $this->taskGit()->dir($dir)->pull('origin', $branch_name)->run();

    $info = Yaml::decode(file_get_contents($info_path));
    $version = str_replace('-dev', '', $info['version']);
    $anchor = fgets(fopen("$dir/CHANGELOG.md", 'r'));

    $log = $this->taskGit()
      ->dir($dir)
      ->exec("log --oneline origin/$base_branch..HEAD")
      ->printOutput(FALSE)
      ->run()
      ->getMessage();

    $log = explode("\n", $log);
    foreach ($log as $key => &$line) {
      $line = preg_replace('/^.*? /', '- ', $line);
      if (strpos(strtolower($line), 'back to dev') !== FALSE) {
        unset($log[$key]);
      }
    }

    if (empty($log)) {
      return;
    }
    $header = [
      $version,
      str_repeat('-', 80),
      '_Release Date: ' . date('Y-m-d') . '_',
      PHP_EOL,
    ];

    $this->taskChangelog("$dir/CHANGELOG.md")
      ->version($version)
      ->setHeader(implode(PHP_EOL, $header))
      ->setBody(implode("\n", $log) . PHP_EOL)
      ->anchor($anchor)
      ->run();
    $composer_diff = $this->taskGit()
      ->dir($dir)
      ->exec("diff origin/$base_branch..HEAD -- composer.json")
      ->printOutput(FALSE)
      ->run()
      ->getMessage();

    $matches = preg_grep('/^-.*su-.*?$/', explode("\n", $composer_diff));
    foreach ($matches as $match) {
      preg_match('/(su-[a-z]+\/[a-z_-]+)/', $match, $matched_packages);
      $this->taskComposerRequire()
        ->dir($dir)
        ->arg($matched_packages[1])
        ->option('no-update')
        ->run();
    }

    $finder = new Finder();
    $finder->in($dir)
      ->files()
      ->name('*.info.yml');

    foreach ($finder as $dir) {
      $info_file = $dir->getRealPath();
      $contents = file_get_contents($info_file);
      file_put_contents($info_file, preg_replace('/version: (.*)-dev/', 'version: $1', $contents));
    }

    $release_version = str_replace('x-', '', $version);
    $this->taskGit()
      ->dir($dir)
      ->checkout("-b release-$release_version")
      ->add($dir)
      ->commit($release_version)
      ->push('origin', "release-$release_version")
      ->run();
    sleep(1);
    $this->taskExec("gh pr create -B $base_branch --title $release_version --body $version")
      ->dir($dir)
      ->run();
  }

}
