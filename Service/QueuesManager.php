<?php

namespace SerendipityHQ\Bundle\QueuesBundle\Service;

use Doctrine\ORM\EntityManager;
use SerendipityHQ\Bundle\QueuesBundle\Model\ScheduledJob;

/**
 * Manages the queues.
 */
class QueuesManager
{
    /** @var  EntityManager */
    private $entityManager;

    /**
     * @param EntityManager $entityManager
     */
    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * Schedules a job.
     *
     * @param ScheduledJob $job
     */
    public function schedule(ScheduledJob $job)
    {
        $this->entityManager->persist($job);
        $this->entityManager->flush();
    }
}
