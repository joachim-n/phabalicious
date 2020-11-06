<?php

namespace Phabalicious\Method;

use Phabalicious\Configuration\ConfigurationService;
use Phabalicious\Configuration\HostConfig;
use Phabalicious\Configuration\HostType;
use Phabalicious\Exception\ValidationFailedException;
use Phabalicious\ShellProvider\ShellProviderInterface;
use Phabalicious\Utilities\Utilities;
use Phabalicious\Validation\ValidationErrorBag;
use Phabalicious\Validation\ValidationErrorBagInterface;
use Phabalicious\Validation\ValidationService;
use Symfony\Component\Console\Output\OutputInterface;

class DrushMethod extends BaseMethod implements MethodInterface
{

    public function getName(): string
    {
        return 'drush';
    }

    public function supports(string $method_name): bool
    {
        return (in_array($method_name, ['drush', 'drush7', 'drush8', 'drush9']));
    }

    public function getGlobalSettings(): array
    {
        return [
            'adminUser' => 'admin',
            'executables' => [
                'drush' => 'drush',
                'mysql' => 'mysql',
                'grep' => 'grep',
                'mysqladmin' => 'mysqladmin',
                'gunzip' => 'gunzip',
                'chmod' => 'chmod',
                'sed' => 'sed',
            ],
            'sqlSkipTables' => [
                'cache',
                'cache_block',
                'cache_bootstrap',
                'cache_field',
                'cache_filter',
                'cache_form',
                'cache_menu',
                'cache_page',
                'cache_path',
                'cache_update',
                'cache_views',
                'cache_views_data',
            ],
            'revertFeatures' => true,
            'replaceSettingsFile' => true,
            'alterSettingsFile' => true,
            'configurationManagement' => [
                'staging' => [
                    '#!drush config-import -y staging'
                ],
            ],
            'installOptions' => [
                'distribution' => 'minimal',
                'locale' => 'en',
                'options' => '',
            ]
        ];
    }

    public function getKeysForDisallowingDeepMerge(): array
    {
        return ['configurationManagement'];
    }

    public function getDefaultConfig(ConfigurationService $configuration_service, array $host_config): array
    {
        $config = parent::getDefaultConfig($configuration_service, $host_config);

        $keys = ['adminUser', 'revertFeatures', 'replaceSettingsFile', 'configurationManagement', 'installOptions'];
        foreach ($keys as $key) {
            $config[$key] = $configuration_service->getSetting($key);
        }

        $config['adminPass'] = $configuration_service->getSetting(
            'adminPass',
            base64_encode('!admin%' . ($host_config['config_name'] ?? 'whatever') . '4')
        );
        if (isset($host_config['database'])) {
            $config['database']['host'] = 'localhost';
            $config['database']['skipCreateDatabase'] = false;
            $config['database']['prefix'] = false;
        }

        $config['drupalVersion'] = in_array('drush7', $host_config['needs'])
            ? 7
            : $configuration_service->getSetting('drupalVersion', 8);

        $config['drushVersion'] = in_array('drush9', $host_config['needs'])
            ? 9
            : $configuration_service->getSetting('drushVersion', 8);

        $config['sqlDumpCommand'] = 'sql-dump';
        $config['sanitizeOnReset'] = false;
        $config['supportsZippedBackups'] = true;
        $config['siteFolder'] = '/sites/default';
        $config['filesFolder'] = '/sites/default/files';

        return $config;
    }

    public function validateConfig(array $config, ValidationErrorBagInterface $errors)
    {
        parent::validateConfig($config, $errors); // TODO: Change the autogenerated stub

        $service = new ValidationService($config, $errors, sprintf('host: `%s`', $config['configName']));

        $service->hasKey('drushVersion', 'the major version of the installed drush tool');
        $service->hasKey('drupalVersion', 'the major version of the drupal-instance');
        $service->hasKey('siteFolder', 'drush needs a site-folder to locate the drupal-instance');
        $service->hasKey('filesFolder', 'drush needs to know where files are stored for this drupal instance');
        $service->hasKey('backupFolder', 'drush needs to know where to store backups into');
        $service->hasKey('tmpFolder', 'drush needs to know where to store temporary files');

        if (!empty($config['database'])) {
            $service = new ValidationService($config['database'], $errors, 'host.database');
            $service->hasKeys([
                'host' => 'the database-host',
                'user' => 'the database user',
                'pass' => 'the password for the database-user',
                'name' => 'the database name to use',
            ]);
        }

        if (array_intersect($config['needs'], ['drush7', 'drush8', 'drush9'])) {
            $errors->addWarning(
                'needs',
                '`drush7`, `drush8` and `drush9` are deprecated, ' .
                'please replace with `drush` and set `drupalVersion` and `drushVersion` accordingly.'
            );
        }
    }

