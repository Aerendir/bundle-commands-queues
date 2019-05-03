<?php

declare(strict_types=1);

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

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Config;

use InvalidArgumentException;
use SerendipityHQ\Bundle\CommandsQueuesBundle\DependencyInjection\Configuration;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Daemon;

/**
 * Manages the configuration of a Daemon.
 */
class DaemonConfig extends AbstractConfig
{
    /** @var array $daemons */
    private $daemons;

    /** @var array $queues */
    private $queues;

    /** @var string $name */
    private $name;

    /** @var bool $prodAllowed If true, passes the --env=prod flag to the commands in the queue */
    private $prodAllowed;

    /** @var int $aliveDaemonsCheckInterval */
    private $aliveDaemonsCheckInterval;

    /** @var int $sleepFor */
    private $sleepFor;

    /** @var int $managedEntitiesTreshold */
    private $managedEntitiesTreshold;

    /** @var int $maxRuntime */
    private $maxRuntime;

    /** @var int $profilingInfoInterval */
    private $profilingInfoInterval;

    /** @var bool $printProfilingInfo */
    private $printProfilingInfo;

    /**
     * @param array $daemons
     * @param array $queues
     */
    public function __construct(array $daemons, array $queues)
    {
        $this->daemons = $daemons;
        $this->queues  = $queues;
    }

    /**
     * @param string|null $daemon
     * @param bool        $allowProd
     */
    public function initialize(?string $daemon, bool $allowProd): void
    {
        if (null === $daemon) {
            if (count($this->daemons) > 1) {
                throw new InvalidArgumentException(
                    'More than one Daemon is configured: you MUST specify the Daemon you want to run passing it as the first argument argument.'
                );
            }

            // Use as Daemon the only one configured
            $daemon = (string) key($this->daemons);
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
        $queues = array_keys($this->queues);
        foreach ($queues as $queue) {
            // Do not unset the default queue
            if (Daemon::DEFAULT_QUEUE_NAME !== $queue && false === in_array($queue, $this->daemons[$daemon]['queues'], true)) {
                unset($this->queues[$queue]);
            }
        }

        $this->daemons = [];
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return array
     */
    public function getQueues(): array
    {
        return array_keys($this->queues);
    }

    /**
     * Returns the requested queue or the default one if the requested desn't exist.
     *
     * @param string $queueName
     *
     * @return array
     */
    public function getQueue(string $queueName): array
    {
        return $this->queues[$queueName] ?? $this->queues[Daemon::DEFAULT_QUEUE_NAME];
    }

    /**
     * @return bool
     */
    public function isProdAllowed(): bool
    {
        return $this->prodAllowed;
    }

    /**
     * @return int
     */
    public function getAliveDaemonsCheckInterval(): int
    {
        return $this->aliveDaemonsCheckInterval;
    }

    /**
     * @return int
     */
    public function getSleepFor(): int
    {
        return $this->sleepFor;
    }

    /**
     * @return int
     */
    public function getMaxRuntime(): int
    {
        return $this->maxRuntime;
    }

    /**
     * @return int
     */
    public function getManagedEntitiesTreshold(): int
    {
        return $this->managedEntitiesTreshold;
    }

    /**
     * @return int
     */
    public function getProfilingInfoInterval(): int
    {
        return $this->profilingInfoInterval;
    }

    /**
     * @return bool
     */
    public function printProfilingInfo(): bool
    {
        return $this->printProfilingInfo;
    }

    /**
     * @param string $queueName
     *
     * @return bool
     */
    public function getRetryStaleJobs(string $queueName): bool
    {
        return $this->queues[$queueName][Configuration::QUEUE_RETRY_STALE_JOBS_KEY];
    }

    /**
     * @param string $queueName
     *
     * @return int
     */
    public function getRunningJobsCheckInterval(string $queueName): int
    {
        return $this->queues[$queueName][Configuration::QUEUE_RUNNING_JOBS_CHECK_INTERVAL_KEY];
    }

    /**
     * @return array
     */
    public function getRepoConfig(): array
    {
        // Return the list of queues to include
        return [
            'included_queues' => array_keys($this->queues),
        ];
    }

    /**
     * @param bool $prodAllowed
     */
    public function setProdAllowed(bool $prodAllowed): void
    {
        $this->prodAllowed = $prodAllowed;
    }

    /**
     * @param int $aliveDaemonsCheckInterval
     */
    private function setAliveDaemonsCheckInterval(int $aliveDaemonsCheckInterval): void
    {
        $this->aliveDaemonsCheckInterval = $aliveDaemonsCheckInterval;
    }

    /**
     * @param int $sleepFor
     */
    private function setSleepFor(int $sleepFor): void
    {
        $this->sleepFor = $sleepFor;
    }

    /**
     * @param int $managedEntitiesTreshold
     */
    private function setManagedEntitiesTreshold(int $managedEntitiesTreshold): void
    {
        $this->managedEntitiesTreshold = $managedEntitiesTreshold;
    }

    /**
     * @param int $maxRuntime
     */
    private function setMaxRuntime(int $maxRuntime): void
    {
        $this->maxRuntime = $maxRuntime;
    }

    /**
     * @param int $printProfilingInfoInterval
     */
    private function setProfilingInfoInterval(int $printProfilingInfoInterval): void
    {
        $this->profilingInfoInterval = $printProfilingInfoInterval;
    }

    /**
     * @param bool $printProfilingInfo
     */
    private function setPrintProfilingInfo(bool $printProfilingInfo): void
    {
        $this->printProfilingInfo = $printProfilingInfo;
    }
}
