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

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Util;

use Doctrine\ORM\UnitOfWork;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Service\JobsManager;
use SerendipityHQ\Bundle\ConsoleStyles\Console\Style\SerendipityHQStyle;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A class to profile the Daemon during the execution.
 */
final class Profiler
{
    /** @var string */
    private const SUCCESS_NOBG = 'success-nobg';

    /** @var string */
    private const ERROR_NOBG = 'error-nobg';

    /** @var float $startTime */
    private $startTime;

    /** @var float $aliveDaemonsLastCheckedAt The last time the alive daemons were checked */
    private $aliveDaemonsLastCheckedAt;

    /** @var float $lastMicrotime The last time the self::profile() method was called */
    private $lastMicrotime;

    /** @var float $lastOptimizationAt The last time the optimization were done */
    private $lastOptimizationAt;

    private ?int $lastMemoryUsage           = null;
    private ?int $lastMemoryUsageReal       = null;
    private ?int $lastUowSize               = null;
    private array $runningJobsLastCheckedAt = [];
    private ?int $highestMemoryPeak         = null;

    /** @var int $highestMemoryPeak */
    private $highestMemoryPeakReal;

    /** @var int $highestUowSize */
    private $highestUowSize;

    /** @var int $iterations How many times Daemon::mustRun() was called in the "while(Daemon::mustRun())" */
    private int $iterations = 0;

    /** @var int $maxRuntime After this amount of time the Daemon MUST die */
    private ?int $maxRuntime = null;

    private bool $memprofEnabled = false;

    /** @var int $pid The PID of the Daemon to be used to mark the callgrindout file */
    private ?int $pid = null;

    private array $profilingInfo                 = [];
    private static ?UnitOfWork $uow              = null;
    private static ?SerendipityHQStyle $ioWriter = null;

    public static function buildJobsList(): string
    {
        if (false === isset(self::$uow->getIdentityMap()[Job::class])) {
            return '';
        }

        $managedEntities = [];

        /** @var Job $job */
        foreach (self::$uow->getIdentityMap()[Job::class] as $job) {
            $managedEntities[] = '<success-nobg>#' . $job->getId() . '</success-nobg> (' . $job->getStatus() . ') [Em: ' . JobsManager::guessJobEmState($job) . ']';
        }

        asort($managedEntities);

        return \implode(', ', $managedEntities);
    }

    public static function setDependencies(SerendipityHQStyle $ioWriter, UnitOfWork $uow): void
    {
        self::$ioWriter = $ioWriter;
        self::$uow      = $uow;
    }

    public static function printUnitOfWork(?string $where = null): void
    {
        if (self::$ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $count   = isset(self::$uow->getIdentityMap()[Job::class]) ? \count(self::$uow->getIdentityMap()[Job::class]) : 0;
            $message = sprintf(
                'Currently there are <success-nobg>%s</success-nobg> Jobs managed <comment-nobg>(%s of %s)</comment-nobg>',
                $count, Helper::formatMemory(\memory_get_usage(false)), Helper::formatMemory(\memory_get_usage(true))
            );

            if (null !== $where) {
                $message = sprintf('[%s] %s', $where, $message);
            }

            self::$ioWriter->noteLineNoBg($message);
        }

        if (self::$ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
            $managedJobs = self::buildJobsList();

            self::$ioWriter->commentLineNoBg($managedJobs);
        }
    }

