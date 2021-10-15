<?php

namespace Phabalicious\Method;

use InvalidArgumentException;
use Phabalicious\Configuration\ConfigurationService;
use Phabalicious\Configuration\HostConfig;
use Phabalicious\Exception\FailedShellCommandException;
use Phabalicious\Exception\MethodNotFoundException;
use Phabalicious\Exception\TaskNotFoundInMethodException;
use Phabalicious\Exception\ValidationFailedException;
use Phabalicious\ShellProvider\ShellProviderInterface;
use Phabalicious\Validation\ValidationErrorBag;
use Phabalicious\Validation\ValidationErrorBagInterface;
use Phabalicious\Validation\ValidationService;
use RuntimeException;

abstract class DatabaseMethod extends BaseMethod implements DatabaseMethodInterface
{
    const DATABASE_CREDENTIALS = 'databaseCredentials';
    const DROP_DATABASE = "dropDatabase";

    public function getDefaultConfig(ConfigurationService $configuration_service, array $host_config): array
    {
        $config = parent::getDefaultConfig($configuration_service, $host_config);


        if (isset($host_config['database'])) {
            $config['database']['skipCreateDatabase'] = false;
            $config['database']['prefix'] = false;
            $config['database']['driver'] = $this->getName();
        }

        $config['supportsZippedBackups'] = true;

        return $config;
    }

    /**
     * @param HostConfig $host_config
     * @param TaskContextInterface $context
     * @param string $task
     *
     * @return bool
     */
    public function isRunningAppRequired(HostConfig $host_config, TaskContextInterface $context, string $task): bool
    {
        return parent::isRunningAppRequired($host_config, $context, $task) ||
            in_array($task, [
                'backup',
                'restore',
                'install',
                'listBackups',
                'restoreSqlFromFilePreparation',
                'restoreSqlFromFile',
                'getSQLDump',
                'copyFrom',
                'copyFromPrepareSource',
                'requestDatabaseCredentialsAndWorkingDir',
            ]);
    }

    /**
     * @param array $config
     * @param ValidationErrorBagInterface $errors
     */
    public function validateConfig(array $config, ValidationErrorBagInterface $errors)
    {
        parent::validateConfig($config, $errors); // TODO: Change the autogenerated stub

        $service = new ValidationService($config, $errors, sprintf('host: `%s`', $config['configName']));

        $service->hasKey('backupFolder', $this->getName() . ' needs to know where to store backups into');

        if (!empty($config['database'])) {
            $this->validateCredentials($config['database'], $errors);
        }
    }

    /**
     * @param HostConfig $host_config
     * @param TaskContextInterface $context
     */
    public function backup(HostConfig $host_config, TaskContextInterface $context)
    {
        $shell = $this->getShell($host_config, $context);
        $what = $context->get('what', []);
        if (!in_array('db', $what)) {
            return;
        }

        $basename = $context->getResult('basename');
        $backup_file_name = $host_config['backupFolder'] . '/' . implode('--', $basename) . '.sql';

        $backup_file_name = $this->exportSqlToFile($host_config, $context, $shell, $backup_file_name);

        $context->addResult('files', [[
            'type' => 'db',
            'file' => $backup_file_name
        ]]);

        $this->logger->notice('Database dumped to `' . $backup_file_name . '`');
    }

    /**
     * @param HostConfig $host_config
     * @param TaskContextInterface $context
     */
    public function getSQLDump(HostConfig $host_config, TaskContextInterface $context)
    {
        $filename = $host_config['tmpFolder'] . '/' . $host_config->getConfigName() . '.' . date('YmdHms') . '.sql';
        $shell = $this->getShell($host_config, $context);
        $filename = $this->exportSqlToFile($host_config, $context, $shell, $filename);

        $context->addResult('files', [$filename]);
    }

    public function listBackups(HostConfig $host_config, TaskContextInterface $context)
    {
        $shell = $this->getShell($host_config, $context);
        $files = $this->getRemoteFiles($shell, $host_config['backupFolder'], ['*.sql.gz', '*.sql']);
        $result = [];
        foreach ($files as $file) {
            $tokens = $this->parseBackupFile($host_config, $file, 'db');
            if ($tokens) {
                $result[] = $tokens;
            }
        }

        $existing = $context->getResult('files', []);
        $context->setResult('files', array_merge($existing, $result));
    }

    /**
     * @param HostConfig $host_config
     * @param TaskContextInterface $context
     *
     * @throws FailedShellCommandException
     */
    public function restore(HostConfig $host_config, TaskContextInterface $context)
    {
        $shell = $this->getShell($host_config, $context);
        $what = $context->get('what', []);
        if (!in_array('db', $what)) {
            return;
        }

        $backup_set = $context->get('backup_set', []);
        foreach ($backup_set as $elem) {
            if ($elem['type'] != 'db') {
                continue;
            }

            $result = $this->importSqlFromFile(
                $host_config,
                $context,
                $shell,
                $host_config['backupFolder'] . '/' . $elem['file'],
                true
            );

            if (!$result->succeeded()) {
                $result->throwException('Could not restore backup from ' . $elem['file']);
            }
            $context->addResult('files', [[
                'type' => 'db',
                'file' => $elem['file']
            ]]);
        }
    }

    /**
     * @param HostConfig $host_config
     * @param TaskContextInterface $context
     */
    public function restoreSqlFromFile(HostConfig $host_config, TaskContextInterface $context)
    {
        $file = $context->get('source', false);
        if (!$file) {
            throw new InvalidArgumentException('Missing file parameter');
        }
        $shell = $this->getShell($host_config, $context);

        $result = $this->importSqlFromFile(
            $host_config,
            $context,
            $shell,
            $file,
            $context->get(self::DROP_DATABASE, true)
        );

        $context->setResult('exitCode', $result->getExitCode());
    }