    /**
     * @param ConfigurationService $configuration_service
     * @param array $data
     * @throws ValidationFailedException
     */
    public function alterConfig(ConfigurationService $configuration_service, array &$data)
    {
        parent::alterConfig($configuration_service, $data);

        $data['siteFolder'] = Utilities::prependRootFolder($data['rootFolder'], $data['siteFolder']);
        $data['filesFolder'] = Utilities::prependRootFolder($data['rootFolder'], $data['filesFolder']);

        // Late validation of uuid + drupal 8+.
        if ($data['drupalVersion'] >= 8 && !$configuration_service->getSetting('uuid')) {
            $errors = new ValidationErrorBag();
            $errors->addError('global', 'Drupal 8 needs a global uuid-setting');
            throw new ValidationFailedException($errors);
        }
    }

    private function runDrush(ShellProviderInterface $shell, $cmd, ...$args)
    {
        array_unshift($args, '#!drush ' . $cmd);
        $command = call_user_func_array('sprintf', $args);
        return $shell->run($command, false, false);
    }

    /**
     * @param HostConfig $host_config
     * @param TaskContextInterface $context
     * @throws \Phabalicious\Exception\MethodNotFoundException
     * @throws \Phabalicious\Exception\MissingScriptCallbackImplementation
     */
    public function reset(HostConfig $host_config, TaskContextInterface $context)
    {
        /** @var ShellProviderInterface $shell */
        $shell = $this->getShell($host_config, $context);
        $shell->cd($host_config['siteFolder']);

        /** @var ScriptMethod $script_method */
        $script_method = $context->getConfigurationService()->getMethodFactory()->getMethod('script');

        if ($host_config->get('sanitizeOnReset', false)) {
            $this->runDrush($shell, 'sql-sanitize -y');
        }

        if ($host_config->isType(HostType::DEV)) {
            $admin_user = $host_config['adminUser'];
            $admin_pass = $host_config['adminPass'];

            if ($context->get('withPasswordReset', true)) {
                if ($host_config['drushVersion'] >= 9) {
                    $command = sprintf('user:password %s "%s"', $admin_user, $admin_pass);
                } else {
                    $command = sprintf('user-password %s --password="%s"', $admin_user, $admin_pass);
                }
                $this->runDrush($shell, $command);
            }

            $shell->run(sprintf('chmod -R 777 %s', $host_config['filesFolder']));
        }

        if ($deployment_module = $context->getConfigurationService()->getSetting('deploymentModule')) {
            $this->runDrush($shell, 'en -y %s', $deployment_module);
        }

        $this->handleModules($host_config, $context, $shell, 'modules_enabled.txt', true);
        $this->handleModules($host_config, $context, $shell, 'modules_disabled.txt', false);

        // Database updates
        if ($host_config['drupalVersion'] >= 8) {
            $this->runDrush($shell, 'cr -y');
            $this->runDrush($shell, 'updb -y');
        } else {
            $this->runDrush($shell, 'updb -y ');
        }

        // CMI / Features
        if ($host_config['drupalVersion'] >= 8) {
            $uuid = $context->getConfigurationService()->getSetting('uuid');
            $this->runDrush($shell, 'cset system.site uuid %s -y', $uuid);

            if (!empty($host_config['configurationManagement']) && $context->getResult('configurationExists', true)) {
                $script_context = clone $context;
                foreach ($host_config['configurationManagement'] as $key => $cmds) {
                    $script_context->set(ScriptMethod::SCRIPT_DATA, $cmds);
                    $script_context->set('rootFolder', $host_config['siteFolder']);
                    $script_method->runScript($host_config, $script_context);
                }
            }
        } else {
            if ($host_config['revertFeatures']) {
                $this->runDrush($shell, 'fra -y');
            }
        }

        $context->set('rootFolder', $host_config['siteFolder']);
        $script_method->runTaskSpecificScripts($host_config, 'reset', $context);

        // Keep calm and clear the cache.
        if ($host_config['drupalVersion'] >= 8) {
            $this->runDrush($shell, 'cr -y');
            $this->runDrush($shell, sprintf('state-set installation_type %s', $host_config['type']));
        } else {
            $this->runDrush($shell, 'cc all -y');
        }
    }

