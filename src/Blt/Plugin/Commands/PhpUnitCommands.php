<?php

namespace Sws\BltSws\Blt\Plugin\Commands;

use Acquia\Blt\Robo\Exceptions\BltException;

use Consolidation\AnnotatedCommand\CommandData;
use Drupal\Core\Serialization\Yaml;
use Robo\Result;
use Sws\BltSws\Blt\Plugin\Commands\Testing\PhpUnitCommand;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

/**
 * Testing command for codeception and phpunit.
 *
 * @package Example\Blt\Plugin\Commands
 */
class PhpUnitCommands extends PhpUnitCommand {

  /**
   * Before the phpunit tests run, build the phpunit.xml config file.
   *
   * @hook pre-command tests:phpunit:run
   */
  public function beforePhpUnit() {
    $this->invokeCommand('tests:phpunit:config');
  }

  /**
   * Build the phpunit.xml configuration file.
   *
   * @command tests:phpunit:config
   */
  public function buildPhpUnitConfig() {
    $this->cleanupInfoFiles();
    $root = $this->getConfigValue('repo.root');
    $docroot = $this->getConfigValue('docroot');

    $task = $this->taskFilesystemStack();
    if (!file_exists("$docroot/core/phpunit.xml")) {
      $task->copy("$root/tests/phpunit/example.phpunit.xml", "$docroot/core/phpunit.xml")
        ->run();
      if (empty($this->getConfigValue('drupal.db.password'))) {
        // If the password is empty, remove the colon between the username &
        // password. This prevents the system from thinking its supposed to
        // use a password.
        $file_contents = file_get_contents("$docroot/core/phpunit.xml");
        str_replace(':${drupal.db.password}', '', $file_contents);
        file_put_contents("$docroot/core/phpunit.xml", $file_contents);
      }
      $this->getConfig()->expandFileProperties("$docroot/core/phpunit.xml");
    }
  }

  /**
   * Clean up any info files for test modules that aren't set correctly.
   */
  protected function cleanupInfoFiles() {
    $docroot = $this->getConfigValue('docroot');
    $finder = new Finder();
    $finder->files()->in("$docroot/modules/")->name('*.info.yml');
    foreach ($finder as $file) {
      $absoluteFilePath = $file->getRealPath();
      $info_contents = file_get_contents($absoluteFilePath);
      $info = Yaml::decode($info_contents);

      if (isset($info['core']) && !isset($info['core_version_requirement'])) {
        $info_contents = preg_replace('/core:.*/', 'core_version_requirement: ^8.8 || ^9', $info_contents);
        file_put_contents($absoluteFilePath, $info_contents);
      }
    }
  }

  /**
   * Setup and run PHPUnit tests with code coverage.
   *
   * @command tests:phpunit:coverage:run
   * @aliases tprc phpunit:coverage tests:phpunit:coverage
   * @description Executes all PHPUnit "Unit" and "Kernel" tests with coverage
   *   report.
   *
   * @throws \Exception
   *   Throws an exception if any test fails.
   */
  public function runPhpUnitTestsCoverage() {
    $this->invokeCommand('tests:phpunit:config');
    parent::runPhpUnitTests();
  }

  /**
   * Test the code coverage is good.
   *
   * @hook post-command tests:phpunit:coverage:run
   */
  public function postPhpUnitCoverage($result, CommandData $commandData) {
    $required_pass = $this->getConfigValue('tests.reports.coveragePass');
    if (empty($required_pass)) {
      return;
    }

    $report = $this->reportsDir . '/coverage/xml/index.xml';
    if (!file_exists($report)) {
      throw new \Exception('Coverage report not found at ' . $report);
    }

    libxml_use_internal_errors(TRUE);
    $dom = new \DOMDocument();
    $dom->loadHtml(file_get_contents($report));
    $xpath = new \DOMXPath($dom);

    $coverage_percent = $xpath->query("//directory[@name='/']/totals/lines/@percent");
    $percent = (float) $coverage_percent->item(0)->nodeValue;
    $pass = $this->getConfigValue('tests.reports.coveragePass');
    if ($pass > $percent) {
      throw new \Exception("Test coverage is only at $percent%. $pass% is required.");
    }
    $this->yell(sprintf('Coverage at %s%%. %s%% required.', $percent, $pass));

    $upload = $this->uploadCoverageCodeClimate();
    if (!$upload->wasSuccessful()) {
      return $upload;
    }
    return $result;
  }

