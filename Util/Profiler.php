<?php

namespace SerendipityHQ\Bundle\QueuesBundle\Util;

/**
 * A class to profile the Daemon during the execution.
 */
class Profiler
{
    private $lastMicrotime;
    private $lastMemoryUsage;
    private $highestMemoryPeak;

    public function __construct()
    {
        $this->lastMicrotime = microtime(true);
        $this->lastMemoryUsage = memory_get_usage(true);
        $this->highestMemoryPeak = memory_get_peak_usage(true);
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
            ['microtime', $this->formatTime($currentMicrotime)],
            ['last_microtime', $this->formatTime($this->lastMicrotime)],
            ['memory_usage', $this->formatMemory($currentMemoryUsage)],
            ['memory_peak', $this->formatMemory($currentMemoryPeak)],
            // If the difference is negative, then this is an increase in memory consumption
            ['memory_usage_difference', ($memoryDifference <= 0 ? '+' : '-') . abs($memoryDifference) . '%'],
            ['memory_peak_difference', ($memoryPeakDifference <= 0 ? '+' : '-') . abs($memoryPeakDifference) . '%'],
            ['elapsed_time', $currentMicrotime - $this->lastMicrotime]
        ];

        $this->lastMicrotime = $currentMicrotime;
        $this->lastMemoryUsage = $currentMemoryUsage;
        $this->highestMemoryPeak = $this->highestMemoryPeak < $currentMemoryPeak ? $currentMemoryPeak : $this->highestMemoryPeak;

        return $return;
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
     * @return string
     */
    private function formatTime(float $time)
    {
        $date = \DateTime::createFromFormat('U.u', number_format($time, 6, '.', ''));
        return $date->format('Y-m-d H:i:s.u');
    }
}
