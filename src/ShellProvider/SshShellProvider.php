<?php

namespace Phabalicious\ShellProvider;

use Phabalicious\Configuration\ConfigurationService;
use Phabalicious\Configuration\HostConfig;
use Phabalicious\Exception\SshTunnelFailedException;
use Phabalicious\Method\TaskContextInterface;
use Phabalicious\Validation\ValidationService;
use Symfony\Component\Process\Process;

class SshShellProvider extends LocalShellProvider
{
    const PROVIDER_NAME = 'ssh';

    static protected $cachedSshPorts = [];

    public function getDefaultConfig(ConfigurationService $configuration_service, array $host_config): array
    {
        $result =  parent::getDefaultConfig($configuration_service, $host_config);
        $result['shellProviderExecutable'] = '/usr/bin/ssh';
        $result['disableKnownHosts'] = $configuration_service->getSetting('disableKnownHosts', false);
        $result['port'] = 22;

        if (isset($host_config['sshTunnel'])) {
            if (!empty($host_config['port'])) {
                $result['sshTunnel']['localPort'] = $host_config['port'];
            } elseif (!empty($host_config['configName'])) {
                if (!empty(self::$cachedSshPorts[$host_config['configName']])) {
                    $port = self::$cachedSshPorts[$host_config['configName']];
                } else {
                    $port = rand(1025, 65535);
                }
                self::$cachedSshPorts[$host_config['configName']] = $port;
                $result['port'] = $port;
                $result['sshTunnel']['localPort'] = $port;
            }

            if (isset($host_config['docker']['name'])) {
                $result['sshTunnel']['destHostFromDockerContainer'] = $host_config['docker']['name'];
            }
        }

        return $result;
    }

    public function validateConfig(array $config, \Phabalicious\Validation\ValidationErrorBagInterface $errors)
    {
        parent::validateConfig($config, $errors);

        $validation = new ValidationService($config, $errors, sprintf('host-config: `%s`', $config['configName']));
        $validation->hasKeys([
            'host' => 'Hostname to connect to',
            'port' => 'The port to connect to',
            'user' => 'Username to use for this connection',
        ]);

        if (!empty($config['sshTunnel'])) {
            $tunnel_validation = new ValidationService(
                $config['sshTunnel'],
                $errors,
                sprintf('sshTunnel-config: `%s`', $config['configName'])
            );
            $tunnel_validation->hasKeys([
                'bridgeHost' => 'The hostname of the bridge-host',
                'bridgeUser' => 'The username to use to connect to the bridge-host',
                'bridgePort' => 'The port to use to connect to the bridge-host',
                'destPort' => 'The port of the destination host',
                'localPort' => 'The local port to forward to the destination-host'
            ]);
            if (empty($config['sshTunnel']['destHostFromDockerContainer'])) {
                $tunnel_validation->hasKey('destHost', 'The hostname of the destination host');
            }
        }
        if (isset($config['strictHostKeyChecking'])) {
            $errors->addWarning('strictHostKeyChecking', 'Please use `disableKnownHosts` instead.');
        }
    }

    protected function addCommandOptions(&$command, $override = false)
    {
        if ($override || $this->hostConfig['disableKnownHosts']) {
            $command[] = '-o';
            $command[] = 'StrictHostKeyChecking=no';
            $command[] = '-o';
            $command[] = 'UserKnownHostsFile=/dev/null';
        }
    }

    public function getShellCommand(array $options = []): array
    {
        $command = [
            $this->hostConfig['shellProviderExecutable'],
            '-A',
            '-p',
            $this->hostConfig['port'],
            ];
        $this->addCommandOptions($command);
        if (!empty($options['tty'])) {
            $command[] = '-t';
        }
        $command[] = $this->hostConfig['user'] . '@' . $this->hostConfig['host'];

        return $command;
    }

    /**
     * @param $dir
     * @return bool
     * @throws \Exception
     */
    public function exists($dir):bool
    {
        $result = $this->run(sprintf('stat %s > /dev/null 2>&1', $dir), false, false);
        return $result->succeeded();
    }

