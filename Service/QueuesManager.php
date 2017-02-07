<?php

namespace SerendipityHQ\Bundle\QueuesBundle\Service;

use Doctrine\ORM\EntityManager;
use SerendipityHQ\Bundle\QueuesBundle\Entity\Job;

/**
 * Manages the Queues.
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
