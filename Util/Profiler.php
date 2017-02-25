<?php

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Util;

use Symfony\Component\Console\Helper\Helper;

/**
 * A class to profile the Daemon during the execution.
 */
class Profiler
{
    /** @var float $startTime */
    private $startTime;

    /** @var float $aliveDaemonsLastCheckedAt The last time the alive daemons were checked */
    private $aliveDaemonsLastCheckedAt;

    /** @var float $lastMicrotime The last time the self::profile() method was called */
    private $lastMicrotime;

    /** @var float $lastOptimizationAt The last time the optimization were done */
    private $lastOptimizationAt;

    /** @var int $lastMemoryUsage */
    private $lastMemoryUsage;

    /** @var int $lastMemoryUsageReal */
    private $lastMemoryUsageReal;

    /** @var array $runningJobsLastCheckedAt */
    private $runningJobsLastCheckedAt = [];

    /** @var int $highestMemoryPeak */
    private $highestMemoryPeak;

    /** @var int $highestMemoryPeak */
    private $highestMemoryPeakReal;

    /** @var int $iterations How many times Daemon::mustRun() was called in the "while(Daemon::mustRun())" */
    private $iterations = 0;

    /** @var float $maxRuntime After this amount of time the Daemon MUST die */
    private $maxRuntime;

    /** @var  bool $memprofEnabled */
    private $memprofEnabled = false;

    /** @var  int $pid The PID of the Daemon to be used to mark the callgrindout file */
    private $pid;

    /**
     * Start the profiler.
     *
     * @param int $pid
     * @param float $maxRuntime After this amount of time the Daemon MUST die.
     * @param array $queues The configured queues
     */
    public function start(int $pid, float $maxRuntime, array $queues)
    {
        $this->pid = $pid;

        $this->startTime
            = $this->aliveDaemonsLastCheckedAt
            = $this->lastMicrotime
            = $this->lastOptimizationAt
            = microtime(true);

        foreach ($queues as $queue) {
            $this->runningJobsLastCheckedAt[$queue] = $this->startTime;
        }

        $this->lastMemoryUsage = memory_get_usage();
        $this->lastMemoryUsageReal = memory_get_usage(true);
        $this->highestMemoryPeak = memory_get_peak_usage();
        $this->highestMemoryPeakReal = memory_get_peak_usage(true);
        $this->maxRuntime = $maxRuntime;
    }

