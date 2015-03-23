<?php
/**
 * PHPCI - Continuous Integration for PHP
 *
 * @copyright    Copyright 2014, Block 8 Limited.
 * @license      https://github.com/Block8/PHPCI/blob/master/LICENSE.md
 * @link         https://www.phptesting.org/
 */

namespace PHPCI\Command;

use b8\Config;
use b8\Store\Factory;
use Monolog\Logger;
use PHPCI\BuildFactory;
use PHPCI\Helper\Lang;
use PHPCI\Helper\Runner;
use PHPCI\Logging\LoggedBuildContextTidier;
use PHPCI\Logging\OutputLogHandler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
* Run console command - Runs any pending builds.
* @author       Dan Cryer <dan@block8.co.uk>
* @package      PHPCI
* @subpackage   Console
*/
class RunCommand extends Command
{
    /**
     * @var Logger
     */
    protected $logger;

    /**
     *
     * @var Runner
     */
    protected $runner;

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
            ->setName('phpci:run-builds')
            ->setDescription(Lang::get('run_all_pending'))
            ->addOption(
                'max-builds',
                'm',
                InputOption::VALUE_REQUIRED,
                "Maximum number of builds to run.",
                100
            );
    }

    /**
     * Sets up logging.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);

        $this->logger->pushProcessor(new LoggedBuildContextTidier());

        // For verbose mode we want to output all informational and above
        // messages to the symphony output interface.
        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $this->logger->pushHandler(new OutputLogHandler($output, Logger::INFO));
        }

        $this->runner = new Runner(
            $this->logger,
            Factory::getStore('Build'),
            Config::getInstance()->get('phpci.build.failed_after', 1800)
        );
    }

    /**
     * Pulls up to $maxBuilds pending builds from the database and runs them.
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $builds = (int)$input->getOption('max-builds');

        while(($build = $this->runner->next()) && $builds-- > 0) {
            $this->runner->run(BuildFactory::getBuild($build));
        }

        $this->logger->info(Lang::get('finished_processing_builds'));
    }
}
