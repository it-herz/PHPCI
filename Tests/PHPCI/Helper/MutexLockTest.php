<?php
/**
 * PHPCI - Continuous Integration for PHP
 *
 * @copyright Copyright 2015, Block 8 Limited.
 * @license   https://github.com/Block8/PHPCI/blob/master/LICENSE.md
 * @link      http://www.phptesting.org/
 */

namespace Tests\PHPCI\Helper;

use PHPCI\Helper\MutexLock;
use PHPUnit_Framework_TestCase;

class MutexLockTest extends PHPUnit_Framework_TestCase
{
    protected function tearDown()
    {
        parent::tearDown();
        @unlink(__DIR__.'/.testlock');
    }

    public function testAcquire()
    {
        $lock = new MutexLock(__DIR__.'/.testlock');

        $this->assertFalse($lock->isOwner());

        $this->assertTrue($lock->acquire());

        $this->assertTrue($lock->isOwner());

        $lock->release();
    }

    public function testAcquireFails()
    {
        $lock1 = new MutexLock(__DIR__.'/.testlock');
        $lock2 = new MutexLock(__DIR__.'/.testlock');

        $this->assertTrue($lock1->acquire());
        $this->assertFalse($lock2->acquire());

        $this->assertTrue($lock1->isOwner());
        $this->assertFalse($lock2->isOwner());

        $lock1->release();
    }

    public function testRelease()
    {
        $lock1 = new MutexLock(__DIR__.'/.testlock');
        $lock2 = new MutexLock(__DIR__.'/.testlock');

        $this->assertTrue($lock1->acquire());
        $this->assertFalse($lock2->acquire());

        $lock1->release();
        $this->assertFalse($lock1->isOwner());

        $this->assertTrue($lock2->acquire());

        $lock2->release();
    }

    public function testGetOwnerPID()
    {
        $lock1 = new MutexLock(__DIR__.'/.testlock');
        $lock2 = new MutexLock(__DIR__.'/.testlock');

        $this->assertFalse($lock1->getOwnerPID());
        $this->assertFalse($lock2->getOwnerPID());

        $this->assertTrue($lock1->acquire());
        $this->assertFalse($lock2->acquire());

        $this->assertEquals($lock1->getOwnerPID(), $lock2->getOwnerPID());

        $lock1->release();
    }

    public function testDestruct()
    {
        $outerLock = new MutexLock(__DIR__.'/.testlock');

        $closure = function () {
            $innerLock = new MutexLock(__DIR__.'/.testlock');
            $this->assertTrue($innerLock->acquire());
            // $innerLock is destructed when going out of scope.
        };

        $closure();

        $this->assertTrue($outerLock->acquire());
        $outerLock->release();
    }
}
