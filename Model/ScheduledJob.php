<?php

namespace SerendipityHQ\Bundle\QueuesBundle\Model;

/**
 * Basic properties and methods o a Job.
 */
class ScheduledJob
{
    /** @var  int $id The ID of the Job */
    private $id;

    /** @var  JobInterface */
    private $scheduledJob;

    /**
     * @param JobInterface $job
     */
    public function __construct(JobInterface $job)
    {
        $this->scheduledJob = $job;
    }

    /**
     * @return int
     */
    public function getId() : int
    {
        return $this->id;
    }
}
