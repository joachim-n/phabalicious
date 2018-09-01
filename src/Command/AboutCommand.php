<?php

namespace Phabalicious\Command;

use Phabalicious\Configuration\ConfigurationService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AboutCommand extends BaseCommand
{
    protected static $defaultName = 'about';

    public function __construct(ConfigurationService $configuration, $name = null) {
        parent::__construct($configuration, $name);
    }

    protected function configure()
    {
        parent::configure();
        $this
            ->setName('about')
            ->setDescription('shows the configuration')
            ->setHelp('Shows a detailed view of all configuration of that specific host');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        parent::execute($input, $output); // TODO: Change the autogenerated stub
        $config_name = $input->getOption('config');
        $host = $this->getConfiguration()->getHostConfig($config_name);
        $output->writeln('<options=bold>Configuration of ' . $config_name. '</>');
        $this->print($output, $host);
        if (!empty($host['docker']['configuration'])) {
            $docker_config_name = $host['docker']['configuration'];
            $docker_config = $this->getConfiguration()->getDockerConfig($docker_config_name);
            $output->writeln('<options=bold>Docker configuration of ' . $docker_config_name. '</>');
            $this->print($output, $docker_config, 2);
        }
    }

    private function print(OutputInterface $output, array $data, $level = 0) {
        ksort($data);
        foreach ($data as $key => $value) {
            if (is_numeric($key)) {
                $key = '-';
            }
            if (is_array($value)) {
                $output->writeln(str_pad('', $level) . str_pad($key, 30 - $level) . ' : ' );
                $this->print($output, $value, $level + 2);
            } else {
                $output->writeln(str_pad('', $level) . str_pad($key, 30 - $level) . ' : ' . $value);
            }
        }
    }

}