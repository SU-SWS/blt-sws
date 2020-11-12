<?php

namespace Sws\BltSws\Blt\Plugin\Commands;

use Acquia\Blt\Robo\BltTasks;
use GuzzleHttp\Client;
use Acquia\Blt\Robo\Common\EnvironmentDetector;

/**
 * Class SlackCommands.
 *
 * @package Example\Blt\Plugin\Commands
 */
class SlackCommands extends BltTasks {

  /**
   * Post a message to the sws-release-notifications Slack channel.
   *
   * @param string $message
   *   The message to post to slack.
   *
   * @command sws:slack:release-notifications
   * @description Posts a message to the sws-release-notifications Slack channel.
   */
  public function postMessageToReleaseNotifications($message = 'Test message, please disregard.') {
    $this->prepareConfig();

    if ($url = $this->getConfigValue('slack.webhooks.release-notifications')) {
      $data = [
        'text' => $message,
      ];
      $this->post($url, $data, TRUE);
    }
    else {
      $this->say('SlackCommands: No release notifications webhook found.');
    }
  }

  /**
   * Load the settings file from the server and prepare that as config values.
   */
  protected function prepareConfig() {
    $settings = [];

    $root = $this->getConfigValue('repo.root');
    $settings_file = EnvironmentDetector::isAhEnv() ? EnvironmentDetector::getAhFilesRoot() . '/secrets.settings.php' : "$root/keys/secrets.settings.php";

    if (file_exists($settings_file)) {
      require $settings_file;
      $config = $this->getConfig();
      $config->set('slack', $settings['sws_slack']);
      $this->setConfig($config);
    }
  }

  /**
   * A Guzzle Post wrapper which sets up sensible settings.
   *
   * @param string $url
   *   The URL to post to.
   * @param array $data
   *   The data to post.
   * @param bool $raw
   *   Set to true to send the data as json-encoded body.
   * @param bool $multipart
   *   Set to true to post a multipart form.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The guzzle response.
   */
  protected function post(string $url, array $data, $raw = FALSE, $multipart = FALSE) {

    $client = new Client([
      'timeout' => 3.0,
    ]);

    $headers = [
      'Authorization' => 'Bearer ' . $this->getConfigValue('slack.token'),
      'content-type' => 'application/json',
    ];

    if ($raw) {
      $response = $client->request('POST', $url, [
        'headers' => $headers,
        'body' => json_encode($data),
      ]);
    }
    elseif ($multipart) {
      $response = $client->request('POST', $url, [
        'headers' => $headers,
        'multipart' => $data,
      ]);
    }
    else {
      $response = $client->request('POST', $url, [
        'headers' => $headers,
        'form_params' => $data,
      ]);
    }
    return $response;
  }

}
