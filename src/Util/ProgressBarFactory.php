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

use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Creates a ProgressBarFactory to display Jobs processing advancing.
 */
final class ProgressBarFactory
{
    /**
     * @var string
     */
    public const FORMAT_CREATE_JOBS          = '<success-nobg>%current%</success-nobg>/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% (%memory_nr:6s% of %memory:6s%)';

    /**
     * @var string
     */
    public const FORMAT_INITIALIZING_JOBS    = '<info-nobg>[>] Job <success-nobg>%current%</success-nobg>/%max% initialized (%percent:3s%% )</info-nobg><comment-nobg> %elapsed:6s%/%estimated:-6s% (%memory_nr:6s% of %memory:6s%)</comment-nobg>';

    /**
     * @var string
     */
    public const FORMAT_PROCESS_RUNNING_JOBS = '<info-nobg>[>] Processing job <success-nobg>%current%</success-nobg>/%max% (%percent:3s%% )</info-nobg><comment-nobg> %elapsed:6s%/%estimated:-6s% (%memory_nr:6s% of %memory:6s%)</comment-nobg>';

    /**
     * @var string
     */
    public const FORMAT_PROCESS_STALE_JOBS   = '<info-nobg>[>] Processing job <success-nobg>%current%</success-nobg>/%max% (%percent%%)</info-nobg><comment-nobg> %elapsed:6s%/%estimated:-6s%  (%memory_nr:6s% of %memory:6s%)</comment-nobg>';

    /**
     * @param string          $format
     * @param OutputInterface $output
     * @param int             $howManyJobs
     *
     * @return ProgressBar
     */
    public static function createProgressBar(string $format, OutputInterface $output, int $howManyJobs = 0): ProgressBar
    {
        ProgressBar::setPlaceholderFormatterDefinition(
            'memory_nr',
            function (ProgressBar $bar, OutputInterface $output) {
                return Helper::formatMemory(\memory_get_usage(false));
            }
        );
        $progress = new ProgressBar($output, $howManyJobs);
        $progress->setFormat($format);

        return $progress;
    }
}
