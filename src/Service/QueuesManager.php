<?php

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

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Service;

use Doctrine\ORM\EntityManager;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job;

/**
 * Manages the commands_queues.
 */
class QueuesManager
{
    /** @var EntityManager */
    private $entityManager;

    /**
     * @param EntityManager $entityManager
     */
    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * Checks if the given Job exists or not.
     *
     * @param string $command
     * @param array  $arguments
     * @param string $queue
     *
     * @return bool|Job
     */
    public function exists(string $command, $arguments = [], string $queue = 'default')
    {
        // Check and prepare arguments of the command
        $arguments = Job::prepareArguments($arguments);

        $exists = $this->entityManager->getRepository('SHQCommandsQueuesBundle:Job')->exists($command, $arguments, $queue);

        if (null === $exists) {
            return false;
        }

        return $exists;
    }

    /**
     * Schedules a job.
     *
     * @param Job $job
     */
    public function schedule(Job $job)
    {
        $this->entityManager->persist($job);
        $this->entityManager->flush();
    }
}