    public function drush(HostConfig $host_config, TaskContextInterface $context)
    {
        $command = $context->get('command');

        /** @var ShellProviderInterface $shell */
        $shell = $this->getShell($host_config, $context);
        $shell->cd($host_config['siteFolder']);
        $context->setResult('shell', $shell);
        $command = sprintf(
            'cd %s;  #!drush  %s',
            $host_config['siteFolder'],
            $command
        );
        $command = $shell->expandCommand($command);
        $context->setResult('command', [
            $command
        ]);
    }

    private function handleModules(
        HostConfig $host_config,
        TaskContextInterface $context,
        ShellProviderInterface $shell,
        string $file_name,
        bool $should_enable
    ) {
        $file = $host_config['rootFolder'] . '/' . $file_name;
        if (!$shell->exists($file)) {
            return;
        }
        $content = $shell->run('cat ' . $file, true);

        $modules = array_filter($content->getOutput(), 'trim');
        $key = $should_enable ? 'modulesEnabledIgnore' : 'modulesDisabledIgnore';

        $to_ignore = $context->getConfigurationService()->getSetting($key, []);
        if (count($to_ignore) > 0) {
            $this->logger->notice(sprintf(
                'Ignoring %s while %s modules from %s',
                implode(' ', $to_ignore),
                $should_enable ? 'enabling' : 'disabling',
                $host_config['configName']
            ));

            $modules = array_diff($modules, $to_ignore);
        }
        $drush_command = ($should_enable) ? 'en -y %s' : 'dis -y %s';

        if (!$context->getOutput() || ($context->getOutput()->getVerbosity() < OutputInterface::VERBOSITY_VERBOSE)) {
            $modules = [
                implode(' ', $modules),
            ];
        }

        foreach ($modules as $module) {
            $result = $this->runDrush($shell, $drush_command, $module);
            if ($result->failed()) {
                $result->throwException(sprintf(
                    'Drush reported an error while handling %s and module %s. ' .
                    'Please check the output, there might be an error in the file.',
                    $file_name,
                    $module
                ));
            }
        }
    }

    public function install(HostConfig $host_config, TaskContextInterface $context)
    {
        /** @var ShellProviderInterface $shell */
        $shell = $this->getShell($host_config, $context);

        // Determine what kind of install operation this will be.
        $context->setResult(
            'settingsFileExists',
            $shell->exists($host_config['siteFolder'] . '/settings.php')
        );
        $context->setResult(
            'configurationExists',
            $shell->exists($this->getConfigSyncDirectory($host_config) . '/core.extension.yml')
        );

        $shell->cd($host_config['rootFolder']);
        $shell->run(sprintf('mkdir -p %s', $host_config['siteFolder']));

        // Create DB.
        $shell->cd($host_config['siteFolder']);
        $o = $host_config['database'] ?? false;
        if ($o && !$host_config['database']['skipCreateDatabase']) {
            $cmd = 'CREATE DATABASE IF NOT EXISTS ' . $o['name'] . '; ' .
                'GRANT ALL PRIVILEGES ON ' . $o['name'] . '.* ' .
                'TO \'' . $o['user'] . '\'@\'%\' ' .
                'IDENTIFIED BY \'' . $o['pass'] . '\';' .
                'FLUSH PRIVILEGES;';
            $shell->run('#!mysql' .
                ' -h ' . $o['host'] .
                ' -u ' . $o['user'] .
                ' --password="' . $o['pass'] . '"' .
                ' -e "' . $cmd . '"');
        }

        // Prepare settings.php
        $shell->run(sprintf('#!chmod u+w %s', $host_config['siteFolder']));

        if ($context->getResult('settingsFileExists')) {
            $shell->run(sprintf('#!chmod u+w %s/settings.php', $host_config['siteFolder']));
            if ($host_config['replaceSettingsFile']) {
                $shell->run(sprintf('rm -f %s/settings.php.old', $host_config['siteFolder']));
                $shell->run(sprintf(
                    'mv %s/settings.php %s/settings.php.old 2>/dev/null',
                    $host_config['siteFolder'],
                    $host_config['siteFolder']
                ));
            }
        }

        // Install drupal, this can be skipped if install from configuration is
        // possible.
        if (!$context->getResult('settingsFileExists') || !$context->getResult('configurationExists')) {
            $cmd_options = '';
            $cmd_options .= ' -y';
            $cmd_options .= ' --sites-subdir=' . basename($host_config['siteFolder']);
            $cmd_options .= ' --account-name=' . $host_config['adminUser'];
            $cmd_options .= sprintf(' --account-pass="%s"', $host_config['adminPass']);
            $cmd_options .= ' --locale=' . $host_config['installOptions']['locale'];

            if ($o) {
                if ($host_config['database']['prefix']) {
                    $cmd_options .= ' --db-prefix=' . $host_config['database']['prefix'];
                }
                $cmd_options .= ' --db-url=mysql://' . $o['user'] . ':' . $o['pass'] . '@' .
                    $o['host'] . '/' . $o['name'];
            }
            $cmd_options .= ' ' . $host_config['installOptions']['options'];
            $this->runDrush($shell, 'site-install %s %s', $host_config['installOptions']['distribution'], $cmd_options);
            $this->setupConfigurationManagement($host_config, $context);
        }
        // Run --existing-config install if config sync dir contains config.
        if ($context->getResult('configurationExists')) {
            $this->runDrush($shell, 'site-install -y --existing-config');
        }
    }

