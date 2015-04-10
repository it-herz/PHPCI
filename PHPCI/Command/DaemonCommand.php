<?php
/**
 * PHPCI - Continuous Integration for PHP
 *
 * @copyright    Copyright 2014, Block 8 Limited.
 * @license      https://github.com/Block8/PHPCI/blob/master/LICENSE.md
 * @link         https://www.phptesting.org/
 */

namespace PHPCI\Command;

use Monolog\Logger;
use PHPCI\Helper\MutexLock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
* Daemon that loops and call the run-command.
* @author       Gabriel Baker <gabriel.baker@autonomicpilot.co.uk>
* @package      PHPCI
* @subpackage   Console
*/
class DaemonCommand extends Command
{
    /**
     * @var Logger
     */
    protected $logger;

    public function __construct(Logger $logger, $name = null)
    {
        parent::__construct($name);
        $this->logger = $logger;
    }

    protected function configure()
    {
        $this
            ->setName('phpci:daemon')
            ->setDescription('Initiates the daemon to run commands.')
            ->addArgument(
                'state',
                InputArgument::REQUIRED,
                'start|stop|status'
            );
    }

    /**
     * Loops through running.
     *
     * @SuppressWarnings(unused)
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $state = $input->getArgument('state');

        switch ($state) {
            case 'start':
                $this->startDaemon();
                break;
            case 'stop':
                $this->stopDaemon();
                break;
            case 'status':
                $this->statusDaemon();
                break;
            default:
                echo "Not a valid choice, please use start stop or status";
                break;
        }

    }

    protected function startDaemon()
    {
        $lock = new MutexLock(PHPCI_DIR.'/daemon/daemon.pid');
        if ($lock->getOwnerPid()) {
            echo "Already started\n";
            $this->logger->warning("Daemon already started");
            return "alreadystarted";
        }

        $logfile = PHPCI_DIR."/daemon/daemon.log";
        $cmd = "nohup %s/daemonise phpci:daemonise > %s 2>&1 &";
        $command = sprintf($cmd, PHPCI_DIR, $logfile);
        $this->logger->info("Daemon started");
        exec($command);
    }

    protected function stopDaemon()
    {
        $lock = new MutexLock(PHPCI_DIR.'/daemon/daemon.pid');
        $pid = $lock->getOwnerPid();
        if (!$pid) {
            echo "Not started\n";
            $this->logger->warning("Can't stop daemon as not started");
            return "notstarted";
        }

        exec(sprintf("kill %d", $pid));
        $this->logger->info("Daemon stopped");
    }

    protected function statusDaemon()
    {
        $lock = new MutexLock(PHPCI_DIR.'/daemon/daemon.pid');
        if ($lock->getOwnerPid()) {
            echo "Running\n";
            return "running";
        }
        echo "Not running\n";
        return "notrunning";
    }
}
