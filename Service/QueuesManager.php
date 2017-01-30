<?php

namespace SerendipityHQ\Bundle\QueuesBundle\Service;

use Doctrine\ORM\EntityManager;
use SerendipityHQ\Bundle\QueuesBundle\Model\JobInterface;
use SerendipityHQ\Bundle\QueuesBundle\Model\ScheduledJob;

/**
 * Manages the queues.
 */
class QueuesManager
{
    /** @var  EntityManager */
    private $entityManager;

    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * Schedules a job.
     *
     * @param JobInterface $job
     */
    public function schedule(JobInterface $job)
    {
        $scheduledJob = new ScheduledJob($job);
        $this->entityManager->persist($scheduledJob);
        $this->entityManager->flush();
        die(dump('job scheduled', $scheduledJob));
    }
}
