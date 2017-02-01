<?php

namespace SerendipityHQ\Bundle\QueuesBundle\Model;

/**
 * Basic properties and methods o a Job.
 */
class ScheduledJob
{
    /** The job is just inserted. It is not already started. */
    const STATE_NEW = 'new';

    /** The job is currently running. */
    const STATE_RUNNING = 'running';

    /** The job was processed and finished with success. */
    const STATE_FINISHED = 'finished';

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

    /** @var  string $status */
    private $status;

    /** @var  \DateTime $createdAt */
    private $createdAt;

    /** @var  \DateTime $startedAt */
    private $startedAt;

    /** @var  \DateTime $closedAt When the Job is marked as Finished, Failed or Terminated. */
    private $closedAt;

    /**
     * @param string $command
     * @param array|string $arguments
     */
    public function __construct(string $command, $arguments = [])
    {
        if (false === is_string($arguments) && false === is_array($arguments)) {
            throw new \InvalidArgumentException('Second parameter $arguments can be only an array or a string.');
        }

        // If is a String...
        if (is_string($arguments)) {
            // Transform into an array
            $arguments = explode(' ', $arguments);

            // And remove leading and trailing spaces
            $arguments = array_map(function($value) {return trim($value);}, $arguments);
        }

        $this->command = $command;
        $this->arguments = $arguments;
        $this->priority = 1;
        $this->queue = 'default';
        $this->status = self::STATE_NEW;
        $this->createdAt = new \DateTime();
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
     * @return \DateTime
     */
    public function getCreatedAt() : \DateTime
    {
        return $this->createdAt;
    }

    /**
     * @return \DateTime|null
     */
    public function getStartedAt()
    {
        return $this->startedAt;
    }

    /**
     * @return \DateTime|null
     */
    public function getClosedAt()
    {
        return $this->closedAt;
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
