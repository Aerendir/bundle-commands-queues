<?php

namespace SerendipityHQ\Bundle\QueuesBundle\Model;

/**
 * A common interface for a Job to be pushed into a queue.
 */
interface JobInterface
{
    public function doWork();
}
