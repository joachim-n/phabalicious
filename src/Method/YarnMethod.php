<?php

namespace Phabalicious\Method;

use Phabalicious\Configuration\HostConfig;
use Phabalicious\Validation\ValidationErrorBagInterface;
use Phabalicious\Validation\ValidationService;

class YarnMethod extends RunCommandBaseMethod
{

    public function getName(): string
    {
        return 'yarn';
    }

    public function validateConfig(array $config, ValidationErrorBagInterface $errors)
    {
        parent::validateConfig($config, $errors); // TODO: Change the autogenerated stub
        $service = new ValidationService($config, $errors, 'Yarn');
        $service->hasKey('yarnBuildCommand', 'build command to run with yarn');
    }

    protected function prepareCommand(HostConfig $host_config, TaskContextInterface $context, string $command): string
    {
        $production = !in_array($host_config['type'], array('dev', 'test'));
        $command .= ' --no-interaction  --silent';

        return $command;
    }
    /**
     * @param HostConfig $host_config
     * @param TaskContextInterface $context
     */
    public function resetPrepare(HostConfig $host_config, TaskContextInterface $context)
    {
        $this->runCommand($host_config, $context, 'install');
        $this->runCommand($host_config, $context, $host_config->get('yarnBuildCommand'));
    }

    public function installPrepare(HostConfig $host_config, TaskContextInterface $context)
    {
        $this->resetPrepare($host_config, $context);
    }

    public function appCreate(HostConfig $host_config, TaskContextInterface $context)
    {
        if (!$current_stage = $context->get('currentStage', false)) {
            throw new \InvalidArgumentException('Missing currentStage on context!');
        }

        if ($current_stage == 'installDependencies') {
            $this->resetPrepare($host_config, $context);
        }
    }

    public function yarn(HostConfig $host_config, TaskContextInterface $context)
    {
        $command = $context->get('command');
        $this->runCommand($host_config, $context, $command);
    }
}
