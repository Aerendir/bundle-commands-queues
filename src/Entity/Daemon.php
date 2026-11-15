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

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Config\DaemonConfig;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Repository\DaemonRepository;

/**
 * Represents a Daemon.
 */
#[ORM\Table(name: 'queues_daemons')]
#[ORM\Entity(repositoryClass: DaemonRepository::class)]
class Daemon implements \Stringable
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

    /** @var int */
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private $id;

    #[ORM\Column(name: 'born_on', type: 'datetime')]
    private \DateTime $bornOn;

    /** @var \DateTimeInterface|null */
    #[ORM\Column(name: 'died_on', type: 'datetime', nullable: true)]
    private $diedOn;

    /** @var string|null */
    #[ORM\Column(name: 'mortis_causa', type: 'string', length: 255, nullable: true)]
    private $mortisCausa;

    /**
     * @var Collection
     *
     * @ ORM\OneToMany(targetEntity="SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job", mappedBy="processedBy")
     */
    private ArrayCollection $processedJobs;

    /**
     * Daemon constructor.
     */
    public function __construct(#[ORM\Column(name: 'host', type: 'string', length: 255)]
        private string $host, #[ORM\Column(name: 'pid', type: 'integer')]
        private int $pid, #[ORM\Column(name: 'config', type: 'array')]
        private DaemonConfig $config)
    {
        $this->bornOn        = new \DateTime();
        $this->processedJobs = new ArrayCollection();
    }

    /**
     * This is required to solve cascade persisting issues.
     */
    public function __toString(): string
    {
        return (string) $this->id;
    }

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

    public function getMortisCausa(): ?string
    {
        return $this->mortisCausa;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPid(): int
    {
        return $this->pid;
    }

    public function getProcessedJobs(): Collection
    {
        return $this->processedJobs;
    }

    /**
     * Sets the date on which the daemon died.
     *
     * Requiescat In Pace (I'm Resting In Pace).
     */
    public function requiescatInPace(string $mortisCausa = self::MORTIS_SIGNAL): void
    {
        $this->diedOn      = new \DateTime();
        $this->mortisCausa = $mortisCausa;
    }
}
