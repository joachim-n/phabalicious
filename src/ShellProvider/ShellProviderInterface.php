<?php

namespace Phabalicious\ShellProvider;

use Phabalicious\Configuration\ConfigurationService;
use Phabalicious\Configuration\HostConfig;
use Phabalicious\Method\TaskContextInterface;
use Phabalicious\ScopedLogLevel\LogLevelStackGetterInterface;
use Phabalicious\Validation\ValidationErrorBagInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

interface ShellProviderInterface extends LogLevelStackGetterInterface
{
    public function getName(): string;

    public function getDefaultConfig(ConfigurationService $configuration_service, array $host_config): array;

    public function validateConfig(array $config, ValidationErrorBagInterface $errors);

    public function setHostConfig(HostConfig $config);

    public function getHostConfig(): ?HostConfig;

    public function getWorkingDir(): string;

    public function pushWorkingDir(string $new_working_dir);

    public function popWorkingDir();

    public function exists($file): bool;

    public function cd(string $dir): ShellProviderInterface;

    public function run(string $command, $capture_output = false, $throw_exception_on_error = false): CommandResult;

    public function setupEnvironment(array $environment);

    public function applyEnvironment(array $environment);

    public function setOutput(OutputInterface $output);

    public function getFile(string $source, string $dest, TaskContextInterface $context, bool $verbose = false): bool;

    public function putFile(string $source, string $dest, TaskContextInterface $context, bool $verbose = false): bool;

    public function copyFileFrom(
        ShellProviderInterface $from_shell,
        string $source_file_name,
        string $target_file_name,
        TaskContextInterface $context,
        bool $verbose = false
    ): bool;

    public function startRemoteAccess(
        string $ip,
        int $port,
        string $public_ip,
        int $public_port,
        HostConfig $config,
        TaskContextInterface $context
    );

    public function expandCommand($line);

    public function runProcess(array $cmd, TaskContextInterface $context, $interactive = false, $verbose = false):bool;

    public function getShellCommand(array $program_to_call, ShellOptions $options): array;

    public function createShellProcess(array $command = [], ShellOptions $options = null): Process;

    public function createTunnelProcess(HostConfig $target_config, array $prefix = []);

    /**
     * Wrap a command to execute into a login shell.
     *
     * @param array $command
     * @return array
     */
    public function wrapCommandInLoginShell(array $command);

    /**
     * Get the rsync options from the shell providers.
     *
     * @param \Phabalicious\Configuration\HostConfig $to_host_config
     * @param \Phabalicious\Configuration\HostConfig $from_host_config
     * @param string $to_path
     * @param string $from_path
     *
     * @return false|array
     */
    public function getRsyncOptions(
        HostConfig $to_host_config,
        HostConfig $from_host_config,
        string $to_path,
        string $from_path
    );

    /**
     * Terminates a running shell, so that it gets recreated with the next command.
     */
    public function terminate();

    public function startSubShell(array $cmd): ShellProviderInterface;

    public function getFileContents($filename, TaskContextInterface $context);

    public function putFileContents($filename, $data, TaskContextInterface $context);

    public function realPath($filename, TaskContextInterface $context);
}
