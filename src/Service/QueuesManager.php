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

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Service;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\OptimisticLockException;
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
     * Checks if a Job is scheduled given a Job instance.
     *
     * It uses the given Job's parameters to find one already scheduled.
     *
     * It uses command, arguments and queue and searches only for Jobs not already executed.
     *
     * Returns (bool) false if it doesn't exist or the scheduled Job if it exists.
     *
     * @param Job $job
     *
     * @return false|Job
     */
    public function jobExists(Job $job)
    {
        return $this->exists($job->getCommand(), $job->getArguments(), $job->getQueue());
    }

    /**
     * Finds a Job given a Job instance.
     *
     * It uses the given Job's parameters to find one already scheduled.
     *
     * It uses command, arguments and queue and searches only for Jobs not already executed.
     *
     * Returns null if it doesn't exist or the scheduled Job if it exists.
     *
     * @param Job $job
     *
     * @return Job|null
     */
    public function findJob(Job $job): ?Job
    {
        return $this->find($job->getCommand(), $job->getArguments(), $job->getQueue());
    }

    /**
     * Given Job parameters, checks if it exists or not.
     *
     * Returns (bool) false if it doesn't exist or the scheduled Job if it exists.
     *
     * @param string $command
     * @param array  $arguments
     * @param string $queue
     *
     * @return bool|Job
     */
    public function exists(string $command, array $arguments = [], string $queue = 'default')
    {
        $exists = $this->find($command, $arguments, $queue);

        if (null === $exists) {
            return false;
        }

        return $exists;
    }

    /**
     * Finds a Job given its parameters.
     *
     * Returns null if it doesn't exist or the scheduled Job if it exists.
     *
     * @param string $command
     * @param array  $arguments
     * @param string $queue
     *
     * @return Job|null
     */
    public function find(string $command, array $arguments = [], string $queue = 'default'): ?Job
    {
        // Check and prepare arguments of the command
        $arguments = Job::prepareArguments($arguments);

        return $this->entityManager->getRepository('SHQCommandsQueuesBundle:Job')->exists($command, $arguments, $queue);
    }

    /**
     * persists a job and flushes it to the database.
     *
     * @param Job $job
     *
     * @throws OptimisticLockException
     */
    public function schedule(Job $job): void
    {
        $this->entityManager->persist($job);
        $this->entityManager->flush($job);
    }
}
