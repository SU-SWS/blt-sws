<?php

namespace Sws\BltSws\Blt\Plugin\Commands;

use Acquia\Blt\Robo\BltTasks;
use Codeception\Task\Filter\GroupFilter;
use Codeception\Task\Merger\ReportMerger;
use Codeception\Task\Splitter\TestsSplitterTrait;
use Robo\ResultData;

/**
 * Testing command for codeception and phpunit.
 *
 * @package Example\Blt\Plugin\Commands
 */
class CodeceptionParallelCommands extends BltTasks {

  use ReportMerger;
  use TestsSplitterTrait;
  use SwsCommandTrait;

  const NUMBER_OF_GROUPS = 6;

  /**
   * @command codeception:parallel-split
   */
  public function parallelSplitTests() {
    $failed_test = NULL;
    $root = $this->getConfigValue('repo.root');
    $tests = $this->getConfigValue('tests.codeception', []);

    foreach ($tests as $test) {
      $test_directory = $test['directory'];

      // Run each suite of tests.
      foreach ($test['suites'] as $suite) {
        if (!file_exists("$test_directory/$suite/")) {
          return new ResultData(ResultData::EXITCODE_OK, 'No tests to execute for suite ' . $suite);
        }


        if (!file_exists("$root/tests/codeception.yml")) {
          $this->taskFilesystemStack()
            ->copy("$root/tests/codeception.dist.yml", "$root/tests/codeception.yml")
            ->run();
          $this->getConfig()
            ->expandFileProperties("$root/tests/codeception.yml");
        }

        $new_test_dir = "$root/tests/codeception/$suite/symlink/";
        $temp_directories[] = $new_test_dir;
        if (file_exists(dirname($new_test_dir))) {
          $this->taskDeleteDir(dirname($new_test_dir))->run();
        }
        $this->taskFilesystemStack()->mkdir($new_test_dir)->run();
        $this->taskRsync()
          ->fromPath("$test_directory/$suite/")
          ->toPath($new_test_dir)
          ->recursive()
          ->run();
      }
    }

    $this->taskSplitTestFilesByGroups(self::NUMBER_OF_GROUPS)
      ->projectRoot("$root/tests")
      ->testsFrom('codeception')
      ->groupsTo("$root/tests/codeception/_data/paracept_")
      ->run();
  }

  /**
   * @command codeception:parallel
   */
  public function parallelRun() {
    $this->parallelSplitTests();

    $parallel = $this->taskParallelExec();
    for ($i = 1; $i <= self::NUMBER_OF_GROUPS; $i++) {
      $parallel->process(
        $this->taskCodecept()
          ->configFile('tests')
          ->group("paracept_$i")
          ->excludeGroup('no-parallel')
          ->suite('acceptance')
          ->option('steps')
          ->option('override', 'paths: output: ../artifacts/acceptance', '=')
          ->option('override', "extensions: config: Codeception\Extension\RunFailed: fail-group: failed_$i")
          ->html("_log/html/result_$i.html")
          ->xml("_log/xml/result_$i.xml")
      );
    }
    $parallel_result = $parallel->run();
    $no_parallel_result = $this->taskCodecept()
      ->configFile('tests')
      ->group('no-parallel')
      ->suite('acceptance')
      ->option('steps')
      ->option('override', 'paths: output: ../artifacts/acceptance', '=')
      ->html('_log/html/no-parallel.html')
      ->xml('_log/xml/no-parallel.xml')
      ->run();

    $this->parallelMergeResults();
    if (!$parallel_result->wasSuccessful() || !$no_parallel_result->wasSuccessful()) {
      $this->say('Retrying failed tests');
      $no_parallel_result = $this->taskCodecept()
        ->configFile('tests')
        ->group('failed')
        ->suite('acceptance')
        ->option('steps')
        ->option('override', 'paths: output: ../artifacts/acceptance', '=')
        ->html('_log/html/retry.html')
        ->xml('_log/xml/retry.xml')
        ->run();
      $this->parallelMergeResults();
      return $no_parallel_result;
    }
    return $no_parallel_result->wasSuccessful() ? $parallel_result : $no_parallel_result;
  }

  /**
   * @command codeception:parallel-merge-results
   */
  public function parallelMergeResults() {
    $root = $this->getConfigValue('repo.root');
    $xml_merge = $this->taskMergeXmlReports();
    $html_merge = $this->taskMergeHTMLReports();
    $failed_merge = $this->taskMergeFailedTestsReports();

    for ($i = 1; $i <= self::NUMBER_OF_GROUPS; $i++) {
      $xml_merge->from("artifacts/acceptance/_log/xml/result_$i.xml");
      $html_merge->from("artifacts/acceptance/_log/html/result_$i.html");
      $failed_merge->from("artifacts/acceptance/failed_$i");
    }

    $xml_merge->from('artifacts/acceptance/_log/xml/no-parallel.xml');
    $html_merge->from('artifacts/acceptance/_log/html/no-parallel.html');
    $failed_merge->from("artifacts/acceptance/failed");

    $xml_merge->into('artifacts/acceptance/result.xml')->run();
    $html_merge->into('artifacts/acceptance/result.html')->run();
    $failed_merge->into('artifacts/acceptance/failed')->run();
  }

}
