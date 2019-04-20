<?php

declare(strict_types=1);

/*
 * This file is part of the SHQCommandsQueuesBundle.
 *
 * Copyright Adamo Aerendir Crespi 2017.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @author    Adamo Aerendir Crespi <hello@aerendir.me>
 * @copyright Copyright (C) 2017 Aerendir. All rights reserved.
 * @license   MIT License.
 */

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Util\JobsMarker;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Util\Profiler;
use SerendipityHQ\Bundle\ConsoleStyles\Console\Formatter\SerendipityHQOutputFormatter;
use SerendipityHQ\Bundle\ConsoleStyles\Console\Style\SerendipityHQStyle;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * An abstract command to manage common dependencies of all other commands.
 */
abstract class AbstractQueuesCommand extends Command
{
    /** @var EntityManagerInterface $entityManager */
    private $entityManager;

    /** @var SerendipityHQStyle */
    private $ioWriter;

    /** @var JobsMarker $jobsMarker */
    private $jobsMarker;

    /**
     * @param EntityManagerInterface $doNotUseEntityManager
     * @param JobsMarker             $doNotUseJobsMarker
     */
    public function __construct(EntityManagerInterface $doNotUseEntityManager, JobsMarker $doNotUseJobsMarker)
    {
        parent::__construct();
        $this->entityManager = $doNotUseEntityManager;
        $this->jobsMarker    = $doNotUseJobsMarker;
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Create the Input/Output writer
        $this->ioWriter = new SerendipityHQStyle($input, $output);
        $this->ioWriter->setFormatter(new SerendipityHQOutputFormatter(true));

        Profiler::setDependencies($this->getIoWriter(), $this->getEntityManager()->getUnitOfWork());

        return 0;
    }

    /**
     * @return EntityManagerInterface
     */
    final protected function getEntityManager(): EntityManagerInterface
    {
        return $this->entityManager;
    }

    /**
     * @return JobsMarker
     */
    final protected function getJobsMarker(): JobsMarker
    {
        return $this->jobsMarker;
    }

    /**
     * @return SerendipityHQStyle
     */
    final protected function getIoWriter(): SerendipityHQStyle
    {
        return $this->ioWriter;
    }
}
