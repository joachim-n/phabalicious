<?php

namespace Phabalicious\Method;

use Phabalicious\Artifact\Actions\ActionFactory;
use Phabalicious\Artifact\Actions\Base\ConfirmAction;
use Phabalicious\Artifact\Actions\Base\CopyAction;
use Phabalicious\Artifact\Actions\Base\DeleteAction;
use Phabalicious\Artifact\Actions\Base\InstallScriptAction;
use Phabalicious\Artifact\Actions\Base\LogAction;
use Phabalicious\Artifact\Actions\Base\MessageAction;
use Phabalicious\Artifact\Actions\Base\ScriptAction;
use Phabalicious\Configuration\ConfigurationService;
use Phabalicious\Configuration\HostConfig;
use Phabalicious\Exception\FailedShellCommandException;
use Phabalicious\Exception\MethodNotFoundException;
use Phabalicious\Exception\MissingScriptCallbackImplementation;
use Phabalicious\Exception\TaskNotFoundInMethodException;
use Phabalicious\Utilities\AppDefaultStages;
use Phabalicious\Utilities\EnsureKnownHosts;
use Phabalicious\Validation\ValidationErrorBagInterface;
use Phabalicious\Validation\ValidationService;
use Psr\Log\LoggerInterface;

abstract class ArtifactsBaseMethod extends BaseMethod
{
    const PREFS_KEY = 'artifact';


    public function __construct(LoggerInterface $logger)
    {
        parent::__construct($logger);

        ActionFactory::register('base', 'copy', CopyAction::class);
        ActionFactory::register('base', 'delete', DeleteAction::class);
        ActionFactory::register('base', 'confirm', ConfirmAction::class);
        ActionFactory::register('base', 'script', ScriptAction::class);
        ActionFactory::register('base', 'message', MessageAction::class);
        ActionFactory::register('base', 'log', LogAction::class);
        ActionFactory::register('base', 'installScript', InstallScriptAction::class);
    }

    public function getDefaultConfig(ConfigurationService $configuration_service, array $host_config): array
    {
        $return = parent::getDefaultConfig($configuration_service, $host_config); // TODO: Change the autogenerated stub
        $return[self::PREFS_KEY]['useLocalRepository'] = false;

        return $return;
    }

    public function validateConfig(array $config, ValidationErrorBagInterface $errors)
    {
        parent::validateConfig($config, $errors); // TODO: Change the autogenerated stub

        $service = new ValidationService($config, $errors, 'Host-config ' . $config['configName']);
        $service->isArray(self::PREFS_KEY, 'Please provide an artifact configuration');
        if (!empty($config[self::PREFS_KEY])) {
            $service = new ValidationService($config[self::PREFS_KEY], $errors, sprintf(
                'host-config.%s.%s',
                $config['configName'],
                self::PREFS_KEY
            ));
            $service->hasKeys([
                'actions' => 'An artifact needs a list of actions',
            ]);
            if (isset($config[self::PREFS_KEY]['actions'])) {
                foreach ($config[self::PREFS_KEY]['actions'] as $action_config) {
                    if (!isset($action_config['action'])) {
                        $errors->addError('unknown', 'action needs a name');
                    } else {
                        $action = ActionFactory::get($this->getName(), $action_config['action']);
                        $action->validateConfig($config, $action_config, $errors);
                    }
                }
            }
        }
    }

    protected function prepareDirectoriesAndStages(
        HostConfig $host_config,
        TaskContextInterface $context,
        array $stages,
        bool $create_target_dir
    ) {
        $hash = md5(rand(0, PHP_INT_MAX) . '-' . time());
        if ($use_local_repository = $host_config[self::PREFS_KEY]['useLocalRepository']) {
            $install_dir = $host_config['gitRootFolder'];
            $stages = array_diff($stages, ['installCode']);
        } else {
            $install_dir = $host_config['tmpFolder'] . '/' . $host_config['configName'] . '-' . $hash;
        }

        $target_dir = $host_config['tmpFolder']
            . '/' . $host_config['configName']
            . '-target-' . $hash;

        $context->set('useLocalRepository', $use_local_repository);
        $context->set('installDir', $install_dir);
        $context->set('targetDir', $target_dir);

        if ($create_target_dir) {
            $shell = $this->getShell($host_config, $context);
            $shell->run(sprintf('mkdir -p %s', $target_dir));
        }
        return $stages;
    }

