<?php

namespace Sws\BltSws\Blt\Plugin\Commands;

use Acquia\Blt\Robo\BltTasks;

/**
 * Class SwsCommand.
 *
 * @package Sws\BltSws\Blt\Plugin\Commands
 */
class SwsCommand extends BltTasks {

  /**
   * @command sws:foo-bar
   */
  public function fooBarBaz(){
    $this->say('Does this work?');
  }

}
