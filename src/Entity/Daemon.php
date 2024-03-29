<?php

declare(strict_types=1);

/*
 * This file is part of the Serendipity HQ Commands Queues Bundle.
 *
 * Copyright (c) Adamo Aerendir Crespi <aerendir@serendipityhq.com>.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Entity;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Exception;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Config\DaemonConfig;

/**
 * Represents a Daemon.
 *
 * @ORM\Entity(repositoryClass="SerendipityHQ\Bundle\CommandsQueuesBundle\Repository\DaemonRepository")
 * @ORM\Table(name="queues_daemons")
 */
class Daemon
{
    /** @var string */
    public const DEFAULT_DAEMON_NAME = 'default';

    /** @var string */
    public const DEFAULT_QUEUE_NAME = 'default';

    /**
     * Used when a Daemon is killed due to a PCNTL signal.
     *
     * @var string
     */
    public const MORTIS_SIGNAL = 'signal';

    /** Used when a Daemon is not found anymore during the check of queues:run checkAliveDamons.
     *
     * @var string */
    public const MORTIS_STRAGGLER = 'straggler';

    /**
     * @var int
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     */
    private $id;

    /**
     * @var DaemonConfig
     *
     * @ORM\Column(name="config", type="array")
     */
    private $config;

    /**
     * @var string
     *
     * @ORM\Column(name="host", type="string", length=255)
     */
    private $host;

    /**
     * @var int
     *
     * @ORM\Column(name="pid", type="integer")
     */
    private $pid;

    /**
     * @var \DateTimeInterface
     *
     * @ORM\Column(name="born_on", type="datetime")
     */
    private $bornOn;

    /**
     * @var \DateTimeInterface|null
     *
     * @ORM\Column(name="died_on", type="datetime", nullable=true)
     */
    private $diedOn;

    /**
     * @var string|null
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
     * Daemon constructor.
     *
     * @param string       $host
     * @param int          $pid
     * @param DaemonConfig $config
     *
     * @throws Exception
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
     * @return \DateTime|\DateTimeImmutable
     */
    public function getBornOn(): \DateTimeInterface
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
     * @return \DateTime|\DateTimeImmutable|null
     */
    public function getDiedOn(): ?\DateTimeInterface
    {
        return $this->diedOn;
    }

    /**
     * @return string|null
     */
    public function getMortisCausa(): ?string
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
     *
     * @throws Exception
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
