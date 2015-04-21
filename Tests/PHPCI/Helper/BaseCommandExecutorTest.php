<?php

namespace PHPCI\Plugin\Tests\Helper;

use PHPCI\Helper\BaseCommandExecutor;
use PHPCI\Helper\Environment;
use Prophecy\PhpUnit\ProphecyTestCase;

class BaseCommandExecutorTest extends ProphecyTestCase
{
    /**
     * @var CommandExecutor
     */
    protected $testedExecutor;

    protected function setUp()
    {
        parent::setUp();

        $mockBuildLogger = $this->prophesize('PHPCI\Logging\BuildLogger');
        $this->testedExecutor = new BaseCommandExecutor($mockBuildLogger->reveal());
    }

    public function testGetLastOutput_ReturnsOutputOfCommand()
    {
        $this->testedExecutor->executeCommand(array('echo %s', 'Hello World'));
        $output = $this->testedExecutor->getLastOutput();
        $this->assertEquals('Hello World', $output);
    }

    public function testGetLastOutput_ForgetsPreviousCommandOutput()
    {
        $this->testedExecutor->executeCommand(array('echo %s', 'Hello World'));
        $this->testedExecutor->executeCommand(array('echo %s', 'Hello Tester'));
        $output = $this->testedExecutor->getLastOutput();
        $this->assertEquals('Hello Tester', $output);
    }

    public function testExecuteCommand_ReturnsTrueForValidCommands()
    {
        $returnValue = $this->testedExecutor->executeCommand(array('echo %s', 'Hello World'));
        $this->assertTrue($returnValue);
    }

    public function testExecuteCommand_ReturnsFalseForInvalidCommands()
    {
        $returnValue = $this->testedExecutor->executeCommand(array('eerfdcvcho %s', 'Hello World'));
        $this->assertFalse($returnValue);
    }

    public function testExecuteCommand_Environment()
    {
        $mockBuildLogger = $this->prophesize('PHPCI\Logging\BuildLogger');

        $environment = new Environment();

        $this->testedExecutor = new BaseCommandExecutor($mockBuildLogger->reveal(), $environment);

        $environment['FOO'] = 'BAR';

        $this->assertTrue($this->testedExecutor->executeCommand(array(IS_WIN ? 'echo %FOO%' : 'echo $FOO')));
        $this->assertEquals('BAR', $this->testedExecutor->getLastOutput());
    }

    public function testExecuteCommand_Script()
    {
        $mockBuildLogger = $this->prophesize('PHPCI\Logging\BuildLogger');

        $environment = new Environment();

        $this->testedExecutor = new BaseCommandExecutor($mockBuildLogger->reveal(), $environment);

        $environment['FOO'] = 'BAR';
        $fixtureDir = __DIR__ . DIRECTORY_SEPARATOR . 'Fixtures';
        $environment->addPath($fixtureDir);

        $this->assertTrue(
            $this->testedExecutor->executeCommand(array(IS_WIN ? 'phpci_test_batch' : 'phpci_test_shell'))
        );
        $this->assertEquals('BAR', $this->testedExecutor->getLastOutput());
    }

    public function testFindBinary_ReturnsPathInEnvironmentPath()
    {
        $mockBuildLogger = $this->prophesize('PHPCI\Logging\BuildLogger');

        $environment = new Environment();

        $this->testedExecutor = new BaseCommandExecutor($mockBuildLogger->reveal(), $environment);

        $fixtureDir = __DIR__ . DIRECTORY_SEPARATOR . 'Fixtures';
        $environment->addPath($fixtureDir);

        $this->assertEquals(
            $fixtureDir . DIRECTORY_SEPARATOR . (IS_WIN ? 'phpci_test_batch.bat' : 'phpci_test_shell'),
            $this->testedExecutor->findBinary((IS_WIN ? 'phpci_test_batch' : 'phpci_test_shell'))
        );
    }
}
