<?php

namespace Phabalicious\Tests;

use Phabalicious\Configuration\ConfigurationService;
use Phabalicious\Configuration\HostConfig;
use Phabalicious\ShellProvider\LocalShellProvider;
use Phabalicious\ShellProvider\SshShellProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

class PhabTestCase extends TestCase
{

    /**
     * @var \Symfony\Component\Process\Process
     */
    private $backgroundProcess;

    protected function getTmpDir()
    {
        $dir = __DIR__ . '/tmp';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    public function tearDown()
    {
        shell_exec(sprintf('rm -rf "%s"', $this->getTmpDir()));
        parent::tearDown(); // TODO: Change the autogenerated stub
    }

    protected function checkFileContent($filename, $needle)
    {
        $haystack = file_get_contents($filename);
        $this->assertContains($needle, $haystack);
    }


    protected function runDockerizedSshServer($logger, ConfigurationService $config)
    {
        $publicKeyFile = realpath(__DIR__ . '/assets/ssh-shell-tests/test_key.pub');
        $privateKeyFile = realpath(__DIR__ . '/assets/ssh-shell-tests/test_key');

        $runDockerShell = new LocalShellProvider($logger);
        $host_config = new HostConfig([
            'shellExecutable' => '/bin/sh',
            'rootFolder' => dirname(__FILE__)
        ], $runDockerShell, $config);

        $result = $runDockerShell->run('docker pull ghcr.io/linuxserver/openssh-server', true);
        $result = $runDockerShell->run('docker stop phabalicious_ssh_test | true', true);
        $result = $runDockerShell->run('docker rm phabalicious_ssh_test | true', true);
        $result = $runDockerShell->run(sprintf('chmod 600 %s', $privateKeyFile));
        $public_key = trim(file_get_contents($publicKeyFile));

        $this->backgroundProcess = new Process([
            'docker',
            'run',
            '-i',
            '-e',
            'PUID=1000',
            '-e',
            'PGID=1000',
            '-e',
            "PUBLIC_KEY=$public_key",
            '-e',
            'USER_NAME=test',
            '-p',
            '22222:2222',
            '--name',
            'phabalicious_ssh_test',
            'ghcr.io/linuxserver/openssh-server',
        ]);
        $input = new InputStream();
        $this->backgroundProcess->setInput($input);
        $this->backgroundProcess->setTimeout(0);
        $this->backgroundProcess->start(function ($type, $buffer) {
            fwrite(STDOUT, $buffer);
        });
        // Give the container some time to spin up
        sleep(5);

        return $privateKeyFile;
    }

    public function getDockerizedSshShell($logger, ConfigurationService $config): SshShellProvider
    {

        $shellProvider = new SshShellProvider($logger);
        $privateKeyFile = $this->runDockerizedSshServer($logger, $config);
        $host_config = new HostConfig([
            'configName' => 'ssh-test',
            'shellExecutable' => '/bin/sh',
            'shellProviderExecutable' => '/usr/bin/ssh',
            'disableKnownHosts' => true,
            'rootFolder' => '/',
            'host' => 'localhost',
            'port' => '22222',
            'user' => 'test',
            'shellProviderOptions' => [
                '-i' .
                $privateKeyFile
            ],
        ], $shellProvider, $config);


        $shellProvider->setHostConfig($host_config);

        return $shellProvider;
    }
}
