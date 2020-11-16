<?php

namespace Sws\BltSws\Blt\Plugin\Commands;

use Acquia\Blt\Robo\BltTasks;

/**
 * Defines commands in the "circleci" namespace.
 */
class CircleCiCommands extends BltTasks {

  use SwsCommandTrait;

  /**
   * Command to run unit tests.
   *
   * @return \Robo\Result
   *   The result of the collection of tasks.
   *
   * @command sws:circleci:phpunit
   */
  public function circleCiPhpUnit() {
    $collection = $this->collectionBuilder();
    $collection->addTaskList($this->setupSite());
    $collection->addTask($this->drushInstall());
    $collection->addTask($this->blt()->arg('tests:phpunit:run'));
    return $collection->run();
  }

  /**
   * Command to run unit coverage tests.
   *
   * @return \Robo\Result
   *   The result of the collection of tasks.
   *
   * @command sws:circleci:phpunit:coverage
   * @description Runs phpunit coverage
   */
  public function circleCiPhpUnitCoverage() {
    $collection = $this->collectionBuilder();
    $collection->addTaskList($this->setupSite());
    $collection->addTask($this->drushInstall());
    $collection->addTask($this->blt()->arg('tests:phpunit:coverage:run'));
    return $collection->run();
  }

  /**
   * Command to codeception tests.
   *
   * @param string $test
   *   Codeception test key defined in blt.yml.
   *
   * @return \Robo\Result
   *   The result of the collection of tasks.
   *
   * @command sws:circleci:codeception
   * @description Runs codeception tests.
   */
  public function circleCiCodeCeption($test) {
    $collection = $this->collectionBuilder();
    $collection->addTaskList($this->setupSite());
    $collection->addTask($this->drushInstall());
    $collection->addTask($this->blt()->arg('tests:codeception:run')->arg($test));
    return $collection->run();
  }

  /**
   * Setup drupal and install the profile configured in the blt.yml.
   *
   * @command sws:circleci:setup
   */
  public function circleCiSetup() {
    $collection = $this->collectionBuilder();
    $collection->addTaskList($this->setupSite());
    $collection->addTask($this->blt()->arg('drupal:install'));
    $collection->addTask($this->taskDrush()
      ->drush('pm-uninstall')
      ->rawArg('stanford_ssp simplesamlphp_auth'));
    // The following drush task is necessary because we are disabling sitemapxml
    // generation during cron. The tests complete successfully in the profile,
    // but this workaround is needed for the stack.
    // @link https://github.com/SU-SWS/acsf-cardinalsites/blob/1.x/docroot/sites/settings/xmlsitemap.settings.php#L7
    $collection->addTask($this->taskDrush()->drush('xmlsitemap-regenerate'));
    return $collection->run();
  }

  /**
   * Automatically update dependencies for stack and profile.
   *
   * @command sws:circleci:update-dependencies
   * @description Update dependencies automatically.
   * @return \Robo\Result
   *   The result of the collection of tasks.
   */
  public function circleCiUpdateDependencies() {
    $new_branch = 'dependency_update_' . date('Y-m-d_h-i', time());
    $docroot = $this->getConfigValue('docroot');

    $collection = $this->collectionBuilder();
    $collection->addTask($this->blt()->arg('sws:github:set-user'));
    $collection->addTaskList($this->setupSite());
    $collection->addTask($this->blt()->arg('drupal:install'));

    // Composer updates for the root directory.
    $collection->addTask($this->taskComposerUpdate()->noInteraction());

    // Run database updates.
    $collection->addTask($this->taskDrush()->drush('updatedb'));

    // Cache clear.
    $collection->addTask($this->taskDrush()->drush('cache:rebuild'));

    // Drush csex.
    $collection->addTask($this->taskDrush()->drush('config-split:export'));

    // acsf:init:drush.
    $collection->addTask($this->blt()->arg('recipes:acsf:init:drush'));

    // Update the profile repo with any config changes as a result of updates.
    $collection->addTask($this->blt()->rawArg('sws:github:update-from-dir ' . $docroot . '/profiles/custom/stanford_profile')
      ->option('new-branch', $new_branch)
      ->option('create-pr'));

    // Update the stack repo as needed.
    $collection->addTask($this->blt()->rawArg('sws:github:update-from-dir ' . $this->getConfigValue('repo.root'))
      ->option('new-branch', $new_branch)
      ->option('create-pr'));

    return $collection->run();
  }

  /**
   * Auto-tag and release.
   *
   * @command sws:circleci:tag-and-release
   * @description Automatically tag and release a commit.
   *
   * @return \Robo\Result
   *   The result of the collection of tasks
   *
   * @throws \Exception
   */
  public function tagAndRelease($increment = 'patch') {
    // Get the last tag.
    $last_version = $this->blt()
      ->arg('sws:github:latest-tag')
      ->printOutput(FALSE)
      ->run()
      ->getMessage();

    // Calculate the next tag.
    $next_version = $this->blt()
      ->arg('sws:github:next-tag')
      ->arg($last_version)
      ->arg($increment)
      ->printOutput(FALSE)
      ->run()
      ->getMessage();
    $this->yell("Releasing $next_version");

    // Get a list of commit hashes from the log.
    $commit_hashes = $this->taskExec('git log')
      ->option('pretty=format:%h')
      ->arg($last_version . '...HEAD')
      ->printOutput(FALSE)
      ->run()
      ->getMessage();

    $this->say($commit_hashes);
    $commit_array = explode(PHP_EOL, $commit_hashes);

    // Turn the commit hashes into an array of changes.
    $changes = [];
    foreach ($commit_array as $hash) {
      $message = $this->blt()
        ->arg('sws:github:changes-from-hash')
        ->arg($hash)
        ->printOutput(FALSE)
        ->run()
        ->getMessage();
      $changes[] = $message . " ($hash)";
      $this->say($message . " ($hash)");
    }

    $github_info = $this->getGitHubInfo();

    $tasks = [];
    $tasks[] = $this->blt()->arg('sws:github:set-user');
    $tasks[] = $this->taskGitHubRelease($next_version)
      ->accessToken(getenv('GITHUB_TOKEN'))
      ->uri($github_info['owner'] . '/' . $github_info['name'])
      ->description("Release $next_version" . PHP_EOL)
      ->changes($changes)
      ->name($next_version)
      ->comittish(getenv('CIRCLE_BRANCH'));

    $collection = $this->collectionBuilder()->addTaskList($tasks);
    return $collection->run();

  }

}
