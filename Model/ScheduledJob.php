<?php

namespace SerendipityHQ\Bundle\QueuesBundle\Model;

/**
 * Basic properties and methods o a Job.
 */
class ScheduledJob
{
    /** @var  int $id The ID of the Job */
    private $id;

    /** @var string $command */
    private $command;

    /** @var array $arguments */
    private $arguments;

    /** @var  int $priority */
    private $priority;

    /** @var  string $queue */
    private $queue;

    /**
     * @param string $command
     * @param array $arguments
     */
    public function __construct(string $command, array $arguments = [])
    {
        $this->command = $command;
        $this->arguments = $arguments;
        $this->queue = 'default';
        $this->priority = 1;
    }

    /**
     * @return int
     */
    public function getId() : int
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getCommand() : string
    {
        return $this->command;
    }

    /**
     * @return array
     */
    public function getArguments() : array
    {
        return $this->arguments;
    }

    /**
     * @return int
     */
    public function getPriority() : int
    {
        return $this->priority;
    }

    /**
     * @return string
     */
    public function getQueue() : string
    {
        return $this->queue;
    }

    /**
     * @param int $priority
     * @return ScheduledJob
     */
    public function setPriority(int $priority) : self
    {
        $this->priority = $priority;

        return $this;
    }

    /**
     * @param string $queue
     * @return ScheduledJob
     */
    public function setQueue(string $queue) : self
    {
        $this->queue = $queue;

        return $this;
    }
}