    /**
     * Start the profiler.
     *
     * @param int   $maxRuntime after this amount of time the Daemon MUST die
     * @param array $queues     The configured queues
     */
    public function start(int $pid, int $maxRuntime, array $queues): void
    {
        $microtime = \microtime(true);
        $this->pid = $pid;

        $this->startTime                 = $microtime;
        $this->lastMicrotime             = $microtime;
        $this->aliveDaemonsLastCheckedAt = $microtime;
        $this->lastOptimizationAt        = $microtime;
        $this->lastMicrotime             = $microtime;
        $this->lastOptimizationAt        = $microtime;
        $this->lastOptimizationAt        = $microtime;

        $this->lastUowSize = self::$uow->size() - 1;
        $this->highestUowSize
        // Subtract the daemon entity
        = self::$uow->size() - 1;

        foreach ($queues as $queue) {
            $this->runningJobsLastCheckedAt[$queue] = $this->startTime;
        }

        $this->lastMemoryUsage       = \memory_get_usage();
        $this->lastMemoryUsageReal   = \memory_get_usage(true);
        $this->highestMemoryPeak     = \memory_get_peak_usage();
        $this->highestMemoryPeakReal = \memory_get_peak_usage(true);
        $this->maxRuntime            = $maxRuntime;
    }

