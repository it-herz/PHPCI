<?php
/**
 * PHPCI - Continuous Integration for PHP
 *
 * @copyright    Copyright 2014, Block 8 Limited.
 * @license      https://github.com/Block8/PHPCI/blob/master/LICENSE.md
 * @link         https://www.phptesting.org/
 */

namespace PHPCI\Helper;

/**
 * A file-lock-based mutex.
 *
 * @author       Adirelle <adirelle@gmail.com>
 * @package      PHPCI/Helper
 */
class MutexLock
{
    /**
     * The path to the lock file.
     *
     * @var string
     */
    private $path;

    /** Has this instance acquired the lock ?
     *
     * @var bool
     */
    private $isOwner = false;

    /**
     * The file resource.
     *
     * @var resource
     */
    private $handle = false;

    /**
     *
     * @param string $path
     * @param bool $unlinkOnRelease
     */
    public function __construct($path)
    {
        $this->path = $path;
    }

    /**
     * Release on desctruction.
     */
    public function __destruct()
    {
        $this->release();
    }

    /**
     * Try to acquire the lock.
     *
     * @return bool true if the lock has been acquired.
     */
    public function acquire()
    {
        if ($this->isOwner) {
            return true;
        }

        // Open the file
        $this->handle = fopen($this->path, 'c+');

        // Try to acquire an exclusive lock on the file.
        if (!flock($this->handle, LOCK_EX | LOCK_NB)) {
            fclose($this->handle);
            $this->handle = null;
            return false;
        }

        // Write the PID
        fwrite($this->handle, getmypid());
        ftruncate($this->handle, ftell($this->handle));
        fflush($this->handle);

        $this->isOwner = true;
        return true;
    }

    /**
     * Has this instance acquired the lock ?
     *
     * @return bool
     */
    public function isOwner()
    {
        return $this->isOwner;
    }

    /**
     * Get the PID of the owning process.
     *
     * @return int|bool The owner PID of false if no one has acquired the lock.
     */
    public function getOwnerPid()
    {
        if ($this->isOwner) {
            return getmypid();
        }

        $handle = @fopen($this->path, 'r');
        if ($handle === false) {
            return false;
        }

        // If we can acquire an exclusive lock, then no process is owning it.
        if (flock($handle, LOCK_EX | LOCK_NB)) {
            // Clean up and return false
            flock($handle, LOCK_UN);
            fclose($handle);
            @unlink($this->path);
            return false;
        }

        $pid = intval(fread($handle, 1024));
        fclose($handle);
        return $pid;
    }

    /**
     * Release the mutex.
     *
     * Destroy the lock file.
     */
    public function release()
    {
        if (!$this->isOwner) {
            return;
        }

        $this->isOwner = false;

        if ($this->handle) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }

        @unlink($this->path);
    }
}
