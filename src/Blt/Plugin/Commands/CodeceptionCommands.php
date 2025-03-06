<?php

namespace Sws\BltSws\Blt\Plugin\Commands;

use Acquia\Blt\Robo\BltTasks;
use Acquia\Blt\Robo\Exceptions\BltException;
use Robo\ResultData;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Testing command for codeception and phpunit.
 *
 * @package Example\Blt\Plugin\Commands
 */
class CodeceptionCommands extends BltTasks {

  /**
   * Fail fast option.
   *
   * @var int
   */
  protected $failFast;

  /**
   * Run all the codeception tests defined in blt.yml.
   *
   * @param array $options
   *   Keyed array of command options.
   *
   * @return \Robo\Result
   *   Result of the test.
   *
   * @command tests:codeception:run
   * @aliases tests:codeception codeception
   * @options test The key of the tests to run.
   * @options suite only run a specific suite instead of all suites.
   * @options stop after nth failure (defaults to 1)
   */
  public function runCodeceptionTests(array $options = [
    'test' => NULL,
    'suite' => NULL,
    'group' => NULL,
    'fail-fast' => NULL,
  ])
  {
    $failed_test = NULL;

    $tests = $this->getConfigValue('tests.codeception', []);

    $this->failFast = $options['fail-fast'];

    // Run only the test that was defined in the options.
    if (!empty($options['test'])) {
      $tests = [$options['test'] => $tests[$options['test']]];
    }

    foreach ($tests as $test) {

      // Filter out the suites that the options doesn't want.
      $test['suites'] = array_filter($test['suites'], function ($suite) use ($options) {
        return empty($options['suite']) || strpos($options['suite'], $suite) !== FALSE;
      });

      // Run each suite of tests.
      foreach ($test['suites'] as $suite) {
        $this->say("Running <comment>$suite</comment> Tests.");
        $test_result = $this->runCodeceptionTestSuite($suite, $test['directory']);

        if (!$test_result->wasSuccessful()) {
          $failed_test = $test_result;
        }
      }
    }
    return $failed_test ?: $test_result;
  }

  /**
   * Execute codeception test suite.
   *
   * @param string $suite
   *   Codeception suite to run.
   * @param string $test_directory
   *   Directory to codeception tests.
   *
   * @return \Robo\Result|\Robo\ResultData
   *   Result of the test.
   */
  protected function runCodeceptionTestSuite($suite, $test_directory) {
    if (!file_exists("$test_directory/$suite/")) {
      return new ResultData(ResultData::EXITCODE_OK, 'No tests to execute for suite ' . $suite);
    }

    $root = $this->getConfigValue('repo.root');
    if (!file_exists("$root/tests/codeception.yml")) {
      $this->taskFilesystemStack()
        ->copy("$root/tests/codeception.dist.yml", "$root/tests/codeception.yml")
        ->run();
      $this->getConfig()
        ->expandFileProperties("$root/tests/codeception.yml");
    }

    $new_test_dir = "$root/tests/codeception/$suite/" . date('Ymd-Hi');
    $tasks[] = $this->taskFilesystemStack()
      ->symlink("$test_directory/$suite/", $new_test_dir);

    $test = $this->taskExec('vendor/bin/codecept')
      ->arg('run')
      ->arg($suite)
      ->option('steps')
      ->option('config', 'tests', '=')
      ->option('override', "paths: output: ../artifacts/$suite", '=')
      ->option('html')
      ->option('xml');

    if (getenv('CI')) {
      $test->option('env', 'ci', '=');
    }

    if ($this->failFast) {
      $test->option('fail-fast', $this->failFast, '=');
    }

    if ($group = $this->input()->getOption('group')) {
      $test->option('group', $group, '=');
    }

    if ($this->input()->getOption('verbose')) {
      $test->option('debug');
      $test->option('verbose');
    }
    $tasks[] = $test;
    $test_result = $this->collectionBuilder()->addTaskList($tasks)->run();
    // Regardless if the test failed or succeeded, always clean up the temporary
    // test directory.
    $this->taskDeleteDir($new_test_dir)->run();

    // Delete the failed file because codeception will try to look for the file
    // that failed again on the next run. Since we have temporary test
    // directories we don't want to save that data.
    foreach (glob("$root/artifacts/*/failed") as $file) {
      $this->taskFilesystemStack()
        ->remove($file)
        ->run();
    }

    return $test_result;
  }
}