    protected function backupSQL(
        HostConfig $host_config,
        TaskContextInterface $context,
        ShellProviderInterface $shell,
        string $backup_file_name
    ) {
        $context->io()->comment(sprintf('Dumping database of `%s` ...', $host_config['configName']));
        $shell->cd($host_config['siteFolder']);

        $dump_options = '';
        if ($skip_tables = $context->getConfigurationService()->getSetting('sqlSkipTables')) {
            $dump_options .= ' --structure-tables-list=' . implode(',', $skip_tables);
        }
        if (!$shell->exists(dirname($backup_file_name))) {
            $shell->run(sprintf('mkdir -p %s', dirname($backup_file_name)));
        }

        if ($host_config['supportsZippedBackups']) {
            $shell->run(sprintf('rm -f %s.gz', $backup_file_name));
            $dump_options .= ' --gzip';
            $return = $backup_file_name . '.gz';
        } else {
            $shell->run(sprintf('rm -f %s', $backup_file_name));
            $return = $backup_file_name;
        }

        $sql_dump_cmd = $host_config->get('sqlDumpCommand', 'sql-dump');
        $this->runDrush($shell, '%s %s --result-file=%s', $sql_dump_cmd, $dump_options, $backup_file_name);
        return $return;
    }

    public function backup(HostConfig $host_config, TaskContextInterface $context)
    {
        $shell = $this->getShell($host_config, $context);
        $what = $context->get('what', []);
        if (!in_array('db', $what)) {
            return;
        }

        $basename = $context->getResult('basename');
        $backup_file_name = $host_config['backupFolder'] . '/' . implode('--', $basename) . '.sql';

        $backup_file_name = $this->backupSQL($host_config, $context, $shell, $backup_file_name);

        $context->addResult('files', [[
            'type' => 'db',
            'file' => $backup_file_name
        ]]);

        $this->logger->notice('Database dumped to `' . $backup_file_name . '`');
    }

