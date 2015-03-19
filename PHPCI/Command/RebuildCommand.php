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
use PHPCI\Command\RunCommand;
use PHPCI\Service\BuildService;
use PHPCI\Store\BuildStore;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
* Re-runs the last run build.
* @author       Dan Cryer <dan@block8.co.uk>
* @package      PHPCI
* @subpackage   Console
*/
class RebuildCommand extends RunCommand
{
    protected function configure()
    {
        $this
            ->setName('phpci:rebuild')
            ->setDescription('Re-runs the last run build.');
    }

    /**
    * Duplicates the last build and runs it.
    */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var BuildStore $store */
        $store = Factory::getStore('Build');
        $service = new BuildService($store);

        $lastBuild = array_shift($store->getLatestBuilds(null, 1));
        $build = $service->createDuplicateBuild($lastBuild);

        $this->runBuild($build);
    }
}
