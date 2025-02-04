<?php

namespace Phabalicious\ShellProvider;

use Phabalicious\Configuration\ConfigurationService;
use Phabalicious\Configuration\HostConfig;
use Phabalicious\Exception\FailedShellCommandException;
use Phabalicious\Method\TaskContextInterface;
use Phabalicious\Utilities\SetAndRestoreObjProperty;
use Phabalicious\Utilities\Utilities;
use Phabalicious\Validation\ValidationErrorBagInterface;
use Phabalicious\Validation\ValidationService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

class LocalShellProvider extends BaseShellProvider implements ShellProviderInterface
{

    const RESULT_IDENTIFIER = '##RESULT:';
    const PROVIDER_NAME = 'local';

    /** @var Process|null */
    protected $process;

    /** @var InputStream */
    protected $input;

    protected $captureOutput = false;

    protected $shellEnvironmentVars = [];

    protected $preventTimeout = false;

    public function __construct(LoggerInterface $logger)
    {
        parent::__construct($logger);
        if ($this->getName() == 'local') {
            $this->setFileOperationsHandler(new LocalFileOperations());
        }
    }

    public function getName(): string
    {
        return 'local';
    }

    /**
     * @param bool $preventTimeout
     */
    public function setPreventTimeout(bool $preventTimeout): void
    {
        $this->preventTimeout = $preventTimeout;
    }

    protected function setShellEnvironmentVars(array $vars)
    {
        $this->shellEnvironmentVars = $vars;
    }

    public function getDefaultConfig(ConfigurationService $configuration_service, array $host_config): array
    {
        $result = parent::getDefaultConfig($configuration_service, $host_config);
        $result['shellExecutable'] = $configuration_service->getSetting('shellExecutable', '/bin/bash');
        $result['shellProviderExecutable'] = $configuration_service->getSetting('shellProviderExecutable', '/bin/bash');

        return $result;
    }

    public function validateConfig(array $config, ValidationErrorBagInterface $errors)
    {
        parent::validateConfig($config, $errors);

        $validator = new ValidationService($config, $errors, 'host-config');
        $validator->hasKey(
            'shellExecutable',
            'Missing shellExecutable, should point to the executable to run an interactive shell'
        );
    }

    public function createShellProcess(array $command = [], ShellOptions $options = null): Process
    {
        if (!$options) {
            $options = new ShellOptions();
        }
        $shell_command = $this->getShellCommand($command, $options);
        $this->logger->info('Starting shell with ' . implode(' ', $shell_command));
        $env_vars = Utilities::mergeData([
            'LANG' => '',
            'LC_CTYPE' => 'POSIX',
        ], $this->shellEnvironmentVars);

        $process = new Process(
            $shell_command,
            getcwd(),
            $env_vars
        );

        $process->setTimeout(0);

        return $process;
    }


    /**
     * Setup local shell.
     *
     * @throws \RuntimeException
     */
    public function setup()
    {
        if ($this->process) {
            return;
        }

        if (empty($this->hostConfig)) {
            throw new \RuntimeException('No host-config set for local shell provider');
        }

        $shell_executable = $this->hostConfig['shellExecutable'];
        $options = new ShellOptions();
        $options->setQuiet(false);
        $this->process = $this->createShellProcess([$shell_executable], $options);

        $this->input = new InputStream();
        $this->process->setInput($this->input);

        $this->process->start(function ($type, $buffer) {
            $lines = explode(PHP_EOL, $buffer);
            foreach ($lines as $line) {
                if (empty(trim($line))) {
                    continue;
                }
                if ($type == Process::ERR) {
                    if (!$this->captureOutput) {
                        fwrite(STDERR, $line . PHP_EOL);
                    } else {
                        $this->logger->debug(trim($line));
                    }
                } elseif ((!$this->captureOutput) && strpos($line, self::RESULT_IDENTIFIER) === false) {
                    if ($this->output) {
                        $this->output->writeln($line);
                    } else {
                        fwrite(STDOUT, $line . PHP_EOL);
                    }
                }
            }
        });
        if ($this->process->isTerminated() && !$this->process->isSuccessful()) {
            throw new \RuntimeException(sprintf(
                'Could not start shell via `%s`, exited with exit code %d, %s',
                $this->process->getCommandLine(),
                $this->process->getExitCode(),
                $this->process->getErrorOutput()
            ));
        }

        $environment = [];
        if (!empty($this->hostConfig['environment'])) {
            $environment = $this->hostConfig['environment'];

            $variables = [
                'settings' => $this->hostConfig->getConfigurationService()->getAllSettings(),
                'host' => $this->hostConfig->raw(),
            ];
            $replacements = Utilities::expandVariables($variables);
            $environment = Utilities::expandStrings($environment, $replacements);
            $environment = $this
                ->hostConfig
                ->getConfigurationService()
                ->getPasswordManager()
                ->resolveSecrets($environment);
        }

        $this->setupEnvironment($environment);
    }

    /**
     * Terminate current shell process.
     */
    public function terminate()
    {
        if ($this->process && !$this->process->isTerminated()) {
            $this->logger->info("Terminating current running shell ...");
            $this->process->stop();
        }
        $this->process = null;

        // Reset prefix.
        $this->logger->setPrefix(bin2hex(random_bytes(3)));
    }

