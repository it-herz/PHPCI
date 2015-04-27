<?php
/**
 * PHPCI - Continuous Integration for PHP
 *
 * @copyright    Copyright 2014, Block 8 Limited.
 * @license      https://github.com/Block8/PHPCI/blob/master/LICENSE.md
 * @link         https://www.phptesting.org/
 */

namespace PHPCI;

use Monolog\Handler\PsrHandler;
use Monolog\Logger;
use PHPCI\Helper\BuildInterpolator;
use PHPCI\Helper\Lang;
use PHPCI\Helper\MailerFactory;
use PHPCI\Logging\BuildDBLogHandler;
use PHPCI\Model\Build;
use b8\Config;
use b8\Store\Factory;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use PHPCI\Plugin\Util\Factory as PluginFactory;

/**
 * PHPCI Build Runner
 * @author   Dan Cryer <dan@block8.co.uk>
 */
class Builder
{
    /**
     * @var string
     */
    public $buildPath;

    /**
     * @var string[]
     */
    public $ignore = array();

    /**
     * @var string
     */
    protected $directory;

    /**
     * @var bool
     */
    protected $verbose = true;

    /**
     * @var \PHPCI\Model\Build
     */
    protected $build;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var BuildDBLogHandler
     */
    protected $dbLogger;

    /**
     * @var array
     */
    protected $config;

    /**
     * @var string
     */
    protected $lastOutput;

    /**
     * @var BuildInterpolator
     */
    protected $interpolator;

    /**
     * @var \PHPCI\Store\BuildStore
     */
    protected $store;

    /**
     * @var bool
     */
    public $quiet = false;

    /**
     * @var \PHPCI\Plugin\Util\Executor
     */
    protected $pluginExecutor;

    /**
     * @var Helper\CommandExecutor
     */
    protected $commandExecutor;

    /**
     * Set up the builder.
     * @param \PHPCI\Model\Build $build
     * @param LoggerInterface $logger
     */
    public function __construct(Build $build, LoggerInterface $logger = null)
    {
        $this->build = $build;
        $this->store = Factory::getStore('Build');

        $this->logger = $this->buildLogger($build, $logger);

        $pluginFactory = $this->buildPluginFactory($build);
        $pluginFactory->addConfigFromFile(PHPCI_DIR . "/pluginconfig.php");
        $this->pluginExecutor = new Plugin\Util\Executor($pluginFactory, $this->logger);

        $executorClass = 'PHPCI\Helper\UnixCommandExecutor';
        if (IS_WIN) {
            $executorClass = 'PHPCI\Helper\WindowsCommandExecutor';
        }

        $this->commandExecutor = new $executorClass($this->logger, PHPCI_DIR);

        $this->interpolator = new BuildInterpolator();
    }

    /**
     *
     * @param Build $build
     * @param LoggerInterface $outerLogger
     * @return LoggerInterface
     */
    private function buildLogger(Build $build, LoggerInterface $outerLogger = null)
    {
        $buildId = $build->getId();
        $logger = new Logger("Build".$buildId);

        $this->dbLogger = new BuildDBLogHandler($build);
        $logger->pushHandler($this->dbLogger);

        if ($outerLogger) {
            $logger->pushHandler(new PsrHandler($outerLogger));
        }

        $logger->pushProcessor(
            function (array $record) use ($buildId) {
                $record['context']['buildID'] = $buildId;
                return $record;
            }
        );

        return $logger;
    }

    /**
     * Set the config array, as read from phpci.yml
     * @param array|null $config
     * @throws \Exception
     */
    public function setConfigArray($config)
    {
        if (is_null($config) || !is_array($config)) {
            throw new \Exception(Lang::get('missing_phpci_yml'));
        }

        $this->config = $config;
    }

    /**
     * Access a variable from the phpci.yml file.
     * @param string
     * @return mixed
     */
    public function getConfig($key)
    {
        $rtn = null;

        if (isset($this->config[$key])) {
            $rtn = $this->config[$key];
        }

        return $rtn;
    }

    /**
     * Access a variable from the config.yml
     * @param $key
     * @return mixed
     */
    public function getSystemConfig($key)
    {
        return Config::getInstance()->get($key);
    }

    /**
     * @return string   The title of the project being built.
     */
    public function getBuildProjectTitle()
    {
        return $this->build->getProject()->getTitle();
    }

