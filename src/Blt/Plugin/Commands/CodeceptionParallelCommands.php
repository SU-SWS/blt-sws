<?php

namespace Sws\BltSws\Blt\Plugin\Commands;

use Acquia\Blt\Robo\BltTasks;
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

        $new_test_dir = "$root/tests/codeception/$suite/symlink/" . date('Ymd-Hi');
        $temp_directories[] = $new_test_dir;
        if (file_exists(dirname($new_test_dir))) {
          $this->taskDeleteDir(dirname($new_test_dir))->run();
        }
        $this->taskFilesystemStack()->mkdir(dirname($new_test_dir))->run();
        $this->taskRsync()
          ->fromPath("$test_directory/$suite/")
          ->toPath($new_test_dir)
          ->recursive()
          ->run();
      }
    }

    $this->taskSplitTestFilesByGroups(5)
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
    for ($i = 1; $i <= 5; $i++) {
      $parallel->process(
        $this->taskCodecept()
          ->configFile('tests')
          ->group("paracept_$i")
          ->suite('acceptance')
          ->option('xml', "_log/result_$i.xml")
          ->option('fail-fast')
          ->option('steps')
      );
    }
    return $parallel->run();
  }

  /**
   * @command codeception:parallel-merge-results
   */
  public function parallelMergeResults() {
    $root = $this->getConfigValue('repo.root');
    $merge = $this->taskMergeXmlReports();
    for ($i = 1; $i <= 5; $i++) {
      $merge->from("$root/artifacts/_log/result_$i.xml");
    }
    $merge->into("$root/artifacts/_log/result_paracept.xml")->run();
  }

}
