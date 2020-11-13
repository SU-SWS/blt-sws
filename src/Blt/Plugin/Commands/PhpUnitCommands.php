<?php

namespace Sws\BltSws\Blt\Plugin\Commands;

use Acquia\Blt\Robo\BltTasks;
use Acquia\Blt\Robo\Exceptions\BltException;
use Acquia\BltDrupalTest\Blt\Plugin\Commands\PhpUnitCommand;
use Robo\ResultData;
use Symfony\Component\Console\Output\OutputInterface;

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
