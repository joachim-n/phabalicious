<?php

namespace Phabalicious\Command;

use Phabalicious\Exception\BlueprintTemplateNotFoundException;
use Phabalicious\Exception\FabfileNotFoundException;
use Phabalicious\Exception\FabfileNotReadableException;
use Phabalicious\Exception\MethodNotFoundException;
use Phabalicious\Exception\MismatchedVersionException;
use Phabalicious\Exception\MissingDockerHostConfigException;
use Phabalicious\Exception\ShellProviderNotFoundException;
use Phabalicious\Exception\TaskNotFoundInMethodException;
use Phabalicious\Method\TaskContext;
use Phabalicious\Utilities\Utilities;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

class VariablePull extends BaseCommand
{

    protected function configure()
    {
        parent::configure();
        $this
            ->setName('variable:pull')
            ->setDescription('Pulls a list of variables from a host ' .
                'and updates the given yaml file, or create it if it does not exist.')
            ->setHelp('Pulls a list of variables from a host ' .
                'and updates the given yaml file, or create it if it does not exist.');
        $this->addArgument('file', InputArgument::REQUIRED, 'yaml file to update, will be created if not existing');
        $this->addOption('output', 'o', InputOption::VALUE_OPTIONAL);
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|null
     * @throws BlueprintTemplateNotFoundException
     * @throws FabfileNotFoundException
     * @throws FabfileNotReadableException
     * @throws MethodNotFoundException
     * @throws MismatchedVersionException
     * @throws MissingDockerHostConfigException
     * @throws ShellProviderNotFoundException
     * @throws TaskNotFoundInMethodException
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($result = parent::execute($input, $output)) {
            return $result;
        }

        $context = new TaskContext($this, $input, $output);

        $context->set('action', 'pull');
        $filename = $input->getArgument('file');
        $data = Yaml::parseFile($filename);
        $context->set('data', $data);
        $this->getMethods()->runTask('variables', $this->getHostConfig(), $context);

        $data = Utilities::mergeData($data, $context->getResult('data', []));
        if ($input->getOption('output')) {
            $filename = $input->getOption('output');
        }
        if (file_put_contents($filename, Yaml::dump($data, 4, 2))) {
            $context->io()->success('Variables written to '. $filename);
            return 0;
        }

        $context->io()->error('Could not write to '.$filename);
        return 1;
    }
}
