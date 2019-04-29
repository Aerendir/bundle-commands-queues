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

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Command;

use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Safe\Exceptions\ArrayException;
use Safe\Exceptions\StringsException;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Util\JobsMarker;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Util\ProgressBarFactory;
use SerendipityHQ\Component\ThenWhen\Strategy\ConstantStrategy;
use SerendipityHQ\Component\ThenWhen\Strategy\ExponentialStrategy;
use SerendipityHQ\Component\ThenWhen\Strategy\LinearStrategy;
use SerendipityHQ\Component\ThenWhen\Strategy\LiveStrategy;
use SerendipityHQ\Component\ThenWhen\Strategy\NeverRetryStrategy;
use SerendipityHQ\Component\ThenWhen\Strategy\StrategyInterface;
use SerendipityHQ\Component\ThenWhen\Strategy\TimeFixedStrategy;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Creates random Jobs.
 */
class TestRandomJobsCommand extends AbstractQueuesCommand
{
    /** @var string $defaultName */
    protected static $defaultName = 'queues:test:random-jobs';

    /** @var array $queues */
    private $queues;

    /**
     * @param EntityManagerInterface $doNotUseEntityManager
     * @param JobsMarker             $doNotUseJobsMarker
     */
    public function __construct(EntityManagerInterface $doNotUseEntityManager, JobsMarker $doNotUseJobsMarker)
    {
        parent::__construct($doNotUseEntityManager, $doNotUseJobsMarker);
        $this->queues = [
            'queue_1', 'queue_2', 'queue_3', 'queue_4', 'queue_5',
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this
            ->setDescription('[INTERNAL] Generates random Jobs to test SHQCommandsQueuesBundle.')
            ->addArgument('how-many-jobs', InputArgument::OPTIONAL, 'How many random Jobs would you like to create?', '10')
            ->addOption('batch', null, InputOption::VALUE_OPTIONAL, 'The number of Jobs in a batch.', '10')
            ->addOption('no-future-jobs', null, InputOption::VALUE_NONE)
            ->addOption('retry-strategies', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'The allowed retry strategies.', ['constant', 'exponential', 'linear', 'live', 'never_retry', 'time_fixed'])
            ->addOption('time-units', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'The allowed time units.', StrategyInterface::TIME_UNITS);
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @throws ArrayException
     * @throws StringsException
     * @throws Exception
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $howManyJobs  = $input->getArgument('how-many-jobs');
        $batch        = $input->getOption('batch');
        $noFutureJobs = $input->getOption('no-future-jobs');

        /** @var array $retryStrategies */
        $retryStrategies = $input->getOption('retry-strategies');

        /** @var array $timeUnits */
        $timeUnits = $input->getOption('time-units');

        if (null !== $howManyJobs && false === is_numeric($howManyJobs)) {
            $this->getIoWriter()->error('The number of jobs has to be a numeric value.');

            return 1;
        }
        $howManyJobs = (int) $howManyJobs;

        if (null !== $batch && false === is_numeric($batch)) {
            $this->getIoWriter()->error('--batch accepts only numeric values.');

            return 1;
        }
        $batch = (int) $batch;

        if (null !== $noFutureJobs && false === is_bool($noFutureJobs)) {
            $this->getIoWriter()->error("--no-future-jobs doesn't accept any value.");

            return 1;
        }

        $this->getIoWriter()->title('SerendipityHQ Queue Bundle Daemon');
        $this->getIoWriter()->info(\Safe\sprintf('Starting generating %s random jobs...', $howManyJobs));

        // Generate the random jobs
        $progress = ProgressBarFactory::createProgressBar(ProgressBarFactory::FORMAT_CREATE_JOBS, $output, $howManyJobs);
        $progress->start();

        $progress->setRedrawFrequency($batch);

        $jobs = [];
        for ($i = 0; $i < $howManyJobs; ++$i) {
            // First: we create a Job to push to the queue
            $arguments    = '--id=' . ($i + 1);
            $scheduledJob = new Job(TestFakeCommand::$defaultName, $arguments);

            // Set a random queue
            $queue = random_int(0, count($this->queues) - 1);
            $scheduledJob->setQueue($this->queues[$queue]);

            // Set a random retry strategy
            if (0 < count($retryStrategies)) {
                $scheduledJob->setRetryStrategy($this->getRandomRetryStrategy($retryStrategies, $timeUnits));
            }

            // Decide if this will be executed in the future
            if (false === $noFutureJobs) {
                $condition = random_int(0, 10);
                if (7 <= $condition) {
                    $days   = random_int(1, 10);
                    $future = new DateTime();
                    $future->modify('+' . $days . ' day');
                    $scheduledJob->setExecuteAfterTime($future);
                }
            }

            // Decide if this has a dependency on another job
            $condition = random_int(0, 10);
            // Be sure there is at least one already created Job!!!
            if (7 <= $condition && 1 < count($jobs)) {
                // Decide how many dependencies it has
                $howManyDeps = random_int(1, count($jobs) - 1);

                for ($ii = 0; $ii <= $howManyDeps; ++$ii) {
                    $parentJob = random_int(0, count($jobs) - 1);
                    $scheduledJob->addParentDependency($jobs[$parentJob]);
                }
            }

            $this->getEntityManager()->persist($scheduledJob);
            $jobs[] = $scheduledJob;

            if (0 === $i % $batch) {
                $this->getEntityManager()->flush();
                $jobs = [];
                $this->getEntityManager()->clear();
            }

            $progress->advance();
        }

        $this->getEntityManager()->flush();
        $progress->finish();

        $this->getIoWriter()->write("\n\n");
        $this->getIoWriter()->success(\Safe\sprintf('All done: %s random jobs generated!', $howManyJobs));

        return 0;
    }

    /**
     * @param array $strategies
     * @param array $timeUnits
     *
     * @throws Exception
     *
     * @return StrategyInterface
     */
    private function getRandomRetryStrategy(array $strategies, array $timeUnits): StrategyInterface
    {
        // Pick a random strategy
        $strategy    = $strategies[random_int(0, count($strategies) - 1)];
        $maxAttempts = random_int(1, 3);
        $incrementBy = random_int(1, 10);
        $timeUnit    = $this->getRandomTimeUnit($timeUnits);

        switch ($strategy) {
            case 'constant':
                return new ConstantStrategy($maxAttempts, $incrementBy, $timeUnit);
                break;
            case 'exponential':
                $exponentialBase = random_int(2, 5);

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
     * @throws Exception
     *
     * @return string
     */
    private function getRandomTimeUnit(array $timeUnits): string
    {
        return $timeUnits[random_int(0, count($timeUnits) - 1)];
    }
}
