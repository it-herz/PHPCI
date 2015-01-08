<?php
/**
 * PHPCI - Continuous Integration for PHP
 *
 * @copyright    Copyright 2014, Block 8 Limited.
 * @license      https://github.com/Block8/PHPCI/blob/master/LICENSE.md
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
abstract class BaseCommandExecutor implements CommandExecutor
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

    protected $lastOutput;
    protected $lastError;

    public $logExecOutput = true;

    /**
     * The path which findBinary will look in.
     * @var string
     */
    protected $rootDir;

    /**
     * Current build path
     * @var string
     */
    protected $buildPath;

    /**
     * An array of paths to binaries.
     * @var array
     */
    protected $binaryPaths = [];

    /**
     * @param BuildLogger $logger
     * @param string      $rootDir
     * @param bool        $quiet
     * @param bool        $verbose
     */
    public function __construct(BuildLogger $logger, $rootDir, &$quiet = false, &$verbose = false)
    {
        $this->logger = $logger;
        $this->quiet = $quiet;
        $this->verbose = $verbose;

        $this->lastOutput = array();

        $this->rootDir = $rootDir;
    }

    /**
     * @param array $binaryPaths
     */
    public function setBinaryPaths(array $binaryPaths)
    {
        $this->binaryPaths = $binaryPaths;
    }

    /**
     * Executes shell commands.
     * @param array $args
     * @return bool Indicates success
     */
    public function executeCommand($args = array())
    {
        $this->lastOutput = array();

        $command = call_user_func_array('sprintf', $args);

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

        $process = proc_open($command, $descriptorSpec, $pipes, dirname($this->buildPath), null);

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
     * @param string $binary
     * @param null $buildPath
     * @return null|string
     */
    public function findBinary($binary, $buildPath = null)
    {
        $paths = [];

        if ($buildPath !== null) {
            $buildRoot = realpath($buildPath);

            foreach ($this->binaryPaths as $path) {
                $fullPath = $buildRoot . '/' . trim($path, '/') . '/';
                if (is_dir($fullPath)) {
                    $paths[$path] = $fullPath;
                }
            }

            $composerBin = $this->getComposerBinDir($buildRoot);
            if (is_dir($composerBin)) {
                $paths[$composerBin] = $composerBin . '/';
            }
        }

        $paths['root'] = $this->rootDir;
        $paths['vendor/bin'] = $this->rootDir . 'vendor/bin/';

        if (is_string($binary)) {
            $binary = array($binary);
        }

        foreach ($binary as $bin) {
            $this->logger->log(Lang::get('looking_for_binary', $bin), LogLevel::DEBUG);

            foreach ($paths as $relative => $fullPath) {
                if (is_file($fullPath . $bin)) {
                    $this->logger->log(Lang::get('found_in_path', $relative, $fullPath), LogLevel::DEBUG);
                    return $fullPath . $bin;
                }
            }

            $findCmdResult = $this->findGlobalBinary($bin);
            if (is_file($findCmdResult)) {
                $this->logger->log(Lang::get('found_in_path', '', $bin), LogLevel::DEBUG);
                return $findCmdResult;
            }
        }
        return null;
    }

    /**
     * Find a binary which is installed globally on the system
     * @param string $binary
     * @return null|string
     */
    abstract protected function findGlobalBinary($binary);

    /**
     * Try to load the composer.json file in the building project
     * If the bin-dir is configured, return the full path to it
     * @param string $path Current build path
     * @return string|null
     */
    public function getComposerBinDir($path)
    {
        if (is_dir($path)) {
            $composer = $path.'/composer.json';
            if (is_file($composer)) {
                $json = json_decode(file_get_contents($composer));

                if (isset($json->config->{"bin-dir"})) {
                    return $path.'/'.$json->config->{"bin-dir"};
                } elseif (is_dir($path . '/vendor/bin')) {
                    return $path  . '/vendor/bin';
                }
            }
        }
        return null;
    }
}
