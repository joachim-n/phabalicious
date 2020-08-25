<?php

namespace Phabalicious\ShellProvider;

use Phabalicious\Configuration\ConfigurationService;
use Phabalicious\Configuration\HostConfig;
use Phabalicious\Method\TaskContextInterface;
use Phabalicious\Validation\ValidationErrorBagInterface;
use Phabalicious\Validation\ValidationService;
use Symfony\Component\Process\Process;

class KubectlShellProvider extends LocalShellProvider implements ShellProviderInterface
{
    const PROVIDER_NAME = 'kubectl';

    public function getDefaultConfig(ConfigurationService $configuration_service, array $host_config): array
    {
        $result =  parent::getDefaultConfig($configuration_service, $host_config);
        $result['kubectlExecutable'] = 'kubectl';
        $result['kubectlOptions'] = [];
        $result['shellExecutable'] = '/bin/sh';

        $result['kube']['namespace'] = 'default';
        return $result;
    }

    public function validateConfig(array $config, ValidationErrorBagInterface $errors)
    {
        parent::validateConfig($config, $errors);

        $validation = new ValidationService($config, $errors, 'host-config');
        $validation->hasKeys(['kube' => 'The kubernetes config to use']);
        $validation->isArray('kubectlOptions', 'A set of key value pairs to pass as options to kubectl');

        if (!$errors->hasErrors()) {
            $validation = new ValidationService($config['kube'], $errors, 'host:kube');
            $validation->isArray('podSelector', 'A set of selectors to get the pod you want to connect to.');
            $validation->hasKey('namespace', 'The namespace the pod is located in.');
        }
    }

    public function createShellProcess(array $command = [], array $options = []): Process
    {
        // Apply kubectl environment vars.
        $this->setShellEnvironmentVars($this->hostConfig['kube']['environment']);
        return parent::createShellProcess($command, $options); // TODO: Change the autogenerated stub
    }

    public static function getKubectlCmd(HostConfig $config, $kubectl_cmd = '#!kubectl')
    {
        $cmd = [ $kubectl_cmd ];
        foreach ($config['kubectlOptions'] as $k => $v) {
            $cmd[] = $k;
            if ($v !== "") {
                $cmd[] = $v;
            }
        }
        foreach (array('kubeconfig', 'namespace') as $key) {
            if (!empty($config['kube'][$key])) {
                $cmd[] = '--' . $key;
                $cmd[] = $config['kube'][$key];
            }
        }

        return $cmd;
    }
    protected function getKubeCmd()
    {
        return self::getKubectlCmd($this->getHostConfig(), 'kubectl');
    }

    public function getShellCommand(array $program_to_call, array $options = []): array
    {
        if (empty($this->hostConfig['kube']['podForCli'])) {
            throw new \RuntimeException("Could not get shell, as podForCli is empty!");
        }
        $command = $this->getKubeCmd();
        $command[] = 'exec';
        $command[] = (empty($options['tty']) ? '-i' : '-it');
        $command[] = $this->hostConfig['kube']['podForCli'];
        $command[] = '--';

        if (!empty($options['tty']) && empty($options['shell_provided'])) {
            $command[] = $this->hostConfig['shellExecutable'];
        }

        if (count($program_to_call)) {
            foreach ($program_to_call as $p) {
                $command[] = $p;
            }
        }

        return $command;
    }

    /**
     * @param string $dir
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
        $command = $this->getPutFileCommand($source, $dest);
        return $this->runProcess($command, $context, false, true);
    }

    public function getFile(string $source, string $dest, TaskContextInterface $context, bool $verbose = false): bool
    {
        $command = $this->getGetFileCommand($source, $dest);
        return $this->runProcess($command, $context, false, true);
    }


    /**
     * {@inheritdoc}
     */
    public function wrapCommandInLoginShell(array $command)
    {
        array_unshift(
            $command,
            '/bin/bash',
            '--login',
            '-c'
        );
        return $command;
    }

    /**
     * @param string $source
     * @param string $dest
     * @return string[]
     */
    public function getPutFileCommand(string $source, string $dest): array
    {
        $command = $this->getKubeCmd();
        $command[] = 'cp';
        $command[] = trim($source);
        $command[] = $this->hostConfig['kube']['podForCli'] . ':' . trim($dest);

        return $command;
    }

    /**
     * @param string $source
     * @param string $dest
     * @return string[]
     */
    public function getGetFileCommand(string $source, string $dest): array
    {
        $command = $this->getKubeCmd();
        $command[] = 'cp';
        $command[] = $this->hostConfig['kube']['podForCli'] . ':' . trim($source);
        $command[] = trim($dest);

        return $command;
    }
}
