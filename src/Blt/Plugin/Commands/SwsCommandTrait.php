<?php

namespace Sws\BltSws\Blt\Plugin\Commands;

use Acquia\Blt\Robo\Common\YamlMunge;
use Acquia\Blt\Robo\Exceptions\BltException;
use AcquiaCloudApi\Connector\Connector;
use AcquiaCloudApi\Endpoints\Applications;
use AcquiaCloudApi\Endpoints\DatabaseBackups;
use AcquiaCloudApi\Endpoints\Databases;
use AcquiaCloudApi\Endpoints\Domains;
use AcquiaCloudApi\Endpoints\Environments;
use AcquiaCloudApi\Endpoints\Notifications;
use AcquiaCloudApi\Endpoints\Servers;
use AcquiaCloudApi\Connector\Client;
use AcquiaCloudApi\Endpoints\SslCertificates;
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
   * App id.
   *
   * @var string
   */
  protected $appId;

  /**
   * Cloud config dir.
   *
   * @var string
   */
  protected $cloudConfDir;

  /**
   * Cloud config filename.
   *
   * @var string
   */
  protected $cloudConfFileName;

  /**
   * Cloud config file path.
   *
   * @var string
   */
  protected $cloudConfFilePath;

  /**
   * @var 
   */
  protected $acquiaApi;

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
   * Acquia Database API.
   *
   * @var \AcquiaCloudApi\Endpoints\Databases
   *
   * @link https://github.com/typhonius/acquia-php-sdk-v2
   */
  protected $acquiaDatabases;

  /**
   * Acquia Database Backups API.
   *
   * @var \AcquiaCloudApi\Endpoints\DatabaseBackups
   *
   * @link https://github.com/typhonius/acquia-php-sdk-v2
   */
  protected $acquiaDatabaseBackups;

  /**
   * Acquia Domains API.
   *
   * @var \AcquiaCloudApi\Endpoints\Domains
   *
   * @link https://github.com/typhonius/acquia-php-sdk-v2
   */
  protected $acquiaDomains;

  /**
   * Acquia Cert API.
   *
   * @var \AcquiaCloudApi\Endpoints\SslCertificates
   */
  protected $acquiaCertificates;

  /**
   * Acquia Notifications API.
   *
   * @var \AcquiaCloudApi\Endpoints\Notifications
   *
   * @link https://github.com/typhonius/acquia-php-sdk-v2
   */
  protected $acquiaNotifications;

  /**
   * @throws \Acquia\Blt\Robo\Exceptions\BltException
   */
  protected function connectAcquiaApi(){
    $this->cloudConfDir = $_SERVER['HOME'] . '/.acquia';
    $this->setAppId();
    $this->cloudConfFileName = 'cloud_api.conf';
    $this->cloudConfFilePath = $this->cloudConfDir . '/' . $this->cloudConfFileName;

    $this->say('<info>Establishing connection to Acquia API</info>');
    $cloudApiConfig = $this->loadCloudApiConfig();
    $this->setCloudApiClient($cloudApiConfig['key'], $cloudApiConfig['secret']);
  }

  /**
   * Sets the Acquia application ID from config and prompt.
   */
  protected function setAppId() {
    if ($app_id = $this->getConfigValue('cloud.appId')) {
      $this->appId = $app_id;
    }
    else {
      $this->say("<info>To generate an alias for the Acquia Cloud, BLT requires your Acquia Cloud application ID.</info>");
      $this->say("<info>See https://docs.acquia.com/acquia-cloud/manage/applications.</info>");
      $this->appId = $this->askRequired('Please enter your Acquia Cloud application ID');
      $this->writeAppConfig($this->appId);
    }
  }


  /**
   * Sets appId value in blt.yml to disable interative prompt.
   *
   * @param string $app_id
   *   The Acquia Cloud application UUID.
   *
   * @throws \Acquia\Blt\Robo\Exceptions\BltException
   */
  protected function writeAppConfig($app_id) {

    $project_yml = $this->getConfigValue('blt.config-files.project');
    $this->say("Updating $project_yml...");
    $project_config = YamlMunge::parseFile($project_yml);
    $project_config['cloud']['appId'] = $app_id;
    try {
      YamlMunge::writeFile($project_yml, $project_config);
    }
    catch (\Exception $e) {
      throw new BltException("Unable to update $project_yml.");
    }
  }

  /**
   * Loads CloudAPI token from an user input if it doesn't exist on disk.
   *
   * @return array
   *   An array of CloudAPI token configuration.
   */
  protected function loadCloudApiConfig() {
    if (!$config = $this->loadCloudApiConfigFile()) {
      $config = $this->askForCloudApiCredentials();
    }
    return $config;
  }

  /**
   * Load existing credentials from disk.
   *
   * @return bool|array
   *   Returns credentials as array on success, or FALSE on failure.
   */
  protected function loadCloudApiConfigFile() {
    if (file_exists($this->cloudConfFilePath)) {
      return (array) json_decode(file_get_contents($this->cloudConfFilePath));
    }
    else {
      return FALSE;
    }
  }

  /**
   * Interactive prompt to get Cloud API credentials.
   *
   * @return array
   *   Returns credentials as array on success.
   *
   * @throws \Acquia\Blt\Robo\Exceptions\BltException
   */
  protected function askForCloudApiCredentials() {
    $this->say("You may generate new API tokens at <comment>https://cloud.acquia.com/app/profile/tokens</comment>");
    $key = $this->askRequired('Please enter your Acquia cloud API key:');
    $secret = $this->askRequired('Please enter your Acquia cloud API secret:');

    // Attempt to set client to check credentials (throws exception on failure).
    $this->setCloudApiClient($key, $secret);

    $config = [
      'key' => $key,
      'secret' => $secret,
    ];
    $this->writeCloudApiConfig($config);
    return $config;
  }

  /**
   * Writes configuration to local file.
   *
   * @param array $config
   *   An array of CloudAPI configuraton.
   */
  protected function writeCloudApiConfig(array $config) {
    if (!is_dir($this->cloudConfDir)) {
      mkdir($this->cloudConfDir);
    }
    file_put_contents($this->cloudConfFilePath, json_encode($config));
    $this->say("Credentials were written to {$this->cloudConfFilePath}.");
  }

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
      [$owner, $repo_name] = explode('/', trim($parsed_url['path'], '/'));
      return ['owner' => $owner, 'name' => $repo_name];
    }
    [, $repo_name] = explode(':', $git_remote);
    str_replace('.git', '', $git_remote);

    [$owner, $repo_name] = explode('/', $repo_name);
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
      $this->acquiaApi  = Client::factory($connector);
      
      $this->acquiaApplications = new Applications($this->acquiaApi);
      $this->acquiaEnvironments = new Environments($this->acquiaApi);
      $this->acquiaServers = new Servers($this->acquiaApi);
      $this->acquiaDatabases = new Databases($this->acquiaApi);
      $this->acquiaDatabaseBackups = new DatabaseBackups($this->acquiaApi);
      $this->acquiaDomains = new Domains($this->acquiaApi);
      $this->acquiaCertificates = new SslCertificates($this->acquiaApi);
      $this->acquiaNotifications = new Notifications($this->acquiaApi);

      // We must call some method on the client to test authentication.
      $this->acquiaApplications->getAll();
    }
    catch (\Exception $e) {
      throw new BltException("Unknown exception while connecting to Acquia Cloud: " . $e->getMessage());
    }
  }

}