    /**
     * @return array
     */
    public function profile()
    {
        $currentMicrotime = microtime(true);
        $currentMemoryUsage = memory_get_usage();
        $currentMemoryUsageReal = memory_get_usage(true);
        $currentMemoryPeak = memory_get_peak_usage();
        $currentMemoryPeakReal = memory_get_peak_usage(true);

        $memoryDifference = $this->lastMemoryUsage - $currentMemoryUsage;
        $memoryDifference = 0 !== $memoryDifference ? round($memoryDifference / $this->lastMemoryUsage * 100, 2) : 0;

        $memoryDifferenceReal = $this->lastMemoryUsageReal - $currentMemoryUsageReal;
        $memoryDifferenceReal = 0 !== $memoryDifferenceReal ? round($memoryDifferenceReal / $this->lastMemoryUsageReal * 100, 2) : 0;

        $memoryPeakDifference = $this->highestMemoryPeak - $currentMemoryPeak;
        $memoryPeakDifference = 0 !== $memoryPeakDifference ? round($memoryPeakDifference / $this->highestMemoryPeak * 100, 2) : 0;

        $memoryPeakDifferenceReal = $this->highestMemoryPeakReal - $currentMemoryPeakReal;
        $memoryPeakDifferenceReal = 0 !== $memoryPeakDifferenceReal ? round($memoryPeakDifferenceReal / $this->highestMemoryPeakReal * 100, 2) : 0;

        $return = [
            ['', 'Microtime', $this->formatTime($currentMicrotime)],
            ['', 'Last Microtime', $this->formatTime($this->lastMicrotime)],
            ['', 'Memory Usage', Helper::formatMemory($currentMemoryUsage)],
            ['', 'Memory Usage (real)', Helper::formatMemory($currentMemoryUsageReal)],
            ['', 'Memory Peak', Helper::formatMemory($currentMemoryPeak)],
            ['', 'Memory Peak (real)', Helper::formatMemory($currentMemoryPeakReal)],
            ['', 'Current Iteration', $this->getCurrentIteration()],
            ['', 'Elapsed Time', $currentMicrotime - $this->lastMicrotime],
            // If the difference is negative, then this is an increase in memory consumption
            [
                $memoryDifference >= 0
                    ? sprintf('<%s>%s</>', 'success-nobg', "\xE2\x9C\x94")
                    : sprintf('<%s>%s</>', 'error-nobg', "\xE2\x9C\x96"),
                'Memory Usage Difference',
                ($memoryDifference <= 0 ? '+' : '-').abs($memoryDifference).'%',
            ],
            [
                $memoryDifferenceReal >= 0
                    ? sprintf('<%s>%s</>', 'success-nobg', "\xE2\x9C\x94")
                    : sprintf('<%s>%s</>', 'error-nobg', "\xE2\x9C\x96"),
                'Memory Usage Difference (real)',
                ($memoryDifferenceReal <= 0 ? '+' : '-').abs($memoryDifferenceReal).'%',
            ],
            [
                $memoryPeakDifference >= 0
                    ? sprintf('<%s>%s</>', 'success-nobg', "\xE2\x9C\x94")
                    : sprintf('<%s>%s</>', 'error-nobg', "\xE2\x9C\x96"),
                'Memory Peak Difference',
                ($memoryPeakDifference <= 0 ? '+' : '-').abs($memoryPeakDifference).'%',
            ],
            [
                $memoryPeakDifferenceReal >= 0
                    ? sprintf('<%s>%s</>', 'success-nobg', "\xE2\x9C\x94")
                    : sprintf('<%s>%s</>', 'error-nobg', "\xE2\x9C\x96"),
                'Memory Peak Difference (real)',
                ($memoryPeakDifferenceReal <= 0 ? '+' : '-').abs($memoryPeakDifferenceReal).'%',
            ],
        ];

        $this->lastMicrotime = $currentMicrotime;
        $this->lastMemoryUsage = $currentMemoryUsage;
        $this->lastMemoryUsageReal = $currentMemoryUsageReal;
        $this->highestMemoryPeak = $this->highestMemoryPeak < $currentMemoryPeak ? $currentMemoryPeak : $this->highestMemoryPeak;
        $this->highestMemoryPeakReal = $this->highestMemoryPeakReal < $currentMemoryPeakReal ? $currentMemoryPeakReal : $this->highestMemoryPeakReal;

        if ($this->isMemprofEnabled()) {
            // Create the directory if it doesn't exist
            if (false === file_exists('app/logs/callgrind')) {
                mkdir('app/logs/callgrind', 0777, true);
            }
            $callgrind = fopen(
                sprintf(
                    'app/logs/callgrind/callgrind.out.%s.%s.%s',
                    (new \DateTime())->format('Y-m-d'), $this->pid, $this->getCurrentIteration()
                ), "w");
            memprof_dump_callgrind($callgrind);
            fwrite($callgrind, stream_get_contents($callgrind));
            fclose($callgrind);
        }

        return $return;
    }

    /**
     * @return int
     */
    public function getCurrentIteration() : int
    {
        return $this->iterations;
    }

    /**
     * @return float
     */
    public function getAliveDaemonsLastCheckedAt(): float
    {
        return $this->aliveDaemonsLastCheckedAt;
    }

    /**
     * @return float
     */
    public function getLastMicrotime(): float
    {
        return $this->lastMicrotime;
    }

    /**
     * @return float
     */
    public function getLastOptimizationAt() : float
    {
        return $this->lastOptimizationAt;
    }

    /**
     * @param string $queueName
     * @return float
     */
    public function getRunningJobsLastCheckedAt(string $queueName) : float
    {
        return $this->runningJobsLastCheckedAt[$queueName];
    }

    /**
     * Sets to NOW the microtime of last check of alive damons.
     */
    public function aliveDaemonsJustCheked()
    {
        $this->aliveDaemonsLastCheckedAt = microtime(true);
    }

    /**
     * Sets to NOW the microtime of the last optimization.
     */
    public function optimized()
    {
        $this->lastOptimizationAt = microtime(true);
    }

    /**
     * Increment the number of iterations by 1.
     */
    public function hitIteration()
    {
        $this->iterations++;
    }

    /**
     * @param string $queueName
     */
    public function runningJobsJustChecked(string $queueName)
    {
        $this->runningJobsLastCheckedAt[$queueName] = microtime(true);
    }

    /**
     * @return bool
     */
    public function isMaxRuntimeReached() : bool
    {
        return microtime(true) - $this->startTime > $this->maxRuntime;
    }

    /**
     * @return bool
     */
    public function isMemprofEnabled() : bool
    {
        return $this->memprofEnabled;
    }

    /**
     * Enables Memprof if required.
     */
    public function enableMemprof()
    {
        // Intialize php-memprof
        if (true === extension_loaded('memprof')) {
            memprof_enable();
            return $this->memprofEnabled = true;
        }

        return false;
    }

    /**
     * @param float $time
     *
     * @return string
     */
    private function formatTime(float $time)
    {
        $date = \DateTime::createFromFormat('U.u', number_format($time, 6, '.', ''));

        return $date->format('Y-m-d H:i:s.u');
    }
}