    /**
     * Run a command in the shell.
     *
     * @param string $command
     * @param bool $capture_output
     * @param bool $throw_exception_on_error
     * @return CommandResult
     * @throws FailedShellCommandException
     * @throws \RuntimeException
     */
    public function run(string $command, $capture_output = false, $throw_exception_on_error = true): CommandResult
    {
        $scoped_capture_output = new SetAndRestoreObjProperty('captureOutput', $this, $capture_output);

        $command = $this->sendCommandToShell($command);

        // Get result.
        $result = '';
        $last_timestamp = time();
        while ((strpos($result, self::RESULT_IDENTIFIER) === false) && !$this->process->isTerminated()) {
            $partial = $this->process->getIncrementalOutput();
            $result .=  $partial;
            if (empty($partial) && !$this->process->isTerminated()) {
                usleep(1000 * 50);
                $delta = time() - $last_timestamp;
                if ($this->preventTimeout && $delta > 10) {
                    $this->logger->info('Sending a space to prevent timeout ...');
                    $this->input->write(' ');
                    $last_timestamp = time();
                }
            } else {
                $last_timestamp = time();
            }
        }
        if ($this->process->isTerminated()) {
            $this->logger->log($this->loglevel->get(), 'Local shell terminated unexpected, will start a new one!');
            $error_output = trim($this->process->getErrorOutput());
            $output = trim($this->process->getOutput());

            if (!empty($output)) {
                $this->logger->log($this->errorLogLevel->get(), $output);
            }
            if (!empty($error_output)) {
                $this->logger->log($this->errorLogLevel->get(), $error_output);
            }
            $exit_code = $this->process->getExitCode();
            $this->process = null;
            $cr = new CommandResult($exit_code, explode(PHP_EOL, $error_output));
            if ($throw_exception_on_error || $exit_code) {
                $cr->throwException(sprintf('`%s` failed!', $command));
            }
            return $cr;
        }

        $lines = explode(PHP_EOL, $result);
        do {
            $exit_code = array_pop($lines);
        } while (empty($exit_code));

        $matches = [];
        if (preg_match('/##RESULT:(\d*)$/', $exit_code, $matches)) {
            $exit_code = intval($matches[1]);
        }

        $cr = new CommandResult($exit_code, $lines);
        if ($cr->failed() && !$capture_output && $throw_exception_on_error) {
            $cr->throwException(sprintf('`%s` failed!', $command));
        }
        return $cr;
    }


    public function getShellCommand(array $program_to_call, ShellOptions $options):array
    {
        return $program_to_call;
    }

    public function exists($file): bool
    {
        return file_exists($file);
    }

    /**
     * @param string $source
     * @param string $dest
     * @param TaskContextInterface $context
     * @param bool $verbose
     * @return bool
     * @throws \Exception
     */
    public function putFile(string $source, string $dest, TaskContextInterface $context, bool $verbose = false): bool
    {
        $this->cd($context->getConfigurationService()->getFabfilePath());
        $result = $this->run(sprintf('cp -r "%s" "%s"', $source, $dest));
        $context->setResult('targetFile', $dest);

        return $result->succeeded();
    }

    /**
     * @param string $source
     * @param string $dest
     * @param TaskContextInterface $context
     * @param bool $verbose
     * @return bool
     * @throws \Exception
     */
    public function getFile(string $source, string $dest, TaskContextInterface $context, bool $verbose = false): bool
    {
        return $this->putFile($source, $dest, $context, $verbose);
    }


    public function startRemoteAccess(
        string $ip,
        int $port,
        string $public_ip,
        int $public_port,
        HostConfig $config,
        TaskContextInterface $context
    ) {
        throw new \InvalidArgumentException('Local shells cannot handle startRemoteAccess!');
    }

    public function createTunnelProcess(HostConfig $target_config, array $prefix = [])
    {
        throw new \InvalidArgumentException('Local shells cannot handle tunnels!');
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

    protected function overrideProcessInputAndOutput(Process $process, InputStream $input, OutputInterface $output)
    {
        $this->process = $process;
        $this->input = $input;
    }

    public function startSubShell(array $cmd): ShellProviderInterface
    {
        $this->sendCommandToShell(implode(' ', $cmd), false);
        return new SubShellProvider($this->logger, $this);
    }

    /**
     * @param string $command
     * @param bool $include_result_identifier
     *
     * @return false|string
     */
    protected function sendCommandToShell(string $command, bool $include_result_identifier = true)
    {
        $this->setup();
        $this->process->clearErrorOutput();
        $this->process->clearOutput();

        $password_manager = $this->getHostConfig()
            ->getConfigurationService()
            ->getPasswordManager();
        if ($password_manager) {
            $command = $password_manager->resolveSecrets($command);
        }

        $command = sprintf("cd %s && %s", $this->getWorkingDir(), $this->expandCommand($command));
        if (substr($command, -1) == ';') {
            $command = substr($command, 0, -1);
        }
        $this->logger->log($this->loglevel->get(), $command);

        // Send to shell.
        $input = $include_result_identifier
            ? $command . '; echo \n"' . self::RESULT_IDENTIFIER . '$?"' . PHP_EOL
            : $command . PHP_EOL;

        $this->input->write($input);
        return $command;
    }
}
