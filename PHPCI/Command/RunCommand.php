<?php
/**
 * PHPCI - Continuous Integration for PHP
 *
 * @copyright    Copyright 2014, Block 8 Limited.
 * @license      https://github.com/Block8/PHPCI/blob/master/LICENSE.md
 * @link         https://www.phptesting.org/
 */

namespace PHPCI\Command;

use b8\Store\Factory;
use DateTime;
use Exception;
use Monolog\Logger;
use PHPCI\Builder;
use PHPCI\BuildFactory;
use PHPCI\Helper\Lang;
use PHPCI\Logging\BuildDBLogHandler;
use PHPCI\Logging\LoggedBuildContextTidier;
use PHPCI\Logging\OutputLogHandler;
use PHPCI\Model\Build;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
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
     * @var OutputInterface
     */
    protected $output;

    /**
     * @var Logger
     */
    protected $logger;

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
            ->setDescription('Run the specified build, or the a pending build.')
            ->addArgument('build-id', InputArgument::OPTIONAL, "The identifier of the build to run.");
    }

    /**
     * Pulls all pending builds from the database and runs them.
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;

        // For verbose mode we want to output all informational and above
        // messages to the symphony output interface.
        if ($input->hasOption('verbose') && $input->getOption('verbose')) {
            $this->logger->pushHandler(
                new OutputLogHandler($this->output, Logger::INFO)
            );
        }

        $buildId = $input->getArgument('build-id');
        if ($buildId) {
            $build = BuildFactory::getBuildById($buildId);
        } else {
            $build = $this->getBuildToRun();
            if (!$build) {
                $this->logger->notice('No pending build to run.');
                return 1;
            }
        }

        if ($build->getStatus() !== Build::STATUS_NEW) {
            throw new \Exception("Can only run pending builds.");
        }

        try {
            // Logging relevant to this build should be stored
            // against the build itself.
            $buildDbLog = new BuildDBLogHandler($build, Logger::INFO);
            $this->logger->pushHandler($buildDbLog);

            $builder = new Builder($build, $this->logger);
            $builder->execute();
        } catch (Exception $ex) {
            $build->setStatus(Build::STATUS_FAILED);
            $build->setFinished(new DateTime());
            $build->setLog($build->getLog() . PHP_EOL . PHP_EOL . $ex->getMessage());
            Factory::get('Build')->save($build);
        }

        // We tried to ran something
        return 0;
    }

    /**
     * Look for a pending build for a project that doesn't have any running build.
     *
     * @return Build|null
     */
    public function getBuildToRun()
    {
        /* @var $store \PHPCI\Store\BuildStore */
        $store = Factory::get('Build');

        // Identify projects having running builds
        $running = $store->getByStatus(Build::STATUS_RUNNING);
        $hasRunningBuild = array();
        /* @var $build \PHPCI\Model\Build */
        foreach ($running['items'] as $build) {
            $hasRunningBuild[$build->getProjectId()] = true;
        }

        // Try to find a pending for a proejct with no running builds
        $pending = $store->getByStatus(Build::STATUS_NEW);
        foreach ($pending['items'] as $build) {
            if (!$hasRunningBuild[$build->getProjectId()]) {
                return BuildFactory::getBuild($build);
            }
            $this->logger->notice(
                sprintf(
                    "Skipped build %d because project %d already has a running build.",
                    $build->getId(),
                    $build->getProjectId()
                )
            );
        }
    }
}
