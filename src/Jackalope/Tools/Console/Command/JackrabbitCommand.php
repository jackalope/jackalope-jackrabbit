<?php

namespace Jackalope\Tools\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use Jackalope\Tools\Console\Helper\JackrabbitHelper;

/**
 * @license http://www.apache.org/licenses Apache License Version 2.0, January 2004
 * @license http://opensource.org/licenses/MIT MIT License
 *
 * @author Daniel Barsotti <daniel.barsotti@liip.ch>
 */
class JackrabbitCommand extends Command
{
    /**
     * Path to Jackrabbit jar file
     * @var string
     */
    protected $jackrabbit_jar;

    /**
     * Path to the Jackrabbit workspace dir
     * @var string
     */
    protected $workspace_dir;

    /**
     * Configures the current command.
     */
    protected function configure()
    {
        $this->setName('jackalope:run:jackrabbit')
            ->addArgument('cmd', InputArgument::REQUIRED, 'Command to execute (start | stop | status)')
            ->addOption('jackrabbit_jar', null, InputOption::VALUE_OPTIONAL, 'Path to the Jackrabbit jar file')
            ->addOption('workspace_dir', null, InputOption::VALUE_OPTIONAL, 'Path to the Jackrabbit workspace dir')
            ->setDescription('Start and stop the Jackrabbit server')
            ->setHelp(<<<EOF
The <info>jackalope:run:jackrabbit</info> command allows to have a minimal
control on the Jackrabbit server from within a command.

If the <info>jackrabbit_jar</info> option is set, it will be used as the
Jackrabbit server jar file.
EOF
);
    }

    protected function setJackrabbitPath($jackrabbit_jar)
    {
        $this->jackrabbit_jar = $jackrabbit_jar;
    }

    protected function setWorkspaceDir($workspace_dir)
    {
        $this->workspace_dir = $workspace_dir;
    }

    /**
     * Executes the current command.
     *
     * @param InputInterface  $input  An InputInterface instance
     * @param OutputInterface $output An OutputInterface instance
     *
     * @return integer 0 if everything went fine, or an error code
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $cmd = $input->getArgument('cmd');

        if (! in_array(strtolower($cmd), array('start', 'stop', 'status'))) {
            $output->writeln($this->asText());

            return 1;
        }

        $jar = $input->getOption('jackrabbit_jar')?: $this->jackrabbit_jar;

        if (! $jar) {
            throw new \InvalidArgumentException('Either specify the path to the jackrabbit jar file or configure the command accordingly');
        }

        if (!file_exists($jar)) {
            throw new \Exception("Could not find the specified Jackrabbit .jar file '$jar'");
        }

        $workspace_dir = $input->getOption('workspace_dir')?:$this->workspace_dir?:null;

        if ($workspace_dir && !file_exists($workspace_dir)) {
            throw new \Exception("Could not find the specified directory'$workspace_dir'");
        }

        $helper = new JackrabbitHelper($jar, $workspace_dir);

        switch (strtolower($cmd)) {
            case 'start':
                $helper->startServer();
                break;
            case 'stop':
                $helper->stopServer();
                break;
            case 'status':
                $output->writeln("Jackrabbit server " . ($helper->isServerRunning() ? 'is running' : 'is not running'));
                break;
        }

        return 0;
    }
}