    protected function cleanupDirectories(HostConfig $host_config, TaskContextInterface $context)
    {
        $shell = $this->getShell($host_config, $context);
        $install_dir = $context->get('installDir');
        $target_dir = $context->get('targetDir');

        $shell->run(sprintf('rm -rf %s', $target_dir));

        if (!$context->get('useLocalRepository')) {
            $shell->run(sprintf('rm -rf %s', $install_dir));
        }
    }

    /**
     * Build the artifact into given directory.
     *
     * @param HostConfig $host_config
     * @param TaskContextInterface $context
     * @param array $stages
     *
     * @throws MethodNotFoundException
     * @throws TaskNotFoundInMethodException
     * @throws MissingScriptCallbackImplementation
     * @throws FailedShellCommandException
     */
    protected function buildArtifact(
        HostConfig $host_config,
        TaskContextInterface $context,
        array $stages
    ) {
        $shell = $this->getShell($host_config, $context);
        $install_dir = $context->get('installDir');

        $cloned_host_config = clone $host_config;
        $keys = [];
        foreach ($context->getConfigurationService()->getMethodFactory()->all() as $method) {
            if ($root_folder = $method->getRootFolderKey()) {
                $keys[] = $root_folder;
            }
        }
        $keys = array_unique($keys);

        foreach ($keys as $key) {
            if ($host_config->get($key, 'ignore')[0] == '.') {
                 $dir = $install_dir . '/' . $host_config[$key];
            } else {
                $dir = $install_dir;
            }
            $cloned_host_config[$key] = $dir;
        }
        $shell->cd($cloned_host_config['tmpFolder']);
        $context->set('outerShell', $shell);
        $context->set('installDir', $install_dir);

        EnsureKnownHosts::ensureKnownHosts(
            $context->getConfigurationService(),
            $this->getKnownHosts($host_config, $context),
            $shell
        );

        AppDefaultStages::executeStages(
            $context->getConfigurationService()->getMethodFactory(),
            $cloned_host_config,
            $stages,
            'appCreate',
            $context,
            'Building artifact'
        );

        $this->runDeployScript($cloned_host_config, $context);
    }

    protected function runStageSteps(HostConfig $host_config, TaskContextInterface $context, $implementations)
    {
        if (!$current_stage = $context->get('currentStage', false)) {
            throw new \InvalidArgumentException('Missing currentStage on context!');
        }

        $implementations = array_merge(
            [
                'runDeployScript',
                'runActions',
            ],
            $implementations
        );

        if (in_array($current_stage, $implementations)) {
            if (method_exists($this, $current_stage)) {
                $this->{$current_stage}($host_config, $context);
            } else {
                throw new \RuntimeException(sprintf('Missing or unimplemented stage `%s`', $current_stage));
            }
        }
    }

    /**
     * @param HostConfig $host_config
     * @param TaskContextInterface $context
     * @throws MethodNotFoundException
     * @throws MissingScriptCallbackImplementation
     */
    protected function runDeployScript(HostConfig $host_config, TaskContextInterface $context)
    {
        /** @var ScriptMethod $script_method */
        $script_method = $context->getConfigurationService()->getMethodFactory()->getMethod('script');
        $install_dir = $context->get('installDir');
        $context->set('variables', [
            'installFolder' => $install_dir,
        ]);
        $context->set('rootFolder', $install_dir);
        $script_method->runTaskSpecificScripts($host_config, 'deploy', $context);

        $context->setResult('skipResetStep', true);
    }


    public function runActions(HostConfig $host_config, TaskContextInterface $context)
    {

        $actions = $host_config[self::PREFS_KEY]['actions'];
        foreach ($actions as $action_config) {
            $action = ActionFactory::get($this->getname(), $action_config['action']);
            $action->setArguments($action_config['arguments']);
            $action->run($host_config, $context);
        }
    }
}
