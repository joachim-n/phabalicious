<?php

namespace Phabalicious\Command;

use Graze\ParallelProcess\Pool;
use http\Exception\InvalidArgumentException;
use Phabalicious\Configuration\ConfigurationService;
use Phabalicious\Configuration\HostConfig;
use Phabalicious\Exception\BlueprintTemplateNotFoundException;
use Phabalicious\Exception\FabfileNotFoundException;
use Phabalicious\Exception\FabfileNotReadableException;
use Phabalicious\Exception\MismatchedVersionException;
use Phabalicious\Exception\ValidationFailedException;
use Phabalicious\Exception\MissingHostConfigException;
use Phabalicious\ShellProvider\ShellProviderInterface;
use Phabalicious\Utilities\ParallelExecutor;
use Phabalicious\Validation\ValidationErrorBag;
use Phabalicious\Validation\ValidationService;
use Psr\Log\NullLogger;
use Stecman\Component\Symfony\Console\BashCompletion\Completion\CompletionAwareInterface;
use Stecman\Component\Symfony\Console\BashCompletion\CompletionContext;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

abstract class BaseCommand extends BaseOptionsCommand
{
    private $hostConfig;

    private $dockerConfig;


    protected function configure()
    {
        $default_conf = getenv('PHABALICIOUS_DEFAULT_CONFIG');
        if (empty($default_conf)) {
            $default_conf = null;
        }
        $this
            ->addOption(
                'config',
                'c',
                InputOption::VALUE_REQUIRED,
                'Which host-config should be worked on',
                $default_conf
            )
            ->addOption(
                'blueprint',
                null,
                InputOption::VALUE_OPTIONAL,
                'Which blueprint to use',
                null
            )
            ->addOption(
                'variants',
                null,
                InputOption::VALUE_OPTIONAL,
                'Run the command on a given set of blueprints simultanously',
                null
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_OPTIONAL,
                'Don\'t ask for confirmation',
                false
            );

        parent::configure();
    }

    public function completeOptionValues($optionName, CompletionContext $context)
    {
        if ($optionName == 'config') {
            $config = new ConfigurationService($this->getApplication(), new NullLogger());
            $config->setOffline(true);
            try {
                $config->readConfiguration(getcwd());
            } catch (\Exception $e) {
                return [];
            }
            return array_keys($config->getAllHostConfigs());
        }
        return parent::completeOptionValues($optionName, $context);
    }

    /**
     * {@inheritdoc}
     * @throws \Phabalicious\Exception\MismatchedVersionException
     * @throws \Phabalicious\Exception\FabfileNotFoundException
     * @throws \Phabalicious\Exception\FabfileNotReadableException
     * @throws \Phabalicious\Exception\MissingDockerHostConfigException
     * @throws \Phabalicious\Exception\ShellProviderNotFoundException
     * @throws \Phabalicious\Exception\BlueprintTemplateNotFoundException
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->checkAllRequiredOptionsAreNotEmpty($input);

        $config_name = '' . $input->getOption('config');

        try {
            $this->readConfiguration($input);

            if ($input->hasOption('blueprint') && $blueprint = $input->getOption('blueprint')) {
                $this->hostConfig = $this->getConfiguration()->getHostConfigFromBlueprint(
                    $config_name,
                    $blueprint
                );
            } else {
                $this->hostConfig = $this->getConfiguration()->getHostConfig($config_name);
            }

            if (!empty($this->hostConfig['docker']['configuration'])) {
                $docker_config_name = $this->hostConfig['docker']['configuration'];
                $this->dockerConfig = $this->getConfiguration()->getDockerConfig($docker_config_name);
            }

            if ($this->hostConfig->shell()) {
                $this->hostConfig->shell()->setOutput($output);
            }

            if ($input->getOption('variants')) {
                return $this->handleVariants($input->getOption('variants'), $input, $output);
            }
        } catch (MissingHostConfigException $e) {
            $output->writeln('<error>Could not find host-config named `' . $config_name . '`</error>');
            return 1;
        } catch (ValidationFailedException $e) {
            $output->writeln('<error>Could not validate config `' . $config_name . '`</error>');
            foreach ($e->getValidationErrors() as $error_msg) {
                $output->writeln('<error>' . $error_msg . '</error>');
            }
            return 1;
        }

        return 0;
    }

    /**
     * Get host config.
     *
     * @return HostConfig
     */
    protected function getHostConfig()
    {
        return $this->hostConfig;
    }

