<?php

namespace SerendipityHQ\Bundle\QueuesBundle\Util;

/**
 * A class to profile the Daemon during the execution.
 */
class Profiler
{
    /** @var float $startTime */
    private $startTime;

    /** @var float $lastMicrotime The last time the self::profile() method was called */
    private $lastMicrotime;

    /** @var int $lastMemoryUsage */
    private $lastMemoryUsage;

    /** @var int $highestMemoryPeak */
    private $highestMemoryPeak;

    /** @var int $iterations How many times Daemon::mustRun() was called in the "while(Daemon::mustRun())" */
    private $iterations = 0;

    /** @var float $maxRuntime After this amount of time the Daemon MUST die */
    private $maxRuntime;

    /**
     * Start the profiler.
     *
     * @param float $maxRuntime After this amount of time the Daemon MUST die.
     */
    public function start(float $maxRuntime)
    {
        $this->startTime = $this->lastMicrotime = microtime(true);
        $this->lastMemoryUsage = memory_get_usage(true);
        $this->highestMemoryPeak = memory_get_peak_usage(true);
        $this->maxRuntime = $maxRuntime;
    }

    /**
     * @return array
     */
    public function profile()
    {
        $currentMicrotime = microtime(true);
        $currentMemoryUsage = memory_get_usage(true);
        $currentMemoryPeak = memory_get_peak_usage(true);

        $memoryDifference = $this->lastMemoryUsage - $currentMemoryUsage;
        $memoryDifference = 0 !== $memoryDifference ? round($memoryDifference / $this->lastMemoryUsage * 100, 2) : 0;

        $memoryPeakDifference = $this->highestMemoryPeak - $currentMemoryPeak;
        $memoryPeakDifference = 0 !== $memoryPeakDifference ? round($memoryPeakDifference / $this->highestMemoryPeak * 100, 2) : 0;

        $return = [
            ['', 'microtime', $this->formatTime($currentMicrotime)],
            ['', 'last_microtime', $this->formatTime($this->lastMicrotime)],
            ['', 'memory_usage', $this->formatMemory($currentMemoryUsage)],
            ['', 'memory_peak', $this->formatMemory($currentMemoryPeak)],
            ['', 'elapsed_time', $currentMicrotime - $this->lastMicrotime],
            // If the difference is negative, then this is an increase in memory consumption
            [
                $memoryDifference <= 0
                    ? sprintf('<%s>%s</>', 'success-nobg', "\xE2\x9C\x94")
                    : sprintf('<%s>%s</>', 'error-nobg', "\xE2\x9C\x96"),
                'memory_usage_difference',
                ($memoryDifference <= 0 ? '+' : '-').abs($memoryDifference).'%'],
            [
                $memoryDifference <= 0
                    ? sprintf('<%s>%s</>', 'success-nobg', "\xE2\x9C\x94")
                    : sprintf('<%s>%s</>', 'error-nobg', "\xE2\x9C\x96"),
                'memory_peak_difference',
                ($memoryPeakDifference <= 0 ? '+' : '-').abs($memoryPeakDifference).'%'],
        ];

        $this->lastMicrotime = $currentMicrotime;
        $this->lastMemoryUsage = $currentMemoryUsage;
        $this->highestMemoryPeak = $this->highestMemoryPeak < $currentMemoryPeak ? $currentMemoryPeak : $this->highestMemoryPeak;

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
     * Increment the number of iterations by 1.
     */
    public function hitIteration()
    {
        $this->iterations++;
    }

    /**
     * @return bool
     */
    public function isMaxRuntimeReached() : bool
    {
        return microtime(true) - $this->startTime > $this->maxRuntime;
    }

    /**
     * Format an integer in bytes.
     *
     * @see http://php.net/manual/en/function.memory-get-usage.php#96280
     *
     * @param $size
     *
     * @return string
     */
    private function formatMemory($size)
    {
        $isNegative = false;
        $unit = ['b', 'kb', 'mb', 'gb', 'tb', 'pb'];

        if (0 > $size) {
            // This is a negative value
            $isNegative = true;
        }

        $return = ($isNegative) ? '-' : '';

        return $return
            .round(
                abs($size) / pow(1024, ($i = floor(log(abs($size), 1024)))), 2
            )
            .' '
            .$unit[$i];
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