    /**
     * @param HostConfig $host_config
     * @param TaskContextInterface $context
     *
     * @throws FailedShellCommandException
     */
    public function copyFrom(HostConfig $host_config, TaskContextInterface $context)
    {
        $what = $context->get('what');
        if (!in_array('db', $what)) {
            return;
        }

        /** @var HostConfig $from_config */
        /** @var ShellProviderInterface $shell */
        /** @var ShellProviderInterface $from_shell */
        $from_config = $context->get('from', false);
        $shell = $this->getShell($host_config, $context);
        $from_shell = $context->get('fromShell', $from_config->shell());

        $from_filename = $from_config['tmpFolder'] . '/' . $from_config->getConfigName()
            . '.' . date('YmdHms') . '.sql';
        $from_filename = $this->exportSqlToFile($from_config, $context, $from_shell, $from_filename);

        $to_filename = $host_config['tmpFolder'] . '/to--' . basename($from_filename);

        // Copy filename to host
        $context->io()->comment(sprintf(
            'Copying dump from `%s` to `%s` ...',
            $from_config->getConfigName(),
            $host_config->getConfigName()
        ));

        $result = $shell->copyFileFrom($from_shell, $from_filename, $to_filename, $context, true);
        if (!$result) {
            throw new RuntimeException(
                sprintf('Could not copy file from `%s` to `%s`', $from_filename, $to_filename)
            );
        }
        $from_shell->run(sprintf(' rm %s', $from_filename));

        // Import db.
        $context->io()->comment(sprintf(
            'Importing dump into `%s` ...',
            $host_config->getConfigName()
        ));

        $result = $this->importSqlFromFile(
            $host_config,
            $context,
            $shell,
            $to_filename,
            $context->get(self::DROP_DATABASE, true)
        );
        if (!$result->succeeded()) {
            $result->throwException('Could not import DB from file `' . $to_filename . '`');
        }

        $shell->run(sprintf('rm %s', $to_filename));

        $context->io()->success('Copied the database successfully!');
    }

    /**
     * @param HostConfig $host_config
     * @param TaskContextInterface $context
     */
    public function appCreate(HostConfig $host_config, TaskContextInterface $context)
    {
        if (!$current_stage = $context->get('currentStage', false)) {
            throw new InvalidArgumentException('Missing currentStage on context!');
        }
        if ($current_stage === 'install') {
            $this->waitForDatabase($host_config, $context);
            $this->install($host_config, $context);
        }
    }

    /**
     * @param HostConfig $config
     * @param TaskContextInterface $context
     */
    public function collectBackupMethods(HostConfig $config, TaskContextInterface $context)
    {
        $context->addResult('backupMethods', ['db']);
    }

    /**
     * @param HostConfig $host_config
     * @param TaskContextInterface $context
     * @param array $credentials
     *
     * @return mixed
     * @throws MethodNotFoundException
     * @throws TaskNotFoundInMethodException
     * @throws ValidationFailedException
     */
    protected function requestCredentialsAndWorkingDir(
        HostConfig $host_config,
        TaskContextInterface $context,
        array $credentials
    ) {
        $cloned_context = clone($context);
        $cloned_context->set(self::DATABASE_CREDENTIALS, $credentials);
        $context->getConfigurationService()->getMethodFactory()->runTask(
            'requestDatabaseCredentialsAndWorkingDir',
            $host_config,
            $cloned_context
        );
        $data = $cloned_context->getResult(self::DATABASE_CREDENTIALS, $credentials);
        $errors = new ValidationErrorBag();
        $this->validateCredentials($data, $errors);
        if ($errors->hasErrors()) {
            throw new ValidationFailedException($errors);
        }

        return $data;
    }


    public function waitForDatabase(HostConfig $host_config, TaskContextInterface $context): bool
    {
        $shell = $this->getShell($host_config, $context);
        $tries = 0;
        $result = false;
        while ($tries < 10) {
            $result = $this->checkDatabaseConnection($host_config, $context, $shell);
            if ($result->succeeded()) {
                return true;
            }
            $this->logger->info(sprintf(
                'Wait another 5 secs for database at %s@%s',
                $host_config['database']['host'],
                $host_config['database']['user']
            ));

            sleep(5);
            $tries++;
        }
        if ($result) {
            $result->throwException('Could not connect to database!');
        }
        return false;
    }

    /**
     * @param HostConfig $host_config
     * @param TaskContextInterface $context
     *
     * @return array
     * @throws MethodNotFoundException
     * @throws TaskNotFoundInMethodException
     * @throws ValidationFailedException
     */
    protected function getDatabaseCredentials(HostConfig $host_config, TaskContextInterface $context): array
    {
        $data = !empty($host_config['database']) ? $host_config['database'] : [];
        return $this->requestCredentialsAndWorkingDir($host_config, $context, $data);
    }

    /**
     * @throws TaskNotFoundInMethodException
     * @throws MethodNotFoundException
     * @throws ValidationFailedException
     */
    public function database(HostConfig $host_config, TaskContextInterface $context)
    {
        $what = $context->get('what');
        $data = $this->getDatabaseCredentials($host_config, $context);
        $shell = $this->getShell($host_config, $context);

        switch ($what) {
            case 'install':
                return $this->install($host_config, $context);
                break;

            case 'drop':
                return $this->dropDatabase($host_config, $context, $shell, $data);
                break;
        }
        throw new RuntimeException(sprintf("Unknown database command `%s`", $what));
    }
}
