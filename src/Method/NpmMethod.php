<?php

namespace Phabalicious\Method;

use Phabalicious\Configuration\HostConfig;
use Phabalicious\Validation\ValidationErrorBagInterface;
use Phabalicious\Validation\ValidationService;

class NpmMethod extends RunCommandBaseMethod
{

    public function getName(): string
    {
        return 'npm';
    }

    public function validateConfig(array $config, ValidationErrorBagInterface $errors)
    {
        parent::validateConfig($config, $errors); // TODO: Change the autogenerated stub
        $service = new ValidationService($config, $errors, 'NPM');
        $service->hasKey('npmBuildCommand', 'build command to run with npm');
    }

    protected function prepareCommand(HostConfig $host_config, TaskContextInterface $context, string $command)
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
        $this->runCommand($host_config, $context, $host_config->get('npmBuildCommand'));
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
    
    public function npm(HostConfig $host_config, TaskContextInterface $context)
    {
        $command = $context->get('command');
        $this->runCommand($host_config, $context, $command);
    }
}
