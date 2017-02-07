<?php

namespace SerendipityHQ\Bundle\QueuesBundle\Entity;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

/**
 * Represents a Daemon.
 */
class Daemon
{
    /** @var  int $id */
    private $id;

    /** @var  array $config */
    private $config = [];

    /** @var string $host */
    private $host;

    /** @var  int $pid */
    private $pid;

    /** @var  \DateTime $bornOn */
    private $bornOn;

    /** @var  \DateTime $diedOn */
    private $diedOn;

    /** @var  Collection $processedJobs */
    private $processedJobs;

    /**
     * @param string $host
     * @param int $pid
     * @param array $config
     */
    public function __construct(string $host, int $pid, array $config = [])
    {
        $this->bornOn = new \DateTime();
        $this->config = $config;
        $this->host = $host;
        $this->pid = $pid;
        $this->processedJobs = new ArrayCollection();
    }

    /**
     * @return int
     */
    public function getId() : int
    {
        return $this->id;
    }

    /**
     * @return \DateTime
     */
    public function getBornOn() : \DateTime
    {
        return $this->bornOn;
    }

    /**
     * @return array
     */
    public function getConfig() : array
    {
        return $this->config;
    }

    /**
     * @return \DateTime|null
     */
    public function getDiedOn()
    {
        return $this->diedOn;
    }

    /**
     * @return string
     */
    public function getHost() : string
    {
        return $this->host;
    }

    /**
     * @return int
     */
    public function getPid() : int
    {
        return $this->pid;
    }

    /**
     * @return Collection
     */
    public function getProcessedJobs() : Collection
    {
        return $this->processedJobs;
    }

    /**
     * Sets the date on which the daemon died.
     *
     * Requiescat In Pace (I'm Resting In Pace).
     */
    public function requiescatInPace()
    {
        $this->diedOn = new \DateTime();
    }

    /**
     * This is required to solve cascade persisting issues.
     *
     * @return string
     */
    public function __toString() : string
    {
        return (string) $this->id;
    }
}
