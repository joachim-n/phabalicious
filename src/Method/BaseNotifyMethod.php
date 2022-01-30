<?php

namespace Phabalicious\Method;

use Phabalicious\Configuration\ConfigurationService;
use Phabalicious\Configuration\HostConfig;
use Phabalicious\Configuration\HostType;
use Phabalicious\Configuration\Storage\Node;
use Phabalicious\Exception\MethodNotFoundException;
use Phabalicious\Exception\TaskNotFoundInMethodException;
use Phabalicious\Validation\ValidationErrorBagInterface;

abstract class BaseNotifyMethod extends BaseMethod implements MethodInterface, NotifyMethodInterface
{

    const DEFAULT_NOTIFY_TYPES = [
        HostType::STAGE,
        HostType::PROD
    ];

    const ERROR = 'error';
    const SUCCESS = 'success';

    public function getDefaultConfig(ConfigurationService $configuration_service, Node $host_config): Node
    {
        $parent = parent::getDefaultConfig($configuration_service, $host_config);
        $result = [];
        if (empty($host_config['notifyOn']) && in_array($host_config['type'], self::DEFAULT_NOTIFY_TYPES)) {
            $result['notifyOn'] = ['deploy'];
        }

        return $parent->merge(new Node($result, $this->getName() . ' method defaults'));
    }

    public function validateConfig(Node $config, ValidationErrorBagInterface $errors)
    {
        parent::validateConfig($config, $errors); // TODO: Change the autogenerated stub
    }

    public function postflightTask(string $task, HostConfig $config, TaskContextInterface $context)
    {
        if (!empty($config['notifyOn']) && in_array($task, $config['notifyOn'])) {
            if ($context->getResult('exitCode', 0) == 0) {
                $this->sendNotificationImpl(
                    $config,
                    sprintf('Task `%s` successfully finished', $task),
                    $context,
                    self::SUCCESS
                );
            } else {
                $this->sendNotificationImpl(
                    $config,
                    sprintf('Task `%s` failed with an error!', $task),
                    $context,
                    self::ERROR
                );
            }
        }
    }

    public function notify(HostConfig $host_config, TaskContextInterface $context)
    {
        $message = $context->get('message', false);
        if (!$message) {
            throw new \InvalidArgumentException('Missing message in context');
        }
        $this->sendNotificationImpl($host_config, $message, $context, self::SUCCESS);
    }

    /**
     * @param HostConfig $host_config
     * @param string $message
     * @param TaskContextInterface $context
     * @param string $type
     * @throws MethodNotFoundException
     * @throws TaskNotFoundInMethodException
     */
    private function sendNotificationImpl(
        HostConfig $host_config,
        $message,
        TaskContextInterface $context,
        string $type
    ) {
        $meta_context = clone $context;
        $meta_context->setResult('meta', []);
        $context->getConfigurationService()->getMethodFactory()->runTask(
            'getMetaInformation',
            $host_config,
            $meta_context
        );
        $meta = $meta_context->getResult('meta', []);

        $this->sendNotification($host_config, $message, $context, $type, $meta);
    }
}