    protected function getDockerConfig()
    {
        return $this->dockerConfig;
    }

    public function runCommand(string $command, array $args, InputInterface $original_input, OutputInterface $output)
    {
        $cmd = $this->getApplication()->find($command);

        $args['command'] = $command;

        foreach ($original_input->getOptions() as $key => $value) {
            // Skip options not available for command.
            if (!$cmd->getDefinition()->hasOption($key)) {
                continue;
            }

            $option_key = '--' . $key;
            if (empty($args[$option_key])) {
                $args[$option_key] = $value;
            }
        };
        $input = new ArrayInput($args);
        return $cmd->run($input, $output);
    }

    /**
     * @param ShellProviderInterface $shell
     * @param array $command
     * @return Process
     */
    protected function startInteractiveShell(ShellProviderInterface $shell, array $command = [])
    {
        /** @var Process $process */
        if (!empty($command)) {
            $command = [
                'bash',
                '--login',
                '-c',
                '\'' . implode(' ', $command) .'\'',
            ];
        }
        $process = $shell->createShellProcess($command, ['tty' => true]);
        $stdin = fopen('php://stdin', 'r');
        $process->setInput($stdin);
        $process->setTimeout(0);
        $process->setTty(true);
        $process->start();
        $process->wait(function ($type, $buffer) {
            if ($type == Process::ERR) {
                fwrite(STDERR, $buffer);
            } else {
                fwrite(STDOUT, $buffer);
            }
        });

        return $process;
    }

    /**
     * Handle variants.
     *
     * @param $variants
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    private function handleVariants($variants, InputInterface $input, OutputInterface $output)
    {
        global $argv;

        $available_variants = $this->configuration->getBlueprints()->getVariants($this->hostConfig['configName']);
        if (!$available_variants) {
            throw new \InvalidArgumentException(sprintf(
                'Could not find variants for `%s` in `blueprints`',
                $this->hostConfig['configName']
            ));
        }

        if ($variants == 'all') {
            $variants = $available_variants;
        } else {
            $variants = explode(',', $variants);
            $not_found = array_filter($variants, function ($v) use ($available_variants) {
                return !in_array($v, $available_variants);
            });

            if (!empty($not_found)) {
                throw new \InvalidArgumentException(sprintf(
                    'Could not find variants `%s` in `blueprints`',
                    implode('`, `', $not_found)
                ));
            }
        }
        if (!empty($variants)) {
            $cmd_lines = [];
            $rows = [];
            foreach ($variants as $v) {
                $cmd = [];
                $cmd[] = 'phab';

                foreach ($input->getArguments() as $a) {
                    $cmd[] = $a;
                }
                foreach ($input->getOptions() as $name => $value) {
                    if ($value && !in_array($name, ['variants', 'blueprint', 'fabfile'])) {
                        $cmd[] = '--' . $name;
                        $cmd[]= $value;
                    }
                }
                $cmd[] = '--fabfile';
                $cmd[] = $this->configuration->getFabfileLocation();
                $cmd[] = '--blueprint';
                $cmd[] = $v;

                $cmd_lines[] = $cmd;
                $rows[] = [$v, implode(' ', $cmd)];
            }

            $style = new SymfonyStyle($input, $output);
            $style->table(['variant', 'command'], $rows);

            if ($input->getOption('force') || $style->confirm('Do you want to run these commands? ', false)) {
                $executor = new ParallelExecutor($cmd_lines, $output);
                return $executor->execute($input, $output);
            }

            return 1;
        }
    }
}
