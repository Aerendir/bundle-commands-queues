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

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Config;

use SerendipityHQ\Bundle\CommandsQueuesBundle\DependencyInjection\Configuration;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Daemon;

/**
 * Manages the configuration of a Daemon.
 */
final class DaemonConfig extends AbstractConfig
{
    private ?string $name = null;

    /** @var bool $prodAllowed If true, passes the --env=prod flag to the commands in the queue */
    private bool $prodAllowed = false;

    private int $aliveDaemonsCheckInterval = 0;
    private int $sleepFor                  = 0;
    private int $managedEntitiesTreshold   = 0;
    private int $maxRuntime                = 0;
    private int $profilingInfoInterval     = 0;
    private bool $printProfilingInfo       = false;

    public function __construct(private array $daemons, private array $queues)
    {
    }

    public function initialize(?string $daemon, bool $allowProd): void
    {
        if (null === $daemon) {
            if ((is_countable($this->daemons) ? \count($this->daemons) : 0) > 1) {
                throw new \InvalidArgumentException('More than one Daemon is configured: you MUST specify the Daemon you want to run passing it as the first argument argument.');
            }

            // Use as Daemon the only one configured
            $daemon = (string) \key($this->daemons);
        }

        if (empty($daemon)) {
            $daemon = Daemon::DEFAULT_DAEMON_NAME;
        }

        $this->name = $daemon;
        $this->setProdAllowed($allowProd);
        $this->setAliveDaemonsCheckInterval($this->daemons[$daemon][Configuration::DAEMON_ALIVE_DAEMONS_CHECK_INTERVAL_KEY]);
        $this->setManagedEntitiesTreshold($this->daemons[$daemon][Configuration::DAEMON_MANAGED_ENTITIES_TRESHOLD_KEY]);
        $this->setMaxRuntime($this->daemons[$daemon][Configuration::DAEMON_MAX_RUNTIME_KEY]);
        $this->setProfilingInfoInterval($this->daemons[$daemon][Configuration::DAEMON_PROFILING_INFO_INTERVAL_KEY]);
        $this->setPrintProfilingInfo($this->daemons[$daemon][Configuration::DAEMON_PRINT_PROFILING_INFO_KEY]);
        $this->setSleepFor($this->daemons[$daemon][Configuration::DAEMON_SLEEP_FOR_KEY]);

        // Remove non needed queues configurations
        $queues = \array_keys($this->queues);
        foreach ($queues as $queue) {
            // Do not unset the default queue
            if (Daemon::DEFAULT_QUEUE_NAME !== $queue && false === \in_array($queue, $this->daemons[$daemon]['queues'], true)) {
                unset($this->queues[$queue]);
            }
        }

        $this->daemons = [];
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getQueues(): array
    {
        return \array_keys($this->queues);
    }

    /**
     * Returns the requested queue or the default one if the requested desn't exist.
     */
    public function getQueue(string $queueName): array
    {
        return $this->queues[$queueName] ?? $this->queues[Daemon::DEFAULT_QUEUE_NAME];
    }

    public function isProdAllowed(): bool
    {
        return $this->prodAllowed;
    }

    public function getAliveDaemonsCheckInterval(): int
    {
        return $this->aliveDaemonsCheckInterval;
    }

    public function getSleepFor(): int
    {
        return $this->sleepFor;
    }

    public function getMaxRuntime(): int
    {
        return $this->maxRuntime;
    }

    public function getManagedEntitiesTreshold(): int
    {
        return $this->managedEntitiesTreshold;
    }

    public function getProfilingInfoInterval(): int
    {
        return $this->profilingInfoInterval;
    }

    public function printProfilingInfo(): bool
    {
        return $this->printProfilingInfo;
    }

    public function getRetryStaleJobs(string $queueName): bool
    {
        return $this->queues[$queueName][Configuration::QUEUE_RETRY_STALE_JOBS_KEY];
    }

    public function getRunningJobsCheckInterval(string $queueName): int
    {
        return $this->queues[$queueName][Configuration::QUEUE_RUNNING_JOBS_CHECK_INTERVAL_KEY];
    }

    public function getRepoConfig(): array
    {
        // Return the list of queues to include
        return [
            'included_queues' => \array_keys($this->queues),
        ];
    }

    public function setProdAllowed(bool $prodAllowed): void
    {
        $this->prodAllowed = $prodAllowed;
    }

    private function setAliveDaemonsCheckInterval(int $aliveDaemonsCheckInterval): void
    {
        $this->aliveDaemonsCheckInterval = $aliveDaemonsCheckInterval;
    }

    private function setSleepFor(int $sleepFor): void
    {
        $this->sleepFor = $sleepFor;
    }

    private function setManagedEntitiesTreshold(int $managedEntitiesTreshold): void
    {
        $this->managedEntitiesTreshold = $managedEntitiesTreshold;
    }

    private function setMaxRuntime(int $maxRuntime): void
    {
        $this->maxRuntime = $maxRuntime;
    }

    private function setProfilingInfoInterval(int $printProfilingInfoInterval): void
    {
        $this->profilingInfoInterval = $printProfilingInfoInterval;
    }

    private function setPrintProfilingInfo(bool $printProfilingInfo): void
    {
        $this->printProfilingInfo = $printProfilingInfo;
    }
}
