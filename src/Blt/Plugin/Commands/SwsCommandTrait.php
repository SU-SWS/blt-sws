<?php

namespace Sws\BltSws\Blt\Plugin\Commands;

use AcquiaCloudApi\Connector\Connector;
use AcquiaCloudApi\Endpoints\Applications;
use AcquiaCloudApi\Endpoints\Environments;
use AcquiaCloudApi\Endpoints\Servers;
use AcquiaCloudApi\Connector\Client;
use Zend\Stdlib\Glob;

/**
 * Trait CardinalTrait.
 *
 * Commmonly used methods used in our custom BLT commands.
 *
 * @package Acquia\Blt\Custom\Commands
 */
trait SwsCommandTrait {

  /**
   * Acquia applications API.
   *
   * @var \AcquiaCloudApi\Endpoints\Applications
   *
   * @link https://github.com/typhonius/acquia-php-sdk-v2
   */
  protected $acquiaApplications;

  /**
   * Acquia environments API.
   *
   * @var \AcquiaCloudApi\Endpoints\Environments
   *
   * @link https://github.com/typhonius/acquia-php-sdk-v2
   */
  protected $acquiaEnvironments;

  /**
   * Acquia servers API.
   *
   * @var \AcquiaCloudApi\Endpoints\Servers
   *
   * @link https://github.com/typhonius/acquia-php-sdk-v2
   */
  protected $acquiaServers;

  /**
   * Recursive glob.
   *
   * @param string $pattern
   *   Glob pattern.
   * @param int $flags
   *   Globl flags.
   *
   * @return array|void
   *   Response from glob.
   */
  protected function rglob($pattern, $flags = 0) {
    $files = Glob::glob($pattern, $flags);
    foreach (Glob::glob(dirname($pattern) . '/*', Glob::GLOB_ONLYDIR | Glob::GLOB_NOSORT) as $dir) {
      $files = array_merge($files, $this->rglob($dir . '/' . basename($pattern), $flags));
    }
    return $files;
  }

  /**
   * Ask a question to the user.
   *
   * @param string $question
   *   The question to ask.
   * @param string $default
   *   Default value.
   * @param bool $required
   *   If a response is required.
   *
   * @return string
   *   Response to the question.
   */
  protected function askQuestion($question, $default = '', $required = FALSE) {
    if ($default) {
      $response = $this->askDefault($question, $default);
    }
    else {
      $response = $this->ask($question);
    }
    if ($required && !$response) {
      return $this->askQuestion($question, $default, $required);
    }
    return $response;
  }

  /**
   * Perform some tasks to prepare the drupal environment.
   *
   * @return \Robo\Contract\TaskInterface[]
   *   List of tasks to set up site.
   */
  protected function setupSite() {
    $tasks[] = $this->waitForDatabase();
    $tasks[] = $this->taskExec('apachectl stop; apachectl start');

    return $tasks;
  }

  /**
   * Waits for the database service to be ready.
   *
   * @return \Robo\Contract\TaskInterface
   *   A task instance.
   */
  protected function waitForDatabase() {
    return $this->taskExec('dockerize -wait tcp://' . $this->getConfigValue('drupal.db.host') . ':3306 -timeout 1m');
  }

  /**
   * Return BLT.
   *
   * @return \Robo\Task\Base\Exec
   *   A drush exec command.
   */
  protected function blt() {
    return $this->taskExec($this->getConfigValue('repo.root') . '/vendor/bin/blt')
      ->option('no-interaction');
  }

  /**
   * Creates a task to do a site-install with Drush, with given profile.
   *
   * @param string $profile
   *   The profile you wish to install from. Default is 'minimal'.
   *
   * @return \Acquia\Blt\Robo\Tasks\DrushTask
   *   A configured drush task
   */
  protected function drushInstall($profile = 'minimal') {
    return $this->taskDrush()
      ->drush('site-install')
      ->args($profile)
      ->option('verbose')
      ->option('yes');
  }

  /**
   * Exec() wrapper to return multiline output of a command as a string.
   *
   * @param string $cmd
   *   The shell command to execute.
   *
   * @return string
   *   The output of the shell command execution
   *
   * @throws \Exception
   */
  protected function execWithMessage($cmd) {
    $output = '';
    $diff_msg = [];

    exec($cmd, $diff_msg, $status);

    if ((int) $status == 0) {
      foreach ($diff_msg as $line) {
        $output .= $line . PHP_EOL;
      }
      return $output;
    }
    else {
      throw new \Exception('CardinalTrait:execWithMessage() returned a status of ' . $status . ' when it tried to execute a command.');
    }

  }

  /**
   * Git the information of the github remote.
   *
   * @return array
   *   Keyed array with github owner and name.
   */
  protected function getGitHubInfo() {
    $git_remote = exec('git config --get remote.origin.url');
    $git_remote = str_replace('.git', '', $git_remote);
    if (strpos($git_remote, 'https') !== FALSE) {
      $parsed_url = parse_url($git_remote);
      list($owner, $repo_name) = explode('/', trim($parsed_url['path'], '/'));
      return ['owner' => $owner, 'name' => $repo_name];
    }
    list(, $repo_name) = explode(':', $git_remote);
    str_replace('.git', '', $git_remote);

    list($owner, $repo_name) = explode('/', $repo_name);
    return ['owner' => $owner, 'name' => $repo_name];
  }

  /**
   * Tests CloudAPI client authentication credentials.
   *
   * @param string $key
   *   The Acquia token public key.
   * @param string $secret
   *   The Acquia token secret key.
   *
   * @throws \Acquia\Blt\Robo\Exceptions\BltException
   */
  protected function setCloudApiClient($key, $secret) {
    try {
      $connector = new Connector([
        'key' => $key,
        'secret' => $secret,
      ]);
      $cloud_api = Client::factory($connector);

      $this->acquiaApplications = new Applications($cloud_api);
      $this->acquiaEnvironments = new Environments($cloud_api);
      $this->acquiaServers = new Servers($cloud_api);

      // We must call some method on the client to test authentication.
      $this->acquiaApplications->getAll();
    }
    catch (\Exception $e) {
      throw new BltException("Unknown exception while connecting to Acquia Cloud: " . $e->getMessage());
    }
  }

}