  /**
   * Use CodeClimate CLI to upload the phpunit coverage report.
   *
   * @link https://docs.codeclimate.com/docs/circle-ci-test-coverage-example
   */
  public function uploadCoverageCodeClimate(): ?Result {
    $coverage_file = $this->reportsDir . '/coverage/clover.xml';

    if (!file_exists($coverage_file)) {
      $this->say('No coverage to upload to code climate.');
      return NULL;
    }

    $test_reporter_id = getenv('CC_TEST_REPORTER_ID');
    if (!$test_reporter_id) {
      $this->say('To enable codeclimate coverage uploads, please set the "CC_TEST_REPORTER_ID" environment variable to enable this feature.');
      $this->say('This can be found on the codeclimate repository settings page.');
      return NULL;
    }

    $repo = $this->getConfigValue('repo.root');
    // Download the executable.
    $tasks[] = $this->taskExec('curl -L https://codeclimate.com/downloads/test-reporter/test-reporter-latest-linux-amd64 > ./cc-test-reporter')
      ->dir($repo);
    $tasks[] = $this->taskExec(' chmod +x ./cc-test-reporter')
      ->dir($repo);

    // Move the phpunit report into the temp directory.
    $tasks[] = $this->taskFilesystemStack()
      ->copy($coverage_file, $repo . '/clover.xml');

    // Use the CLI to upload the report.
    $tasks[] = $this->taskExec('./cc-test-reporter after-build -t clover')
      ->dir($repo);

    return $this->collectionBuilder()
      ->addTaskList($tasks)
      ->run();
  }

  /**
   * Executes all PHPUnit tests.
   *
   * This method is copied from Acquia BLT command for running phpunit tests.
   * But it adds the coverage options and filters for only Unit and Kernel
   * tests. Running Functional tests takes far too long since XDebug has to
   * listen to the entire bootstrap process.
   *
   * @param array|null $config
   *   Blt phpunit configuration.
   * @param string $report_directory
   *   Local reports directory.
   *
   * @throws \Acquia\Blt\Robo\Exceptions\BltException
   *
   * @see \Acquia\Blt\Robo\Commands\Tests\PhpUnitCommand::executeUnitCoverageTests()
   */
  public function executeTests() {
    if (is_array($this->phpunitConfig)) {
      foreach ($this->phpunitConfig as $test) {
        $task = $this->taskPhpUnitTask()
          ->xml($this->reportFile)
          ->printOutput(TRUE)
          ->printMetadata(FALSE);

        $task->option('coverage-html', $this->reportsDir . '/coverage/html', '=');
        $task->option('coverage-xml', $this->reportsDir . '/coverage/xml', '=');
        $task->option('coverage-clover', $this->reportsDir . '/coverage/clover.xml', '=');

        if (isset($test['path'])) {
          $task->dir($test['path']);
        }

        if ($this->output()
            ->getVerbosity() >= OutputInterface::VERBOSITY_NORMAL) {
          $task->printMetadata(TRUE);
          $task->verbose();
        }

        if (isset($this->testingEnvString)) {
          $task->testEnvVars($this->testingEnvString);
        }

        if (isset($this->apacheRunUser)) {
          $task->user($this->apacheRunUser);
        }

        if (isset($this->sudoRunTests) && ($this->sudoRunTests)) {
          $task->sudo();
        }

        if (isset($test['bootstrap'])) {
          $task->bootstrap($test['bootstrap']);
        }

        if (isset($test['config'])) {
          $task->configFile($test['config']);
        }

        if (isset($test['debug']) && ($test['debug'])) {
          $task->debug();
        }

        if (isset($test['exclude'])) {
          $task->excludeGroup($test['exclude']);
        }

        if (isset($test['filter'])) {
          $task->filter($test['filter']);
        }

        if (isset($test['group'])) {
          $task->group($test['group']);
        }

        if (isset($test['printer'])) {
          $task->printer($test['printer']);
        }

        if (isset($test['stop-on-error']) && ($test['stop-on-error'])) {
          $task->stopOnError();
        }

        if (isset($test['stop-on-failure']) && ($test['stop-on-failure'])) {
          $task->stopOnFailure();
        }

        if (isset($test['testdox']) && ($test['testdox'])) {
          $task->testdox();
        }

        if (isset($test['class'])) {
          $task->arg($test['class']);
          if (isset($test['file'])) {
            $task->arg($test['file']);
          }
        }
        else {
          if (isset($test['directory'])) {
            $task->arg($test['directory']);
          }
        }

        if ((isset($test['testsuites']) && is_array($test['testsuites'])) || isset($test['testsuite'])) {
          if (isset($test['testsuites'])) {
            $task->testsuite(implode(',', $test['testsuites']));
          }
          elseif (isset($test['testsuite'])) {
            $task->testsuite($test['testsuite']);
          }
        }

        $result = $task->run();
        if (!$result->wasSuccessful()) {
          throw new BltException("PHPUnit tests failed.");
        }
      }
    }
  }

}
