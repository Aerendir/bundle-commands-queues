<?php

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

/**
 * Represents a Daemon.
 */
class Daemon
{
    /** Used when a Daemon is killed due to a PCNTL signal */
    const MORTIS_SIGNAL = 'signal';

    /** Used when a Daemon is not found anymore during the check of queues:run checkAliveDamons */
    const MORTIS_STRAGGLER = 'straggler';

    /** @var int $id */
    private $id;

    /** @var array $config */
    private $config = [];

    /** @var string $host */
    private $host;

    /** @var int $pid */
    private $pid;

    /** @var \DateTime $bornOn */
    private $bornOn;

    /** @var \DateTime $diedOn */
    private $diedOn;

    /** @var string $mortisCausa */
    private $mortisCausa;

    /** @var Collection $processedJobs */
    private $processedJobs;

    /**
     * @param string $host
     * @param int    $pid
     * @param array  $config
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
    public function getMortisCausa()
    {
        return $this->mortisCausa;
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
     *
     * @param string $mortisCausa
     */
    public function requiescatInPace(string $mortisCausa = self::MORTIS_SIGNAL)
    {
        $this->diedOn = new \DateTime();
        $this->mortisCausa = $mortisCausa;
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
