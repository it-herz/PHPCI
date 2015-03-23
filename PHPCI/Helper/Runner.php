<?php
/**
 * PHPCI - Continuous Integration for PHP
 *
 * @copyright    Copyright 2015, Block 8 Limited.
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
use PHPCI\Model\Build;
use PHPCI\Store\BuildStore;
use Psr\Log\LoggerInterface;

/**
 * Helper class for running builds.
 *
 * @package PHPCI\Helper
 */
class Runner
{
    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var BuildStore
     */
    protected $buildStore;

    /**
     * @var integer
     */
    protected $buildTimeout;

    /**
     * @param LoggerInterface $logger
     * @param string $name
     */
    public function __construct(Logger $logger, $buildStore, $buildTimeout = 1800)
    {
        $this->logger = $logger;
        $this->buildStore = $buildStore;
        $this->buildTimeout = $buildTimeout;
    }

    /**
     * Find a pending build for a project which has no running builds.
     *
     * @return Build|null
     */
    public function next()
    {
        $running = $this->validateRunningBuilds();

        $this->logger->addInfo(Lang::get('finding_builds'));
        $result = $this->buildStore->getByStatus(Build::STATUS_NEW);
        $this->logger->addInfo(Lang::get('found_n_builds', count($result['items'])));

        foreach ($result['items'] as $build) {
            if (isset($running[$build->getProjectId()])) {
                $this->logger->info(Lang::get('skipping_build', $build->getId()));
            } else {
                return $build;
            }
        }
    }

    /**
     * Runs one build and cleans up after it finishes.
     *
     * @param Build $build
     */
    public function run(Build $build)
    {
        $this->logger->addInfo(sprintf("Running build %s", $build->getId()));

        $buildDbLog = $this->createLogger($build);
        $this->logger->pushHandler($buildDbLog);

        try {
            $this->createBuilder($build)->execute();

        } catch (Exception $ex) {
            $build->setStatus(Build::STATUS_FAILED);
            $build->setFinished(new DateTime());
            $build->setLog($build->getLog() . PHP_EOL . PHP_EOL . $ex->getMessage());
            $this->buildStore->save($build);
        }

        $this->removeBuildDirectory($build);
        $this->logger->popHandler($buildDbLog);
        $this->logger->info(sprintf("Build %d ended", $build->getId()));
    }

    /** Create a logger for the given build.
     *
     * @param Build $build
     * @return BuildDBLogHandler
     */
    protected function createLogger(Build $build)
    {
        return new BuildDBLogHandler($build, Logger::INFO);
    }

    /** Create the builder for the given buid.
     *
     * @param Build $build
     * @return Builder
     */
    protected function createBuilder(Build $build)
    {
        return new Builder($build, $this->logger);
    }

    /**
     * Checks all running builds, and kills those that seem dead.
     *
     * @return array An array with project identifiers as keys, for projets
     *               that have running builds.
     */
    public function validateRunningBuilds()
    {
        $running = $this->buildStore->getByStatus(1);
        $rtn = array();

        foreach ($running['items'] as $build) {
            /** @var Build $build */
            $build = BuildFactory::getBuild($build);

            $now = time();
            $start = $build->getStarted()->getTimestamp();

            if (($now - $start) > $this->buildTimeout) {
                $this->logger->info(Lang::get('marked_as_failed', $build->getId()));
                $build->setStatus(Build::STATUS_FAILED);
                $build->setFinished(new DateTime());
                $this->buildStore->save($build);
                $this->removeBuildDirectory($build);
                continue;
            }

            $rtn[$build->getProjectId()] = true;
        }

        return $rtn;
    }

    /**
     * Remove the build directory of a finished build.
     *
     * @param Build $build
     *
     * @todo Move this to the Build class.
     */
    public function removeBuildDirectory(Build $build)
    {
        $buildPath = PHPCI_DIR . 'PHPCI/build/' . $build->getId() . '/';

        if (is_dir($buildPath)) {
            $cmd = 'rm -Rf "%s"';

            if (IS_WIN) {
                $cmd = 'rmdir /S /Q "%s"';
            }

            exec($cmd);
        }
    }

}
