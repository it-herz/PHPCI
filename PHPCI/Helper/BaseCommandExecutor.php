<?php
/**
 * PHPCI - Continuous Integration for PHP
 *
 * @copyright    Copyright 2014, Block 8 Limited.
 * @license      https://github.com/Block8/PHPCI/blob/master/LICENSE.md
 *
 * @link         https://www.phptesting.org/
 */

namespace PHPCI\Helper;

use \PHPCI\Logging\BuildLogger;
use Psr\Log\LogLevel;
use PHPCI\Helper\Lang;

/**
 * Handles running system commands with variables.
 * @package PHPCI\Helper
 */
class BaseCommandExecutor implements CommandExecutor
{
    /**
     * @var \PHPCI\Logging\BuildLogger
     */
    protected $logger;

    /**
     * @var bool
     */
    protected $quiet;

    /**
     * @var bool
     */
    protected $verbose;

    /**
     * @var string[]
     */
    protected $lastOutput;

    /**
     * @var string
     */
    protected $lastError;

    /**
     * @var bool
     */
    public $logExecOutput = true;

    /**
     * Current build path
     *
     * @var string
     */
    protected $buildPath;

    /**
     * @var Environment
     */
    protected $environment;

    /**
     * @param BuildLogger $logger
     * @param Environment $environment
     * @param bool        $quiet
     * @param bool        $verbose
     */
    public function __construct(
        BuildLogger $logger,
        Environment $environment = null,
        &$quiet = false,
        &$verbose = false
    ) {
        $this->logger = $logger;
        $this->quiet = $quiet;
        $this->verbose = $verbose;
        $this->lastOutput = array();
        $this->environment = $environment ? $environment : new Environment();
    }

    /**
     * Executes shell commands.
     *
     * @param array $args
     *
     * @return bool Indicates success
     */
    public function executeCommand(array $args = array())
    {
        $this->lastOutput = array();

        $command = $this->formatArguments($args);

        if ($this->quiet) {
            $this->logger->log('Executing: ' . $command);
        }

        $status = 0;
        $descriptorSpec = array(
            0 => array("pipe", "r"),  // stdin
            1 => array("pipe", "w"),  // stdout
            2 => array("pipe", "w"),  // stderr
        );

        $pipes = array();

        $process = proc_open($command, $descriptorSpec, $pipes, $this->buildPath, $this->environment->getArrayCopy());

        if (is_resource($process)) {
            fclose($pipes[0]);

            $this->lastOutput = stream_get_contents($pipes[1]);
            $this->lastError = stream_get_contents($pipes[2]);

            fclose($pipes[1]);
            fclose($pipes[2]);

            $status = proc_close($process);
        }

        $this->lastOutput = array_filter(explode(PHP_EOL, $this->lastOutput));

        $shouldOutput = ($this->logExecOutput && ($this->verbose || $status != 0));

        if ($shouldOutput && !empty($this->lastOutput)) {
            $this->logger->log($this->lastOutput);
        }

        if (!empty($this->lastError)) {
            $this->logger->log("\033[0;31m" . $this->lastError . "\033[0m", LogLevel::ERROR);
        }

        $rtn = false;

        if ($status == 0) {
            $rtn = true;
        }

        return $rtn;
    }

    /** Format the arguments into a single command.
     *
     * @param array $arguments
     * @return string
     */
    protected function formatArguments(array $arguments)
    {
        $format = array_shift($arguments);
        if (!empty($arguments)) {
            return vsprintf($format, $arguments);
        }
        return $format;
    }

    /**
     * Returns the output from the last command run.
     */
    public function getLastOutput()
    {
        return implode(PHP_EOL, $this->lastOutput);
    }

    /**
     * Returns the stderr output from the last command run.
     */
    public function getLastError()
    {
        return $this->lastError;
    }

    /**
     * Find a binary required by a plugin.
     *
     * @param array|string $binary
     *
     * @return string|null
     */
    public function findBinary($binary)
    {
        if (is_string($binary)) {
            $binary = array($binary);
        }

        $this->logger->log(Lang::get('looking_for_binary', implode(', ', $binary)), LogLevel::DEBUG);

        $command = IS_WIN ? 'where' : 'which';
        $arguments = implode(' ', array_map('escapeshellarg', $binary));

        if (!$this->executeCommand(array('%s %s', $command, $arguments))) {
            $this->logger->log(sprintf('Binary not found: %s', implode(', ', $binary)), LogLevel::WARNING);
            return null;
        }

        $path = trim($this->lastOutput[0]);
        $this->logger->log(Lang::get('found_in_path', dirname($path), basename($path)), LogLevel::DEBUG);
        return $path;
    }

    /**
     * Set the buildPath property.
     *
     * @param string $path
     */
    public function setBuildPath($path)
    {
        $this->buildPath = $path;
    }
}
