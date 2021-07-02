<?php

namespace Sws\BltSws\Blt\Plugin\Commands;

use Acquia\Blt\Robo\BltTasks;
use Acquia\Blt\Robo\Common\YamlMunge;
use Acquia\Blt\Robo\Exceptions\BltException;
use AcquiaCloudApi\Response\ApplicationResponse;
use Symfony\Component\Yaml\Yaml;

/**
 * Defines commands in the "generate:aliases" namespace.
 */
class AliasesCommand extends BltTasks {

  use SwsCommandTrait;

  /**
   * Site alias dir.
   *
   * @var string
   */
  protected $siteAliasDir;

  /**
   * Generates new Acquia site aliases for Drush.
   *
   * @command recipes:aliases:init:acquia
   *
   * @aliases raia aliases
   */
  public function generateAliasesAcquia() {
    $this->connectAcquiaApi();
    $this->siteAliasDir = $this->getConfigValue('drush.alias-dir');

    $this->say("<info>Gathering site info from Acquia Cloud.</info>");
    $site = $this->acquiaApplications->get($this->appId);

    $error = FALSE;
    try {
      $this->getSiteAliases($site);
    }
    catch (\Exception $e) {
      $error = TRUE;
      $this->logger->error("Did not write aliases for $site->name. Error: " . $e->getMessage());
    }
    if (!$error) {
      $this->say("<info>Aliases were written, type 'drush sa' to see them.</info>");
    }
  }


  /**
   * Gets generated drush site aliases.
   *
   * @param \AcquiaCloudApi\Response\ApplicationResponse $site
   *   The Acquia subscription that aliases will be generated for.
   *
   * @throws \Exception
   */
  protected function getSiteAliases(ApplicationResponse $site) {
    $sites = [];
    $this->output->writeln("<info>Gathering sites list from Acquia Cloud.</info>");

    $environments = $this->acquiaEnvironments->getAll($this->appId);
    $hosting = $site->hosting->type;
    $site_split = explode(':', $site->hosting->id);

    foreach ($environments as $env) {

      $environment_servers = $this->acquiaServers->getAll($env->uuid);
      $web_servers = array_filter($environment_servers->getArrayCopy(), function($server){
        return in_array('web', $server->roles);
      });

      $domains = [];
      $domains = $env->domains;
      $this->say('<info>Found ' . count($domains) . ' domains for environment ' . $env->name . ', writing aliases...</info>');

      $sshFull = $env->sshUrl;
      $ssh_split = explode('@', $env->sshUrl);
      $envName = $env->name;
      $remoteHost = $ssh_split[1];
      $remoteUser = $ssh_split[0];

      if (in_array($hosting, ['ace', 'acp'])) {

        $siteID = $site_split[1];
        $uri = $env->domains[0];
        $sites[$siteID][$envName] = ['uri' => $uri];
        $siteAlias = $this->getAliases($uri, $envName, $remoteHost, $remoteUser);
        $sites[$siteID][$envName] = $siteAlias[$envName];

      }

      if ($hosting == 'acsf') {
        $this->say('<info>ACSF project detected - generating sites data....</info>');

        try {
          $acsf_sites = $this->getSitesJson($sshFull, $remoteUser);
        }
        catch (\Exception $e) {
          $this->logger->error("Could not fetch acsf data for $envName. Error: " . $e->getMessage());
        }

        // Look for list of sites and loop over it.
        if ($acsf_sites) {
          foreach ($acsf_sites['sites'] as $name => $info) {
            // Pick a random web server to use as the host.
            $server = $web_servers[array_rand($web_servers)];

            // Reset uri value to identify non-primary domains.
            $uri = NULL;

            // Get site prefix from main domain.
            if (strpos($name, '.acsitefactory.com')) {
              $acsf_site_name = explode('.', $name, 2);
              $siteID = $acsf_site_name[0];
            }
            if (!empty($siteID) && !empty($info['flags']['preferred_domain'])) {
              $uri = $name;
            }

            foreach ($domains as $site) {
              // Skip sites without primary domain as the alias will be invalid.
              if (isset($uri)) {
                $sites[$siteID][$envName] = ['uri' => $uri];
                $siteAlias = $this->getAliases($uri, $envName, $server->hostname, $remoteUser, $siteID);
                $sites[$siteID][$envName] = $siteAlias[$envName];
              }
              continue;
            }
          }

        }
      }

    }

    // Write the alias files to disk.
    foreach ($sites as $siteID => $aliases) {
      $this->writeSiteAliases($siteID, $aliases);
    }
  }

  /**
   * Generates a site alias for valid domains.
   *
   * @param string $uri
   *   The unique site url.
   * @param string $envName
   *   The current environment.
   * @param string $remoteHost
   *   The remote host.
   * @param string $remoteUser
   *   The remote user.
   *
   * @return array
   *   The full alias for this site.
   */
  protected function getAliases($uri, $envName, $remoteHost, $remoteUser) {
    $alias = [];
    // Skip wildcard domains.
    $skip_site = FALSE;
    if (strpos($uri, ':*') !== FALSE) {
      $skip_site = TRUE;
    }

    if (!$skip_site) {
      $docroot = '/var/www/html/' . $remoteUser . '/docroot';
      $alias[$envName]['uri'] = $uri;
      $alias[$envName]['host'] = $remoteHost;
      $alias[$envName]['options'] = [];
      $alias[$envName]['paths'] = ['dump-dir' => '/mnt/tmp'];
      $alias[$envName]['root'] = $docroot;
      $alias[$envName]['user'] = $remoteUser;
      $alias[$envName]['ssh'] = ['options' => '-p 22', 'tty' => 0];

      return $alias;

    }
  }

  /**
   * Gets ACSF sites info without secondary API calls or Drupal bootstrap.
   *
   * @param string $sshFull
   *   The full ssh connection string for this environment.
   * @param string $remoteUser
   *   The site.env remoteUser string used in the remote private files path.
   *
   * @return array
   *   An array of ACSF site data for the current environment.
   */
  protected function getSitesJson($sshFull, $remoteUser) {

    $this->say('Getting ACSF sites.json information...');
    $result = $this->taskRsync()
      ->fromPath('/mnt/files/' . $remoteUser . '/files-private/sites.json')
      ->fromHost($sshFull)
      ->toPath($this->cloudConfDir)
      ->remoteShell('ssh -A -p 22')
      ->run();

    if (!$result->wasSuccessful()) {
      throw new \Exception("Unable to rsync ACSF sites.json");
    }

    $fullPath = $this->cloudConfDir . '/sites.json';
    $response_body = file_get_contents($fullPath);
    $sites_json = json_decode($response_body, TRUE);

    return $sites_json;

  }

  /**
   * Writes site aliases to disk.
   *
   * @param string $site_id
   *   The siteID or alias group.
   * @param array $aliases
   *   The alias array for this site group.
   *
   * @return string
   *   The alias site group file path.
   *
   * @throws \Exception
   */
  protected function writeSiteAliases($site_id, array $aliases) {

    if (!is_dir($this->siteAliasDir)) {
      mkdir($this->siteAliasDir);
    }
    $filePath = $this->siteAliasDir . '/' . $site_id . '.site.yml';

    file_put_contents($filePath, Yaml::dump($aliases));
    return $filePath;
  }

}
