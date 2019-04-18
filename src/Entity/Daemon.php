<?php

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

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Entity;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Config\DaemonConfig;

/**
 * Represents a Daemon.
 *
 * @ORM\Entity(repositoryClass="SerendipityHQ\Bundle\CommandsQueuesBundle\Repository\DaemonRepository")
 * @ORM\Table(name="queues_daemons")
 */
class Daemon
{
    /** Used when a Daemon is killed due to a PCNTL signal
     *
     * @var string
     */
    const MORTIS_SIGNAL = 'signal';

    /** Used when a Daemon is not found anymore during the check of queues:run checkAliveDamons
     *
     * @var string */
    const MORTIS_STRAGGLER = 'straggler';

    /**
     * @var int
     *
     * @ORM\Column(name="id", type="integer", nullable=false)
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     */
    private $id;

    /**
     * @var DaemonConfig
     *
     * @ORM\Column(name="config", type="array", nullable=false)
     */
    private $config = [];

    /**
     * @var string
     *
     * @ORM\Column(name="host", type="string", length=255, nullable=false)
     */
    private $host;

    /**
     * @var int
     *
     * @ORM\Column(name="pid", type="integer", nullable=false)
     */
    private $pid;

    /**
     * @var DateTime
     *
     * @ORM\Column(name="born_on", type="datetime", nullable=false)
     */
    private $bornOn;

    /**
     * @var DateTime
     *
     * @ORM\Column(name="died_on", type="datetime", nullable=true)
     */
    private $diedOn;

    /**
     * @var string
     *
     * @ORM\Column(name="mortis_causa", type="string", length=255, nullable=true)
     */
    private $mortisCausa;

    /**
     * @var Collection
     *
     * @ ORM\OneToMany(targetEntity="SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job", mappedBy="processedBy")
     */
    private $processedJobs;

    /**
     * @param string       $host
     * @param int          $pid
     * @param DaemonConfig $config
     */
    public function __construct(string $host, int $pid, DaemonConfig $config)
    {
        $this->bornOn        = new DateTime();
        $this->config        = $config;
        $this->host          = $host;
        $this->pid           = $pid;
        $this->processedJobs = new ArrayCollection();
    }

    /**
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @return DateTime
     */
    public function getBornOn(): DateTime
    {
        return $this->bornOn;
    }

    /**
     * @return DaemonConfig
     */
    public function getConfig(): DaemonConfig
    {
        return $this->config;
    }

    /**
     * @return DateTime|null
     */
    public function getDiedOn(): DateTime
    {
        return $this->diedOn;
    }

    /**
     * @return string
     */
    public function getMortisCausa(): string
    {
        return $this->mortisCausa;
    }

    /**
     * @return string
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * @return int
     */
    public function getPid(): int
    {
        return $this->pid;
    }

    /**
     * @return Collection
     */
    public function getProcessedJobs(): Collection
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
    public function requiescatInPace(string $mortisCausa = self::MORTIS_SIGNAL): void
    {
        $this->diedOn      = new DateTime();
        $this->mortisCausa = $mortisCausa;
    }

    /**
     * This is required to solve cascade persisting issues.
     *
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->id;
    }
}
