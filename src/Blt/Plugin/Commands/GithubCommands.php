<?php

namespace Sws\BltSws\Blt\Plugin\Commands;

use Robo\ResultData;
use Acquia\Blt\Robo\BltTasks;
use Symfony\Component\Console\Input\InputOption;

/**
 * Defines commands in the "github" namespace.
 */
class GithubCommands extends BltTasks {

  use SwsCommandTrait;

  /**
   * Get the base branch of this repo.
   *
   * @command sws:github:base-branch
   *
   * @return \Robo\Result
   *   Message contains the base branch for the repo.
   */
  public function getBaseBranchCommand() {
    return $this->taskExec("git remote show origin | grep 'HEAD branch' | cut -d' ' -f5")->run();
  }

  /**
   * Checks the working tree in the current directory for changes.
   *
   * @return bool
   *   false if changes are present, true if working tree is clean
   */
  public static function workingTreeClean() {
    return (strpos(self::execWithMessage('git status'), 'nothing to commit') > 0);
  }

  /**
   * Get the origin of this repo.
   *
   * @command sws:github:origin
   *
   * @return \Robo\Result
   *   Message contains the repo origin.
   */
  public function getGithubOrigin() {
    return $this->taskExec('git config --get remote.origin.url')->run();
  }

  /**
   * Returns a message about the diff between the working tree and base branch.
   *
   * Useful for making PRs, etc.
   *
   * @command sws:github:diff
   *
   * @return \Robo\Result
   *   Message contains diff information.
   */
  public function diffFromBase() {
    $base_branch = $this->blt()->arg('sws:github:base-branch')->printOutput(FALSE)->run()->getMessage();
    return $this->taskExec('git request-pull ' . $base_branch . ' ./')->run();
  }

  /**
   * Returns a markdown-formatted pull request message.
   *
   * @command sws:github:pr-message
   *
   * @param string $title
   *   A title for the pull request.
   * @param string $changes
   *   A string containing information to include.
   *
   * @return \Robo\ResultData
   *   A markdown-formatted string suitable for a github pull request message.
   */
  public function getPrMessage($title = '', $changes = '') {
    $template = <<<'MESSAGE'
# READY FOR REVIEW

# Summary
- Automated pull request created via BLT

# Review By (Date)
- As soon as possible

# Urgency
- This is a maintenance pull request

# Associated Issues and/or People
- This PR contains automated updates to keep the stack up to date.
- Attention: @jbickar @sherakama @pookmish @imonroe @boznik
MESSAGE;

    $output = $title . PHP_EOL . PHP_EOL;
    $output .= $template . PHP_EOL . PHP_EOL;
    $output .= $changes . PHP_EOL;
    $composer_diff = $this->blt()->arg('sws:github:composer-diff')->printOutput(FALSE)->run();
    if ($composer_diff->wasSuccessful()) {
      $output .= PHP_EOL . $composer_diff->getMessage();
    }
    $outdated = $this->blt()
      ->rawArg('cardinalsites:outdated')
      ->printOutput(FALSE)
      ->run();

    if (!$outdated->wasSuccessful()) {
      $output .= 'The following Drupal or SWS packages are outdated: ' . PHP_EOL;
      $output .= $outdated->getMessage() . PHP_EOL;
    }
    $pr_message = str_replace("'", "", $output);
    $this->say($pr_message);
    return new ResultData(0, $pr_message);
  }

  /**
   * Returns a markdown version of composer differences.
   *
   * @command sws:github:composer-diff
   *
   * @return \Robo\ResultData
   *   Message contains diff information.
   */
  public function composerLockDiff() {
    if (is_readable($this->getConfigValue('repo.root') . '/composer.lock')) {
      return $this->taskExec('vendor/bin/composer-lock-diff --md')->run();
    }
    return new ResultData(1, 'GithubCommands: no composer.lock file in current directory.');
  }