    public function putFile(string $source, string $dest, TaskContextInterface $context, bool $verbose = false): bool
    {
        $command = [
            '/usr/bin/scp',
            '-P',
            $this->hostConfig['port']
        ];

        $this->addCommandOptions($command);

        $command[] = $source;
        $command[] = $this->hostConfig['user'] . '@' . $this->hostConfig['host'] . ':' . $dest;

        return $this->runProcess($command, $context, false, true);
    }

    public function getFile(string $source, string $dest, TaskContextInterface $context, bool $verbose = false): bool
    {
        $command = [
            '/usr/bin/scp',
            '-P',
            $this->hostConfig['port']
        ];

        $this->addCommandOptions($command);

        $command[] = $this->hostConfig['user'] . '@' . $this->hostConfig['host'] . ':' . $source;
        $command[] = $dest;

        return $this->runProcess($command, $context, false, true);
    }

    public function getSshTunnelCommand(
        string $ip,
        int $port,
        string $public_ip,
        int $public_port,
        $config
    ) {
        $cmd = [
            '/usr/bin/ssh',
            '-A',
            "-L$public_ip:$public_port:$ip:$port",
            '-p',
            $config['port'],
            $config['user'] . '@' . $config['host']
        ];
        $this->addCommandOptions($cmd, true);
        return $cmd;
    }

    public function startRemoteAccess(
        string $ip,
        int $port,
        string $public_ip,
        int $public_port,
        HostConfig $config,
        TaskContextInterface $context
    ) {
        $this->runProcess(
            $this->getSshTunnelCommand($ip, $port, $public_ip, $public_port, $config),
            $context,
            true
        );
    }

    /**
     * @param HostConfig $target_config
     * @param array $prefix
     * @return Process
     * @throws SshTunnelFailedException
     */
    public function createTunnelProcess(HostConfig $target_config, array $prefix = [])
    {
        $tunnel = $target_config['sshTunnel'];
        $bridge = [
            'host' => $tunnel['bridgeHost'],
            'port' => $tunnel['bridgePort'],
            'user' => $tunnel['bridgeUser'],
        ];
        $cmd = $this->getSshTunnelCommand(
            $tunnel['destHost'],
            $tunnel['destPort'],
            $target_config['host'],
            $target_config['port'],
            $bridge
        );

        $cmd[] = '-v';
        $cmd[] = '-N';
        $cmd[] = '-o';
        $cmd[] = 'PasswordAuthentication=no';

        if (count($prefix)) {
            $prefix[] = implode(' ', $cmd);
            $cmd = $prefix;
        }

        $this->logger->info('Starting tunnel with ' . implode(' ', $cmd));

        $process = new Process(
            $cmd
        );
        $process->setTimeout(0);
        $process->start(function ($type, $buffer) {
            $buffer = trim($buffer);
            $this->logger->debug($buffer);
        });

        $result = '';
        while ((strpos($result, 'Entering interactive session') === false) && !$process->isTerminated()) {
            $result .= $process->getIncrementalErrorOutput();
        }
        if ($process->isTerminated() && $process->getExitCode() != 0) {
            throw new SshTunnelFailedException("SSH-Tunnel creation failed with \n" . $result);
        }

        return $process;
    }

    public function copyFileFrom(
        ShellProviderInterface $from_shell,
        string $source_file_name,
        string $target_file_name,
        TaskContextInterface $context,
        bool $verbose = false
    ): bool {
        if ($from_shell instanceof SshShellProvider) {
            $from_host_config = $from_shell->getHostConfig();
            $command = [
                '/usr/bin/scp',
                '-o',
                'PasswordAuthentication=no',
                '-P',
                $from_host_config['port']
            ];

            $this->addCommandOptions($command, true);

            $command[] = $from_host_config['user'] . '@' . $from_host_config['host'] . ':' .$source_file_name;
            $command[] = $target_file_name;

            $cr = $this->run(implode(' ', $command), false, false);
            if ($cr->succeeded()) {
                return true;
            } else {
                $this->logger->warning('Could not copy file via SSH, try fallback');
            }

        }
        return parent::copyFileFrom($from_shell, $source_file_name, $target_file_name, $context, $verbose);
    }

}