    /**
     * Run the active build.
     */
    public function execute()
    {
        // Update the build in the database, ping any external services.
        $this->build->setStatus(Build::STATUS_RUNNING);
        $this->build->setStarted(new \DateTime());
        $this->store->save($this->build);
        $this->build->sendStatusPostback();
        $success = true;

        try {
            // Set up the build:
            $this->setupBuild();

            // Run the core plugin stages:
            foreach (array('setup', 'test') as $stage) {
                $success &= $this->pluginExecutor->executePlugins($this->config, $stage);
            }

            // Set the status so this can be used by complete, success and failure
            // stages.
            if ($success) {
                $this->build->setStatus(Build::STATUS_SUCCESS);
            } else {
                $this->build->setStatus(Build::STATUS_FAILED);
            }

            // Complete stage plugins are always run
            $this->pluginExecutor->executePlugins($this->config, 'complete');

            if ($success) {
                $this->pluginExecutor->executePlugins($this->config, 'success');
                $this->logSuccess(Lang::get('build_success'));
            } else {
                $this->pluginExecutor->executePlugins($this->config, 'failure');
                $this->logFailure(Lang::get('build_failed'));
            }
        } catch (\Exception $ex) {
            $this->build->setStatus(Build::STATUS_FAILED);
            $this->logFailure(Lang::get('exception'), $ex);
        }


        // Update the build in the database, ping any external services, etc.
        $this->build->sendStatusPostback();
        $this->build->setFinished(new \DateTime());

        // Clean up:
        $this->logger->info(Lang::get('removing_build'));
        $this->build->removeBuildDirectory();

        $this->store->save($this->build);
    }

    /**
     * Used by this class, and plugins, to execute shell commands.
     */
    public function executeCommand()
    {
        return $this->commandExecutor->executeCommand(func_get_args());
    }

    /**
     * Returns the output from the last command run.
     */
    public function getLastOutput()
    {
        return $this->commandExecutor->getLastOutput();
    }

    /**
     * Specify whether exec output should be logged.
     * @param bool $enableLog
     */
    public function logExecOutput($enableLog = true)
    {
        $this->commandExecutor->logExecOutput = $enableLog;
    }

    /**
     * Find a binary required by a plugin.
     * @param string $binary
     * @param bool $quiet
     *
     * @return null|string
     */
    public function findBinary($binary, $quiet = false)
    {
        return $this->commandExecutor->findBinary($binary, $quiet = false);
    }

    /**
     * Replace every occurrence of the interpolation vars in the given string
     * Example: "This is build %PHPCI_BUILD%" => "This is build 182"
     * @param string $input
     * @return string
     */
    public function interpolate($input)
    {
        return $this->interpolator->interpolate($input);
    }

    /**
     * Set up a working copy of the project for building.
     */
    protected function setupBuild()
    {
        $this->buildPath = $this->build->getBuildPath() . '/';
        $this->build->currentBuildPath = $this->buildPath;

        $this->interpolator->setupInterpolationVars(
            $this->build,
            $this->buildPath,
            PHPCI_URL
        );

        $this->commandExecutor->setBuildPath($this->buildPath);

        // Create a working copy of the project:
        if (!$this->build->createWorkingCopy($this, $this->buildPath)) {
            throw new \Exception(Lang::get('could_not_create_working'));
        }

        // Does the project's phpci.yml request verbose mode?
        if (empty($this->config['build_settings']['verbose'])) {
            $this->dbLogger->setLevel(LogLevel::NOTICE);
        } else {
            $this->dbLogger->setLevel(LogLevel::INFO);
        }

        // Does the project have any paths it wants plugins to ignore?
        if (isset($this->config['build_settings']['ignore'])) {
            $this->ignore = $this->config['build_settings']['ignore'];
        }

        $this->logger->notice(Lang::get('working_copy_created', $this->buildPath));
        return true;
    }

    /**
     * Write to the build log.
     * @param $message
     * @param string $level
     * @param array $context
     */
    public function log($message, $level = LogLevel::INFO, $context = array())
    {
        $this->logger->log($level, $message, $context);
    }

    /**
     * Add a success-coloured message to the log.
     *
     * @param string
     */
    public function logSuccess($message)
    {
        $this->logger->notice($message);
    }

    /**
     * Add a failure-coloured message to the log.
     * @param string $message
     * @param Exception $exception The exception that caused the error.
     */
    public function logFailure($message, \Exception $exception = null)
    {
        $context = $exception ? array('exception' => $exception) : array();
        $this->logger->error($message, $context);
    }

    /**
     * Returns a configured instance of the plugin factory.
     *
     * @param Build $build
     * @return PluginFactory
     */
    private function buildPluginFactory(Build $build)
    {
        $pluginFactory = new PluginFactory();

        $self = $this;
        $pluginFactory->registerResource(
            function () use ($self) {
                return $self;
            },
            null,
            'PHPCI\Builder'
        );

        $pluginFactory->registerResource(
            function () use ($build) {
                return $build;
            },
            null,
            'PHPCI\Model\Build'
        );

        $logger = $this->logger;
        $pluginFactory->registerResource(
            function () use ($logger) {
                return $logger;
            },
            null,
            'Psr\Log\LoggerInterface'
        );

        $pluginFactory->registerResource(
            function () use ($self) {
                $factory = new MailerFactory($self->getSystemConfig('phpci'));
                return $factory->getSwiftMailerFromConfig();
            },
            null,
            'Swift_Mailer'
        );

        return $pluginFactory;
    }
}