    public function getSQLDump(HostConfig $host_config, TaskContextInterface $context)
    {
        $filename = $host_config['tmpFolder'] . '/' . $host_config['configName'] . '.' . date('YmdHms') . '.sql';
        $shell = $this->getShell($host_config, $context);
        $filename = $this->backupSQL($host_config, $context, $shell, $filename);

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
     * @throws \Phabalicious\Exception\FailedShellCommandException
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

            $shell->pushWorkingDir($host_config['siteFolder']);
            $result = $this->importSqlFromFile($shell, $host_config['backupFolder'] . '/' . $elem['file']);
            $shell->popWorkingDir();

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
     * @param ShellProviderInterface $shell
     * @param string $file
     * @param bool $drop_db
     * @return \Phabalicious\ShellProvider\CommandResult
     */
    private function importSqlFromFile(ShellProviderInterface $shell, string $file, $drop_db = false)
    {
        $this->logger->notice('Restoring db from ' . $file);
        if ($drop_db) {
            $this->runDrush($shell, 'sql-drop -y');
        }

        if (substr($file, strrpos($file, '.') + 1) == 'gz') {
            return $shell->run(sprintf('#!gunzip -c %s | $(#!drush sql-connect)', $file));
        }
        return $this->runDrush($shell, 'sql-cli < %s', $file);
    }


    public function restoreSqlFromFile(HostConfig $host_config, TaskContextInterface $context)
    {
        $file = $context->get('source', false);
        if (!$file) {
            throw new \InvalidArgumentException('Missing file parameter');
        }
        $shell = $this->getShell($host_config, $context);

        $shell->pushWorkingDir($host_config['siteFolder']);
        $result = $this->importSqlFromFile($shell, $file);
        $shell->popWorkingDir();

        $context->setResult('exitCode', $result->getExitCode());
    }

    /**
     * @param HostConfig $host_config
     * @param TaskContextInterface $context
     * @throws \Phabalicious\Exception\FailedShellCommandException
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

        $from_filename = $from_config['tmpFolder'] . '/' . $from_config['configName'] . '.' . date('YmdHms') . '.sql';
        $from_filename = $this->backupSQL($from_config, $context, $from_shell, $from_filename);

        $to_filename = $host_config['tmpFolder'] . '/to--' . basename($from_filename);

        // Copy filename to host
        $context->io()->comment(sprintf(
            'Copying dump from `%s` to `%s` ...',
            $from_config['configName'],
            $host_config['configName']
        ));

        $result = $shell->copyFileFrom($from_shell, $from_filename, $to_filename, $context, true);
        if (!$result) {
            throw new \RuntimeException(
                sprintf('Could not copy file from `%s` to `%s`', $from_filename, $to_filename)
            );
        }
        $from_shell->run(sprintf(' rm %s', $from_filename));

        // Import db.
        $context->io()->comment(sprintf(
            'Importing dump into `%s` ...',
            $host_config['configName']
        ));

        $shell->cd($host_config['siteFolder']);
        $result = $this->importSqlFromFile($shell, $to_filename, true);
        if (!$result->succeeded()) {
            $result->throwException('Could not import DB from file `' . $to_filename . '`');
        }

        $shell->run(sprintf('rm %s', $to_filename));

        $context->io()->success('Copied the database successfully!');
    }

    public function appUpdate(HostConfig $host_config, TaskContextInterface $context)
    {
        if (in_array('composer', $host_config['needs'])) {
            // Project is handled by composer, will handle the update.
            return;
        }


        $this->logger->notice('Updating drupal core');
        $shell = $this->getShell($host_config, $context);
        $pwd = $shell->getWorkingDir();
        $install_dir = $host_config['tmpFolder'] . '/drupal-update';
        $shell->run(sprintf('rm -rf %s', $install_dir));
        $shell->run(sprintf('mkdir -p %s', $install_dir));
        $result = $this->runDrush(
            $shell,
            'dl --destination="%s" --default-major="%d" drupal',
            $install_dir,
            $host_config['drupalVersion']
        );

        if ($result->failed()) {
            throw new \RuntimeException('Could not download drupal via drush!');
        }

        $shell->cd($install_dir);
        $result = $shell->run('ls ', true);
        $drupal_folder = trim($result->getOutput()[0]);
        $shell->run(sprintf('#!rsync -rav --no-o --no-g %s/* %s', $drupal_folder, $host_config['rootFolder']));
        $shell->run(sprintf('rm -rf %s', $install_dir));

        $shell->cd($pwd);
    }

    /**
     * @param HostConfig $host_config
     * @param TaskContextInterface $context
     * @throws \Phabalicious\Exception\FailedShellCommandException
     */
    public function appCreate(HostConfig $host_config, TaskContextInterface $context)
    {
        if (!$current_stage = $context->get('currentStage', false)) {
            throw new \InvalidArgumentException('Missing currentStage on context!');
        }
        if ($current_stage === 'install') {
            $this->waitForDatabase($host_config, $context);
            $this->install($host_config, $context);
        }
    }

    /**
     * @param HostConfig $host_config
     * @param TaskContextInterface $context
     * @return bool
     * @throws \Phabalicious\Exception\FailedShellCommandException
     */
    private function waitForDatabase(HostConfig $host_config, TaskContextInterface $context)
    {
        $shell = $this->getShell($host_config, $context);
        $tries = 0;
        $result = false;
        while ($tries < 10) {
            $result = $shell->run(sprintf(
                '#!mysqladmin -u%s --password=%s -h %s ping',
                $host_config['database']['user'],
                $host_config['database']['pass'],
                $host_config['database']['host']
            ), true, false);
            if ($result->succeeded()) {
                return true;
            }
            $this->logger->info(sprintf(
                'Wait another 5 secs for database at %s@%s',
                $host_config['database']['host'],
                $host_config['database']['user']
            ));

            sleep(5);
        }
        if ($result) {
            $result->throwException('Could not connect to database!');
        }
        return false;
    }

    private function getConfigSyncDirectory(HostConfig $host_config)
    {
        return $host_config['rootFolder'] . '/../config/' . array_key_last($host_config['configurationManagement']);
    }

    private function setupConfigurationManagement(HostConfig $host_config, TaskContextInterface $context)
    {
        if ($host_config['drupalVersion'] < 8 || empty($host_config['alterSettingsFile']) || $context->getResult('configurationExists')) {
            return;
        }

        $shell = $this->getShell($host_config, $context);
        $cwd = $shell->getWorkingDir();
        $shell->cd($host_config['siteFolder']);
        $shell->run('#!chmod u+w .');
        $shell->run('#!chmod u+w settings.php');

        $shell->run('#!sed -i "/\$config_directories\[/d" settings.php');
        $shell->run('#!sed -i "/\$settings\[\'config_sync_directory/d" settings.php');
        foreach ($host_config['configurationManagement'] as $key => $data) {
            $shell->run(sprintf(
                'echo "\$settings[\'config_sync_directory\'] = \'%s\';" >> settings.php',
                '../config/' . $key
            ));
            $shell->run(sprintf(
                'echo "\$config_directories[\'%s\'] = \'%s\';" >> settings.php',
                $key,
                '../config/' . $key
            ));
        }

        $shell->cd($cwd);
    }

    public function variables(HostConfig $host_config, TaskContextInterface $context)
    {
        $result = $context->get('data', []);
        $what = $context->get('action', 'pull');

        $context->io()->progressStart(count($result));

        foreach ($result as $key => $value) {
            if ($what == 'pull') {
                $this->logger->info(sprintf('Pulling `%s` from `%s`', $key, $host_config['configName']));
                $result[$key] = $this->getVariable($host_config, $context, $key);
            } elseif ($what == 'push' && !is_null($value)) {
                $this->logger->info(sprintf('Pushing `%s` to `%s`', $key, $host_config['configName']));
                $this->putVariable($host_config, $context, $key, $value);
            }
            $context->io()->progressAdvance();
        }
        $context->io()->progressFinish();
        $context->setResult('data', $result);
    }

    private function getVariable(HostConfig $host_config, TaskContextInterface $context, $key)
    {
        /** @var ShellProviderInterface $shell */
        $shell = $context->get('shell', $host_config->shell());
        if ($host_config['drupalVersion'] == 7) {
            $shell->pushWorkingDir($host_config['siteFolder']);
            $output = $shell->run(sprintf('#!drush variable-get --format=json %s', $key), true, false);
            $shell->popWorkingDir();
            if ($output->failed()) {
                if (!empty($output->getOutput())) {
                    $this->logger->error(implode("\n", $output->getOutput()));
                }
                return null;
            }
            $json = json_decode(implode("\n", $output->getOutput()), true);
            return isset($json[$key]) ? $json[$key] : null;
        }

        throw new \InvalidArgumentException('getVariable is not implemented for that particular drupal version.');
    }

    private function putVariable(HostConfig $host_config, TaskContextInterface $context, $key, $value)
    {
        /** @var ShellProviderInterface $shell */
        $shell = $context->get('shell', $host_config->shell());
        if ($host_config['drupalVersion'] == 7) {
            $shell->pushWorkingDir($host_config['siteFolder']);
            $output = $shell->run(sprintf(
                '#!drush variable-set --yes --format=json %s \'%s\'',
                $key,
                json_encode($value)
            ), true);
            $shell->popWorkingDir();
            if ($output->failed()) {
                throw new \RuntimeException($output->getOutput());
            }
            return;
        }
        throw new \InvalidArgumentException('putVariable is not implemented for that particular drupal version.');
    }
}