  /**
   * Update repo based on current working tree.
   *
   * Here, we have a function which will accept a path containing a git repo,
   * a new branch name, and will :
   *  - create the new branch if necessary
   *  - stage the changes
   *  - commit the changes to the new branch
   *  - push the new branch to github
   *  - optionally open a pull request in the associated repo
   *    containing the changes from the base branch.
   *
   * @param string $directory
   *   The path to the directory which contains your repo.
   * @param array $opts
   *   An array of options.
   *
   * @command sws:github:update-from-dir
   *
   * @option $create-pr Set this flag to create a pull request from the new branch.
   * @option $new-branch Name of the new branch to create
   * @option $base-branch Name of the base branch to compare with
   *
   * @return \Robo\Result
   *   The result of the command.
   *
   * @throws \Exception
   */
  public function updateGithubFromDirectory($directory, array $opts = ['new-branch' => InputOption::VALUE_REQUIRED, 'base-branch' => NULL, 'create-pr' => FALSE]) {
    if (!is_dir($directory)) {
      throw new \Exception('GithubCommands: Could not find the directory specified.');
    }
    $original_working_directory = getcwd();
    $this->say('Beginning to update repo in: ' . $directory);
    chdir($directory);

    if (self::workingTreeClean()) {
      $msg = 'Working tree clean, nothing to do.';
      $this->say($msg);
      return new ResultData(0, $msg);
    }

    if ($opts['base-branch']) {
      $base_branch = $opts['base-branch'];
    }
    else {
      $base_branch = $this->blt()
        ->arg('sws:github:base-branch')
        ->printOutput(FALSE)
        ->run()
        ->getMessage();
    }

    $collection = $this->collectionBuilder();

    $update_task = $this->taskGitStack()
      ->stopOnFail()
      ->exec('checkout -b ' . $opts['new-branch'])
      ->add('.')
      ->commit('Automated commit from CircleCi')
      ->push('origin', $opts['new-branch']);
    $collection->addTask($update_task);

    if ($opts['create-pr']) {
      $diff_message = $this->blt()
        ->arg('sws:github:diff')
        ->printOutput(FALSE)
        ->run()
        ->getMessage();
      $pr_message = $this->blt()
        ->arg("github:pr-message '" . $opts['new-branch'] . "' '" . $diff_message . "'")
        ->printOutput(FALSE)
        ->run()
        ->getMessage();
      $collection->addTask($this->taskExec('hub')->arg('pull-request')
        ->option('base', $base_branch)
        ->option('message', $pr_message));
    }

    $result = $collection->run();
    chdir($original_working_directory);
    return $result;

  }

  /**
   * Sets the name and email address that Git will use.
   *
   * @param string $name
   *   The name you would like git to use. Optional.
   * @param string $email
   *   The email address you would like git to use. Optional.
   *
   * @command sws:github:set-user
   * @description Configures git to use standard SWS name and email
   *
   * @return \Robo\Result
   *   The result of the collection of tasks.
   */
  public function setGithubName($name = 'CircleCI', $email = 'sws-developers@lists.stanford.edu') {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      throw new \Exception('GithubCommands: email address is not valid.');
    }
    $collection = $this->collectionBuilder();
    $collection->addTask($this->taskGitStack()->exec(['config', '--global user.email "' . $email . '"']));
    $collection->addTask($this->taskGitStack()->exec(['config', '--global user.name "' . $name . '"']));
    return $collection->run();
  }

  /**
   * Returns the latest tag from github or an exception if it can't get one.
   *
   * @command sws:github:latest-tag
   * @description Returns the latest tag from Github
   *
   * @return \Robo\ResultData
   *   The result of the collection of tasks.
   */
  public function getLatestTag() {
    $tag_strings = $this->taskGitStack()
      ->stopOnFail()
      ->exec('tag --list --sort=-creatordate')
      ->printOutput(FALSE)
      ->run()
      ->getMessage();

    $tag_array = explode("\n", $tag_strings);
    if (!empty($tag_array[0])) {
      $this->say($tag_array[0]);
      return new ResultData(0, $tag_array[0]);
    }
    else {
      return new ResultData(1, 'GithubCommands: tag array was empty.');
    }
  }

  /**
   * Takes a semver tag and an increment, and returns the next tag.
   *
   * @param string $current_tag
   *   The current semver compliant tag.
   * @param string $increment
   *   The increment to use--'patch', 'minor', 'major'.
   *
   * @command sws:github:next-tag
   * @description Takes a semver tag, increments it.
   *
   * @return \Robo\ResultData
   *   The result of the collection of tasks.
   */
  public function incrementTag($current_tag, $increment) {
    $new_tag = $this->taskSemVer()
      ->version($current_tag)
      ->increment($increment)
      ->setFormat('%M.%m.%p%s')
      ->__toString();
    $this->say($new_tag);
    return new ResultData(0, $new_tag);
  }

  /**
   * Takes a github commit hash, returns a changelog message.
   *
   * @param string $hash
   *   The commit hash to process.
   *
   * @command sws:github:changes-from-hash
   * @description Takes a commit hash, returns a changlog message.
   *
   * @return \Robo\ResultData
   *   The result of the collection of tasks.
   */
  public function msgFromHash($hash) {
    $msg = $this->taskExec('git log')
      ->option('format=%B -n')
      ->arg('1')
      ->arg($hash)
      ->printOutput(FALSE)
      ->run()
      ->getMessage();
    echo $msg;
    return new ResultData(0, $msg);
  }

}
