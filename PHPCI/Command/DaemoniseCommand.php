<?php
/**
 * PHPCI - Continuous Integration for PHP
 *
 * @copyright    Copyright 2014, Block 8 Limited.
 * @license      https://github.com/Block8/PHPCI/blob/master/LICENSE.md
 * @link         https://www.phptesting.org/
 */

namespace PHPCI\Command;

use Exception;
use Monolog\Logger;
use PHPCI\Helper\MutexLock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
* Daemon that loops and call the run-command.
* @author       Gabriel Baker <gabriel.baker@autonomicpilot.co.uk>
* @package      PHPCI
* @subpackage   Console
*/
class DaemoniseCommand extends Command
{
    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var OutputInterface
     */
    protected $output;

    /**
     * @var bool
     */
    protected $running;

    /**
     * @param Logger $logger
     * @param string $name
     */
    public function __construct(Logger $logger, $name = null)
    {
        parent::__construct($name);
        $this->logger = $logger;
    }

    protected function configure()
    {
        $this
            ->setName('phpci:daemonise')
            ->setDescription('Starts the daemon to run commands.');
    }

    /**
     * Loops through running.
     *
     * @SuppressWarnings("unused")
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $daemonLock = new MutexLock(PHPCI_DIR . 'daemon/daemon.pid');
        if (!$daemonLock->acquire()) {
            $pid = $daemonLock->getOwnerPid();
            throw new Exception("PID file locked; is another daemon already running with PID $pid ?");
        }

        $this->installSignalHandler();

        $this->logger->notice('Daemon started', array('pid' => getmypid()));

        $command = sprintf('php %s/console phpci:run-builds', PHPCI_DIR);
        $this->logger->debug("Using command '$command'");

        for ($this->running = true; $this->running; sleep(15)) {
            exec($command);
        }

        $this->logger->notice('Daemon exiting');
    }

    /**
     * Install a signal handler if the pcntl extension is available.
     */
    public function installSignalHandler()
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        pcntl_signal(SIGTERM, array($this, 'handleSignal'));
        pcntl_signal(SIGINT, array($this, 'handleSignal'));
        pcntl_signal(SIGCHLD, array($this, 'handleSignal'));
        pcntl_signal(SIGHUP, SIG_IGN);

        register_tick_function('pcntl_signal_dispatch');
        declare(ticks=5);
    }

    /**
     *
     * @param type $signo
     */
    public function handleSignal($signo)
    {
        $this->logger->info(sprintf('Received signal %d', $signo));

        // Shutdown on SIGTERM and SIGINT
        if ($signo === SIGTERM || $signo === SIGINT) {
            $this->running = false;
        }
    }
}
