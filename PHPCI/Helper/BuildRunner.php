<?php
/**
 * PHPCI - Continuous Integration for PHP
 *
 * @copyright    Copyright 2014, Block 8 Limited.
 * @license      https://github.com/Block8/PHPCI/blob/master/LICENSE.md
 * @link         https://www.phptesting.org/
 */

namespace PHPCI\Helper;

use DateTime;
use Exception;
use Monolog\Logger;
use PHPCI\Builder;
use PHPCI\BuildFactory;
use PHPCI\Logging\BuildDBLogHandler;
use PHPCI\Logging\LoggedBuildContextTidier;
use PHPCI\Model\Build;
use PHPCI\Store\BuildStore;

/**
 *
 * @author       Dan Cryer <dan@block8.co.uk>
 * @package      PHPCI
 * @subpackage   Web
 */
class BuildRunner
{
    /**
     * @var BuildStore
     */
    private $buildStore;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var int
     */
    private $maxConcurrentBuilds;

    /**
     * @var int
     */
    private $buildTimeout;

    /**
     *
     * @param BuildStore $buildStore
     * @param Logger $maxConcurrentBuilds
     * @param type $buildTimeout
     */
    public function __construct(BuildStore $buildStore, Logger $logger, $maxConcurrentBuilds = 20, $buildTimeout = 3000)
    {
        $this->buildStore = $buildStore;
        $this->logger = $logger;
        $this->maxConcurrentBuilds = $maxConcurrentBuilds;
        $this->buildTimeout = $buildTimeout;
    }

    /** Find and execute up to $this->maxConcurrentBuilds pending builds.
     *
     * @return int Number of executed builds.
     */
    public function run()
    {
        $this->logger->pushProcessor(new LoggedBuildContextTidier());
        $this->logger->addInfo(Lang::get('finding_builds'));

        $runningCount = 0;
        $projects = $this->validateRunningBuilds($runningCount);
        if ($runningCount >= $this->maxConcurrentBuilds) {
            $this->logger->addInfo(Lang::get('finished_processing_builds'));
            return 0;
        }

        $builds = $this->getPendingBuilds($projects, $this->maxConcurrentBuilds - $runningCount);
        $this->logger->addInfo(Lang::get('found_n_builds', count($builds)));

        foreach ($builds as $build) {
            $this->executeBuild($build);
        }

        $this->logger->addInfo(Lang::get('finished_processing_builds'));
        return count($builds);
    }

    /** Execute the given build.
     *
     * @param Build $build
     */
    public function executeBuild(Build $build)
    {
        // Logging relevant to this build should be stored
        // against the build itself.
        $buildLogger = $this->createBuildLogger($build);

        try {
            $builder = new Builder($build, $buildLogger);
            $builder->execute();
        } catch (Exception $ex) {
            $build->setStatus(Build::STATUS_FAILED);
            $build->setFinished(new DateTime());
            $build->setLog($build->getLog() . PHP_EOL . PHP_EOL . $ex->getMessage());
            $this->buildStore->save($build);
        }
    }

    /** Create a build-specific logger
     *
     * @param Build $build
     * @return Logger
     */
    public function createBuildLogger(Build $build)
    {
        $buildLogger = clone $this->logger;
        $buildDbLog = new BuildDBLogHandler($build, Logger::INFO);
        $buildLogger->logger->pushHandler($buildDbLog);
        return $buildLogger;
    }

    /** Validate and count running builds.
     *
     * @return array The sets of projects that have running builds.
     */
    public function validateRunningBuilds(&$runningCount = 0)
    {
        $running = $this->buildStore->getByStatus(Build::STATUS_RUNNING);
        $projects = array();

        foreach ($running['items'] as $build) {
            $build = BuildFactory::getBuild($build);

            $now = time();
            $start = $build->getStarted()->getTimestamp();

            if (($now - $start) > $this->buildTimeout) {
                $this->logger->addInfo(Lang::get('marked_as_failed', $build->getId()));
                $build->setStatus(Build::STATUS_FAILED);
                $build->setFinished($now);
                $this->buildStore->save($build);
                $this->removeBuildDirectory($build);
                continue;
            }

            $projects[$build->getProjectId()] = true;
            $runningCount++;
        }

        return $projects;
    }

    /** Find up to $maxNumber pending builds for projects with not running builds.
     *
     * @param array $excludeProjects
     * @param int $maxNumber
     * @return array A list of Build.
     */
    public function getPendingBuilds(array $excludeProjects, $maxNumber)
    {
        $pending = $store->getByStatus(Build::STATUS_NEW, $maxNumber + count($excludeProjects));
        $builds = array();

        foreach ($pending['items'] as $build) {

            $build = BuildFactory::getBuild($build);
            if (isset($excludeProjects[$build->getProjectId()])) {
                $this->logger->addInfo(Lang::get('skipping_build', $build->getId()));
                continue;
            }

            $builds[] = $build;
            if (count($builds) >= $maxNumber) {
                break;
            }
        }

        return $builds;
    }

    /** Remove the working copy of a build.
     * 
     * @param Build $build
     */
    protected function removeBuildDirectory(Build $build)
    {
        $buildPath = PHPCI_DIR . 'PHPCI/build/' . $build->getId() . '/';

        if (is_dir($buildPath)) {
            $cmd = 'rm -Rf "%s"';

            if (IS_WIN) {
                $cmd = 'rmdir /S /Q "%s"';
            }

            shell_exec($cmd);
        }
    }
}