    /**
     * @suppress PhanUndeclaredFunction
     */
    public function profile(): array
    {
        $currentMicrotime       = \microtime(true);
        $currentMemoryUsage     = \memory_get_usage();
        $currentMemoryUsageReal = \memory_get_usage(true);
        $currentMemoryPeak      = \memory_get_peak_usage();
        $currentMemoryPeakReal  = \memory_get_peak_usage(true);
        // Subtract the daemon entity
        $currentUowSize        = self::$uow->size() - 1;
        $currentHighestUowSize = max($currentUowSize, $this->highestUowSize);

        $memoryDifference = $this->lastMemoryUsage - $currentMemoryUsage;
        $memoryDifference = 0 !== $memoryDifference ? \round($memoryDifference / $this->lastMemoryUsage * 100, 2) : 0;

        $memoryDifferenceReal = $this->lastMemoryUsageReal - $currentMemoryUsageReal;
        $memoryDifferenceReal = 0 !== $memoryDifferenceReal ? \round($memoryDifferenceReal / $this->lastMemoryUsageReal * 100, 2) : 0;

        $memoryPeakDifference = $this->highestMemoryPeak - $currentMemoryPeak;
        $memoryPeakDifference = 0 !== $memoryPeakDifference ? \round($memoryPeakDifference / $this->highestMemoryPeak * 100, 2) : 0;

        $memoryPeakDifferenceReal = $this->highestMemoryPeakReal - $currentMemoryPeakReal;
        $memoryPeakDifferenceReal = 0 !== $memoryPeakDifferenceReal ? \round($memoryPeakDifferenceReal / $this->highestMemoryPeakReal * 100, 2) : 0;

        $uowSizeDifference = $this->lastUowSize - $currentUowSize;
        $uowSizeDifference = (0 !== $uowSizeDifference && 0 !== $this->lastUowSize) ? \round($uowSizeDifference / $this->lastUowSize * 100, 2) : 0;

        $uowHighestSizeDifference = $this->highestUowSize - $currentHighestUowSize;
        $uowHighestSizeDifference = (0 !== $uowHighestSizeDifference && 0 !== $this->highestUowSize) ? \round($uowHighestSizeDifference / $this->highestUowSize * 100, 2) : 0;

        $this->profilingInfo = [
            ['', '<success-nobg>Time info</success-nobg>'],
            ['', 'Current Iteration', $this->getCurrentIteration()],
            ['', 'Elapsed Time', $currentMicrotime - $this->lastMicrotime],
            ['', 'Current Microtime', $this->formatTime($currentMicrotime)],
            ['', 'Last Microtime', $this->formatTime($this->lastMicrotime)],
            ['', 'Last optimization at', $this->formatTime($this->lastOptimizationAt)],
            ['', '', ''],
            ['', '<success-nobg>Memory info (memory_get_*(true))</success-nobg>'],
            [
                // If the difference is negative, then this is an increase in memory consumption
                $memoryDifferenceReal >= 0
                    ? sprintf('<%s>%s</>', self::SUCCESS_NOBG, "\xE2\x9C\x94")
                    : sprintf('<%s>%s</>', self::ERROR_NOBG, "\xE2\x9C\x96"), 'Allocated Memory',
                Helper::formatMemory($this->lastMemoryUsageReal) . ' => ' . Helper::formatMemory($currentMemoryUsageReal) . ' (' . ($memoryDifferenceReal <= 0 ? '+' : '-') . \abs($memoryDifferenceReal) . '%)',
            ],
            [
                $memoryPeakDifferenceReal >= 0
                    ? sprintf('<%s>%s</>', self::SUCCESS_NOBG, "\xE2\x9C\x94")
                    : sprintf('<%s>%s</>', self::ERROR_NOBG, "\xE2\x9C\x96"),
                'Allocated Memory Peak',
                Helper::formatMemory($this->highestMemoryPeakReal) . ' => ' . Helper::formatMemory($currentMemoryPeakReal) . ' (' . ($memoryPeakDifferenceReal <= 0 ? '+' : '-') . \abs($memoryPeakDifferenceReal) . '%)',
            ],
            ['', '', ''],
            ['', '<success-nobg>Memory info (memory_get_*(false))</success-nobg>'],
            [
                $memoryDifference >= 0
                    ? sprintf('<%s>%s</>', self::SUCCESS_NOBG, "\xE2\x9C\x94")
                    : sprintf('<%s>%s</>', self::ERROR_NOBG, "\xE2\x9C\x96"),
                'Memory Actually Used',
                Helper::formatMemory($this->lastMemoryUsage) . ' => ' . Helper::formatMemory($currentMemoryUsage) . ' (' . ($memoryDifference <= 0 ? '+' : '-') . \abs($memoryDifference) . '%)',
            ],
            [
                $memoryPeakDifference >= 0
                    ? sprintf('<%s>%s</>', self::SUCCESS_NOBG, "\xE2\x9C\x94")
                    : sprintf('<%s>%s</>', self::ERROR_NOBG, "\xE2\x9C\x96"),
                'Memory Actual Peak',
                Helper::formatMemory($this->highestMemoryPeak) . ' => ' . Helper::formatMemory($currentMemoryPeak) . ' (' . ($memoryPeakDifference <= 0 ? '+' : '-') . \abs($memoryPeakDifference) . '%)',
            ],
            ['', '', ''],
            ['', '<success-nobg>UnitOfWork info</success-nobg>'],
            [
                $uowSizeDifference >= 0
                    ? sprintf('<%s>%s</>', self::SUCCESS_NOBG, "\xE2\x9C\x94")
                    : sprintf('<%s>%s</>', self::ERROR_NOBG, "\xE2\x9C\x96"),
                'Uow size',
                $this->lastUowSize . ' => ' . $currentUowSize . ' (' . ($uowSizeDifference <= 0 ? '+' : '-') . \abs($uowSizeDifference) . '%)',
            ],
            [
                $uowHighestSizeDifference >= 0
                    ? sprintf('<%s>%s</>', self::SUCCESS_NOBG, "\xE2\x9C\x94")
                    : sprintf('<%s>%s</>', self::ERROR_NOBG, "\xE2\x9C\x96"),
                'Uow peak size',
                $this->highestUowSize . ' => ' . $currentHighestUowSize . ' (' . ($uowHighestSizeDifference <= 0 ? '+' : '-') . \abs($uowHighestSizeDifference) . '%)',
            ],
        ];

        $this->lastMicrotime         = $currentMicrotime;
        $this->lastMemoryUsage       = $currentMemoryUsage;
        $this->lastMemoryUsageReal   = $currentMemoryUsageReal;
        $this->lastUowSize           = $currentUowSize;
        $this->highestMemoryPeak     = max($currentMemoryPeak, $this->highestMemoryPeak);
        $this->highestMemoryPeakReal = $this->highestMemoryPeakReal < $currentMemoryPeakReal ? $currentMemoryPeakReal : $this->highestMemoryPeakReal;
        $this->highestUowSize        = $currentHighestUowSize;

        if (\function_exists('memprof_dump_callgrind') && $this->isMemprofEnabled()) {
            // Create the directory if it doesn't exist
            if (false === \file_exists('app/logs/callgrind') && false === \mkdir('app/logs/callgrind', 0777, true)) {
                throw new \RuntimeException('Impossible to create the directory app/logs/callgrind.');
            }

            $callgrindPath = sprintf(
                'app/logs/callgrind/callgrind.out.%s.%s.%s',
                (new \DateTime())->format('Y-m-d'), $this->pid, $this->getCurrentIteration()
            );
            // "w": writing only; "b": binary safe
            $callgrind = \fopen($callgrindPath, 'wb');
            if (false === $callgrind) {
                throw new \RuntimeException(sprintf('Impossible to open the file %s.', $callgrindPath));
            }

            \memprof_dump_callgrind($callgrind);
            $contents = \stream_get_contents($callgrind);
            if (false === $contents) {
                throw new \RuntimeException(sprintf('Impossible to read the file %s.', $callgrindPath));
            }

            if (false === \fwrite($callgrind, $contents)) {
                throw new \RuntimeException(sprintf('Impossible to write the file %s.', $callgrindPath));
            }

            if (false === \fclose($callgrind)) {
                throw new \RuntimeException(sprintf('Impossible to close the file %s.', $callgrindPath));
            }
        }

        return $this->profilingInfo;
    }

