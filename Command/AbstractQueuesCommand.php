<?php

/*
 * This file is part of the Trust Back Me Www.
 *
 * Copyright Adamo Aerendir Crespi 2012-2016.
 *
 * This code is to consider private and non disclosable to anyone for whatever reason.
 * Every right on this code is reserved.
 *
 * @author    Adamo Aerendir Crespi <hello@aerendir.me>
 * @copyright Copyright (C) 2012 - 2016 Aerendir. All rights reserved.
 * @license   SECRETED. No distribution, no copy, no derivative, no divulgation or any other activity or action that
 *            could disclose this text.
 */

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Command;

use Doctrine\ORM\EntityManager;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Repository\JobRepository;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Util\JobsMarker;
use SerendipityHQ\Bundle\ConsoleStyles\Console\Formatter\SerendipityHQOutputFormatter;
use SerendipityHQ\Bundle\ConsoleStyles\Console\Style\SerendipityHQStyle;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * An abstract command to manage common dependencies of all other commands.
 */
abstract class AbstractQueuesCommand extends ContainerAwareCommand
{
    /** @var  EntityManager $entityManager */
    private $entityManager;

    /** @var  SerendipityHQStyle */
    private $ioWriter;

    /** @var  JobsMarker $jobsMarker */
    private $jobsMarker;

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return bool
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Create the Input/Output writer
        $this->ioWriter = new SerendipityHQStyle($input, $output);
        $this->ioWriter->setFormatter(new SerendipityHQOutputFormatter(true));

        $this->entityManager = $this->getContainer()->get('commands_queues.do_not_use.entity_manager');

        return 0;
    }

    /**
     * @return EntityManager
     */
    protected final function getEntityManager() : EntityManager
    {
        return $this->entityManager;
    }

    /**
     * @return JobsMarker
     */
    protected final function getJobsMarker() : JobsMarker
    {
        if (null === $this->jobsMarker) {
            $this->jobsMarker = $this->getContainer()->get('commands_queues.do_not_use.jobs_marker');
        }

        return $this->jobsMarker;
    }

    /**
     * @return SerendipityHQStyle
     */
    protected final function getIoWriter() : SerendipityHQStyle
    {
        return $this->ioWriter;
    }
}