<?php

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

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Command;

use Countable;
use DateTime;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Util\ProgressBar;
use SerendipityHQ\Component\ThenWhen\Strategy\ConstantStrategy;
use SerendipityHQ\Component\ThenWhen\Strategy\ExponentialStrategy;
use SerendipityHQ\Component\ThenWhen\Strategy\LinearStrategy;
use SerendipityHQ\Component\ThenWhen\Strategy\LiveStrategy;
use SerendipityHQ\Component\ThenWhen\Strategy\NeverRetryStrategy;
use SerendipityHQ\Component\ThenWhen\Strategy\StrategyInterface;
use SerendipityHQ\Component\ThenWhen\Strategy\TimeFixedStrategy;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Creates random Jobs.
 */
class TestRandomJobsCommand extends AbstractQueuesCommand
{
    private $queues = [
        'queue_1', 'queue_2', 'queue_3', 'queue_4', 'queue_5',
    ];

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this
            ->setName('queues:test:random-jobs')
            ->setDescription('[INTERNAL] Generates random Jobs to test SHQCommandsQueuesBundle.')
            ->setDefinition(
                new InputDefinition([
                    new InputArgument('how-many-jobs', InputArgument::OPTIONAL, 'How many random Jobs would you like to create?', 10),
                    new InputOption('batch', null, InputOption::VALUE_OPTIONAL, 'The number of Jobs in a batch.', 10),
                    new InputOption('no-future-jobs', null),
                    new InputOption('retry-strategies', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'The allowed retry strategies.', ['constant', 'exponential', 'linear', 'live', 'never_retry', 'time_fixed']),
                    new InputOption('time-units', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'The allowed time units.', StrategyInterface::TIME_UNITS),
                ])
            );
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return bool
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $howManyJobs     = (int) $input->getArgument('how-many-jobs');
        $batch           = $input->getOption('batch');
        $retryStrategies = $input->getOption('retry-strategies');
        $timeUnits       = $input->getOption('time-units');
        $noFutureJobs    = $input->getOption('no-future-jobs');

        $this->getIoWriter()->title('SerendipityHQ Queue Bundle Daemon');
        $this->getIoWriter()->info(\Safe\sprintf('Starting generating %s random jobs...', $howManyJobs));

        // Generate the random jobs
        $progress = ProgressBar::createProgressBar(ProgressBar::FORMAT_CREATE_JOBS, $output, $howManyJobs);
        $progress->start();

        $progress->setRedrawFrequency($batch);

        $jobs = [];
        for ($i = 0; $i < $howManyJobs; ++$i) {
            // First: we create a Job to push to the queue
            $arguments    = '--id=' . ($i + 1);
            $scheduledJob = new Job('queues:test:fake', $arguments);

            // Set a random queue
            $queue = rand(0, count($this->queues) - 1);
            $scheduledJob->setQueue($this->queues[$queue]);

            // Set a random retry strategy
            if (0 < (is_array($retryStrategies) || $retryStrategies instanceof Countable ? count($retryStrategies) : 0)) {
                $scheduledJob->setRetryStrategy($this->getRandomRetryStrategy($retryStrategies, $timeUnits));
            }

            // Decide if this will be executed in the future
            if (false === $noFutureJobs) {
                $condition = rand(0, 10);
                if (7 <= $condition) {
                    $days   = rand(1, 10);
                    $future = new DateTime();
                    $future->modify('+' . $days . ' day');
                    $scheduledJob->setExecuteAfterTime($future);
                }
            }

            // Decide if this has a dependency on another job
            $condition = rand(0, 10);
            // Be sure there is at least one already created Job!!!
            if (7 <= $condition && 0 < count($jobs)) {
                // Decide how many dependencies it has
                $howManyDeps = rand(1, count($jobs) - 1);

                for ($ii = 0; $ii <= $howManyDeps; ++$ii) {
                    $parentJob = rand(0, count($jobs) - 1);
                    $scheduledJob->addParentDependency($jobs[$parentJob]);
                }
            }

            $this->getContainer()->get('doctrine')->getManager()->persist($scheduledJob);
            $jobs[] = $scheduledJob;

            if (0 === $i % $input->getOption('batch')) {
                $this->getContainer()->get('doctrine')->getManager()->flush();
                $jobs = [];
                $this->getContainer()->get('doctrine')->getManager()->clear();
            }

            $progress->advance();
        }

        $this->getContainer()->get('doctrine')->getManager()->flush();
        $progress->finish();

        $this->getIoWriter()->write("\n\n");
        $this->getIoWriter()->success(\Safe\sprintf('All done: %s random jobs generated!', $howManyJobs));

        return 0;
    }

    /**
     * @param array $strategies
     * @param array $timeUnits
     *
     * @return StrategyInterface
     */
    private function getRandomRetryStrategy(array $strategies, array $timeUnits): StrategyInterface
    {
        // Pick a random strategy
        $strategy    = $strategies[rand(0, count($strategies) - 1)];
        $maxAttempts = rand(1, 3);
        $incrementBy = rand(1, 10);
        $timeUnit    = $this->getRandomTimeUnit($timeUnits);

        switch ($strategy) {
            case 'constant':
                return new ConstantStrategy($maxAttempts, $incrementBy, $timeUnit);
                break;
            case 'exponential':
                $exponentialBase = rand(2, 5);

                return new ExponentialStrategy($maxAttempts, $incrementBy, $timeUnit, $exponentialBase);
                break;
            case 'linear':
                return new LinearStrategy($maxAttempts, $incrementBy, $timeUnit);
                break;
            case 'live':
                return new LiveStrategy($maxAttempts);
                break;
            case 'time_fixed':
                // Sum $maxAttempts and $incrementBy to be sure the time window is sufficiently large
                return new TimeFixedStrategy($maxAttempts, $maxAttempts + $incrementBy, $timeUnit);
                break;
            case 'never_retry':
            default:
                return new NeverRetryStrategy();
                break;
        }
    }

    /**
     * @param array $timeUnits
     *
     * @return string
     */
    private function getRandomTimeUnit(array $timeUnits): string
    {
        return $timeUnits[rand(0, count($timeUnits) - 1)];
    }
}
