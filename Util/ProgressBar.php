<?php

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Util;

use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Creates a ProgressBar to display Jobs processing advancing.
 */
class ProgressBar
{
    const FORMAT_CREATE_JOBS = '<success-nobg>%current%</success-nobg>/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% (%memory_nr:6s% of %memory:6s%)';
    const FORMAT_INITIALIZING_JOBS = '<info-nobg>[>] Job <success-nobg>%current%</success-nobg>/%max% initialized (%percent:3s%% )</info-nobg><comment-nobg> %elapsed:6s%/%estimated:-6s% (%memory_nr:6s% of %memory:6s%)</comment-nobg>';
    const FORMAT_PROCESS_RUNNING_JOBS = '<info-nobg>[>] Processing job <success-nobg>%current%</success-nobg>/%max% (%percent:3s%% )</info-nobg><comment-nobg> %elapsed:6s%/%estimated:-6s% (%memory_nr:6s% of %memory:6s%)</comment-nobg>';
    const FORMAT_PROCESS_STALE_JOBS = '<info-nobg>[>] Processing job <success-nobg>%current%</success-nobg>/%max% (%percent%%)</info-nobg><comment-nobg> %elapsed:6s%/%estimated:-6s%  (%memory_nr:6s% of %memory:6s%)</comment-nobg>';

    /**
     * @param string          $format
     * @param OutputInterface $output
     * @param int             $howManyJobs
     *
     * @return \Symfony\Component\Console\Helper\ProgressBar
     */
    public static function createProgressBar(string $format, OutputInterface $output, int $howManyJobs = 0)
    {
        \Symfony\Component\Console\Helper\ProgressBar::setPlaceholderFormatterDefinition(
            'memory_nr',
            function (\Symfony\Component\Console\Helper\ProgressBar $bar, OutputInterface $output) {
                return Helper::formatMemory(memory_get_usage(false));
            }
        );
        $progress = new \Symfony\Component\Console\Helper\ProgressBar($output, $howManyJobs);
        $progress->setFormat($format);

        return $progress;
    }
}
