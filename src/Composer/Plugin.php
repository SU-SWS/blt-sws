<?php

namespace Sws\BltSws\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;
use Composer\Util\ProcessExecutor;

class Plugin implements PluginInterface, EventSubscriberInterface {

  /**
   * Returns an array of event names this subscriber wants to listen to.
   */
  public static function getSubscribedEvents() {
    return [
      ScriptEvents::POST_INSTALL_CMD => "onPostCmdEvent",
    ];
  }

  /**
   * Apply plugin modifications to composer.
   *
   * @param \Composer\Composer $composer
   *   Composer.
   * @param \Composer\IO\IOInterface $io
   *   Io.
   */
  public function activate(Composer $composer, IOInterface $io) {
    $this->composer = $composer;
    $this->io = $io;
  }

  /**
   * {@inheritDoc}
   */
  public function deactivate(Composer $composer, IOInterface $io) {}

  /**
   * {@inheritDoc}
   */
  public function uninstall(Composer $composer, IOInterface $io) {}

  /**
   * Execute blt blt:update after update command has been executed.
   *
   * @throws \Exception
   */
  public function onPostCmdEvent() {
    var_dump(__LINE__);
  }

}
