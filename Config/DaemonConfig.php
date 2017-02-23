<?php

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Config;

/**
 * Manages the configuration of a Daemon.
 */
class DaemonConfig extends AbstractConfig
{
    /** @var array $daemons */
    private $daemons;

    /** @var array $queues */
    private $queues;

    /** @var  string $name */
    private $name;

    /** @var  int $aliveDaemonsCheckInterval */
    private $aliveDaemonsCheckInterval;
    
    /** @var  int $idleTime */
    private $idleTime;

    /** @var  int $maxRuntime */
    private $maxRuntime;

    /** @var  int $optimizationInterval */
    private $optimizationInterval;

    /** @var  int $profilingInfoInterval */
    private $profilingInfoInterval;

    /** @var  bool $printProfilingInfo */
    private $printProfilingInfo;

    /**
     * @param array $daemons
     * @param array $queues
     */
    public function __construct(array $daemons, array $queues)
    {
        $this->daemons = $daemons;
        $this->queues = $queues;
    }

    /**
     * @param string|null $daemon
     */
    public function initialize($daemon)
    {
        if (null === $daemon) {
            if(count($this->daemons) > 1) {
                throw new \InvalidArgumentException(
                    'More than one Daemon is configured: you MUST specify the Daemon you want to run using the "--daemon"'
                    . ' argument'
                );
            }

            // Use as Daemon the only one configured
            $daemon = key($this->daemons);
        }

        $this->name = $daemon;
        $this->setAliveDaemonsCheckInterval($this->daemons[$daemon]['alive_daemons_check_interval']);
        $this->setIdleTime($this->daemons[$daemon]['idle_time']);
        $this->setMaxRuntime($this->daemons[$daemon]['max_runtime']);
        $this->setOptimizationInterval($this->daemons[$daemon]['optimization_interval']);
        $this->setProfilingInfoInterval($this->daemons[$daemon]['profiling_info_interval']);
        $this->setPrintProfilingInfo($this->daemons[$daemon]['print_profiling_info']);

        // Remove non needed queues configurations
        $queues = array_keys($this->queues);
        foreach ($queues as $queue) {
            // Do not unset the default queue
            if (false === array_search($queue, $this->daemons[$daemon]['queues']) && 'default' !== $queue) {
                unset($this->queues[$queue]);
            }
        }

        $this->daemons = null;
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
    public function getQueues() : array
    {
        return array_keys($this->queues);
    }

    /**
     * Returns the requested queue or the default one if the requested desn't exist.
     *
     * @param string $queueName
     * @return array
     */
    public function getQueue(string $queueName) : array
    {
        return $this->queues[$queueName] ?? $this->queues['default'];
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
    public function getIdleTime(): int
    {
        return $this->idleTime;
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
    public function getOptimizationInterval(): int
    {
        return $this->optimizationInterval;
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
     * @return bool
     */
    public function getRetryStaleJobs(string $queueName) : bool
    {
        return $this->queues[$queueName]['running_jobs_check_interval'];
    }
    
    /**
     * @param string $queueName
     * @return int
     */
    public function getRunningJobsCheckInterval(string $queueName): int
    {
        return $this->queues[$queueName]['running_jobs_check_interval'];
    }

    /**
     * @return array
     */
    public function getRepoConfig() : array
    {
        // Return the list of queues to include
        return [
            'included_queues' => array_keys($this->queues)
        ];
    }

    /**
     * @param int $aliveDaemonsCheckInterval
     */
    private function setAliveDaemonsCheckInterval(int $aliveDaemonsCheckInterval)
    {
        $this->aliveDaemonsCheckInterval = $aliveDaemonsCheckInterval;
    }

    /**
     * @param int $idleTime
     */
    private function setIdleTime(int $idleTime)
    {
        $this->idleTime = $idleTime;
    }

    /**
     * @param int $maxRuntime
     */
    private function setMaxRuntime(int $maxRuntime)
    {
        $this->maxRuntime = $maxRuntime;
    }

    /**
     * @param int $optimizationInterval
     */
    private function setOptimizationInterval(int $optimizationInterval)
    {
        $this->optimizationInterval = $optimizationInterval;
    }

    /**
     * @param int $printProfilingInfoInterval
     */
    private function setProfilingInfoInterval(int $printProfilingInfoInterval)
    {
        $this->profilingInfoInterval = $printProfilingInfoInterval;
    }

    /**
     * @param bool $printProfilingInfo
     */
    private function setPrintProfilingInfo(bool $printProfilingInfo)
    {
        $this->printProfilingInfo = $printProfilingInfo;
    }
}