    /**
     * Prints the current profiling info.
     */
    public function printProfilingInfo(): void
    {
        self::$ioWriter->table(
            ['', 'Profiling info'],
            $this->profilingInfo
        );
    }

    public function getCurrentIteration(): int
    {
        return $this->iterations;
    }

    public function getAliveDaemonsLastCheckedAt(): float
    {
        return $this->aliveDaemonsLastCheckedAt;
    }

    public function getLastMicrotime(): float
    {
        return $this->lastMicrotime;
    }

    public function getLastOptimizationAt(): float
    {
        return $this->lastOptimizationAt;
    }

    public function getRunningJobsLastCheckedAt(string $queueName): float
    {
        return $this->runningJobsLastCheckedAt[$queueName];
    }

    /**
     * Sets to NOW the microtime of last check of alive damons.
     */
    public function aliveDaemonsJustCheked(): void
    {
        $this->aliveDaemonsLastCheckedAt = \microtime(true);
    }

    /**
     * Sets to NOW the microtime of the last optimization.
     */
    public function optimized(): void
    {
        $this->lastOptimizationAt = \microtime(true);
    }

    /**
     * Increment the number of iterations by 1.
     */
    public function hitIteration(): void
    {
        ++$this->iterations;
    }

    public function runningJobsJustChecked(string $queueName): void
    {
        $this->runningJobsLastCheckedAt[$queueName] = \microtime(true);
    }

    public function isMaxRuntimeReached(): bool
    {
        if (0 === $this->maxRuntime) {
            return false;
        }

        return \microtime(true) - $this->startTime > $this->maxRuntime;
    }

    public function isMemprofEnabled(): bool
    {
        return $this->memprofEnabled;
    }

    /**
     * Enables Memprof if required.
     *
     * @suppress PhanUndeclaredFunction
     */
    public function enableMemprof(): bool
    {
        // Intialize php-memprof
        if (\function_exists('memprof_enable') && true === \extension_loaded('memprof')) {
            \memprof_enable();

            return $this->memprofEnabled = true;
        }

        return false;
    }

    private function formatTime(float $time): string
    {
        $date = \DateTime::createFromFormat('U.u', \number_format($time, 6, '.', ''));

        if ( ! $date instanceof \DateTime) {
            throw new \RuntimeException('Impossible to parse the string into a valid DateTime object.');
        }

        return $date->format('Y-m-d H:i:s.u');
    }
}
