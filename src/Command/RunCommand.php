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

use Doctrine\ORM\EntityManager;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Daemon;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Service\QueuesDaemon;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Util\JobsMarker;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Util\Profiler;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Util\ProgressBar;
use SerendipityHQ\Bundle\ConsoleStyles\Console\Formatter\SerendipityHQOutputFormatter;
use SerendipityHQ\Bundle\ConsoleStyles\Console\Style\SerendipityHQStyle;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs the daemon that listens for new Joobs to process.
 */
class RunCommand extends Command
{
    /**
     * @var string
     */
    const NAME = 'queues:run';

    /** @var QueuesDaemon $daemon */
    private $daemon;

    /** @var EntityManager $entityManager */
    private $entityManager;

    /** @var JobsMarker $jobsMarker */
    private $jobsMarker;

    /** @var SerendipityHQStyle $ioWriter */
    private $ioWriter;

    /** @var OutputInterface $output */
    private $output;

    /**
     * @param QueuesDaemon  $daemon
     * @param EntityManager $entityManager
     * @param JobsMarker    $jobsMarker
     */
    public function __construct(QueuesDaemon $daemon, EntityManager $entityManager, JobsMarker $jobsMarker)
    {
        $this->daemon        = $daemon;
        $this->entityManager = $entityManager;
        $this->jobsMarker    = $jobsMarker;

        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this
            ->setName(self::NAME)
            ->setDescription('Start the daemon to continuously process the queue.')
            ->setDefinition(
                new InputDefinition([
                    new InputArgument('daemon', InputArgument::OPTIONAL, 'The Daemon to run.'),
                    new InputOption('enable-memprof', null),
                    new InputOption('allow-prod', null, InputOption::VALUE_NONE, 'Runs the commands in the queue passing the --env=prod flag. If not set, is passed the flag --env=dev.'),
                ])
            );
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int The status code of the command
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->output = $output;

        // Create the Input/Output writer
        $this->ioWriter = new SerendipityHQStyle($input, $output);
        $this->ioWriter->setFormatter(new SerendipityHQOutputFormatter(true));

        $this->jobsMarker->setIoWriter($this->ioWriter);

        // Do the initializing operations
        $this->daemon->initialize($input->getArgument('daemon'), $input->getOption('allow-prod'), $this->ioWriter, $output);

        // Check that the Daemons in the database that are still running are really still running
        $this->checkAliveDaemons();
        $this->daemon->getProfiler()->profile();
        $this->daemon->getProfiler()->printProfilingInfo();

        $this->ioWriter->success('Waiting for new ScheduledJobs to process...');
        $this->ioWriter->commentLineNoBg('To quit the Queues Daemon use CONTROL-C.');

        // Run the Daemon
        while ($this->daemon->isAlive()) {
            $printUow = false;
            // First process Jobs already running in each queue
            foreach ($this->daemon->getConfig()->getQueues() as $queueName) {
                if ($this->daemon->hasToCheckRunningJobs($queueName)) {
                    $this->processRunningJobs($queueName);
                    $printUow = true;
                }
            }

            // Then initialize new Jobs for each queue if possible
            foreach ($this->daemon->getConfig()->getQueues() as $queueName) {
                $jobsToLoad = $this->daemon->getJobsToLoad($queueName);
                if (0 < $jobsToLoad) {
                    if ($this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                        $this->ioWriter->infoLineNoBg(\Safe\sprintf('Trying to initialize <success-nobg>%s</success-nobg> new Jobs for queue <success-nobg>%s</success-nobg>...', $jobsToLoad, $queueName));
                        $initializingJobs = ProgressBar::createProgressBar(ProgressBar::FORMAT_INITIALIZING_JOBS, $output, $jobsToLoad);
                    }
                    for ($i = 0; $i < $jobsToLoad; ++$i) {
                        // Start processing the next Job in the queue
                        if (false === $this->daemon->processNextJob($queueName)) {
                            if ($this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                                $this->ioWriter->infoLineNoBg(\Safe\sprintf('Queue <success-nobg>%s</success-nobg> is empty: no more Jobs to initialize.', $queueName));
                            }
                            // The next Job is null: exit this queue and pass to the next one
                            break;
                        }

                        if (isset($initializingJobs)) {
                            $initializingJobs->advance();
                            $this->ioWriter->writeln('');
                        }
                    }

                    $printUow = true;
                }
            }

            // Check alive daemons
            if ($this->daemon->hasToCheckAliveDaemons()) {
                if ($this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                    $this->ioWriter->infoLineNoBg('Checking alive Daemons...');
                }
                $this->checkAliveDaemons();
            }

            // Free some memory
            if ($this->daemon->hasToOptimize()) {
                if ($this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                    $this->ioWriter->info('Optimizing the Daemons...');
                }
                $this->daemon->optimize();
            }

            // Print profiling info
            if ($this->daemon->hasToPrintProfilingInfo()) {
                $this->daemon->getProfiler()->profile();
                $this->daemon->getProfiler()->printProfilingInfo();
            }

            if ($printUow) {
                Profiler::printUnitOfWork('RunCommand');
            }

            // If the daemon can sleep, make it sleep
            if ($this->daemon->canSleep()) {
                if ($this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                    $this->ioWriter->infoLineNoBg(\Safe\sprintf(
                        'No Jobs to process. Idling for <success-nobg>%s seconds<success-nobg>...', $this->daemon->getConfig()->getIdleTime()
                    ));
                }
                $this->daemon->sleep();
            }
        }

        $this->ioWriter->note('Entering shutdown sequence.');

        // Wait for the currently running jobs to finish
        if ($this->daemon->hasRunningJobs()) {
            $this->ioWriter->infoLineNoBg('Emptying the queue of still running Jobs...');
        }
        while ($this->daemon->hasRunningJobs()) {
            foreach ($this->daemon->getConfig()->getQueues() as $queueName) {
                if ($this->daemon->hasRunningJobs($queueName)) {
                    $this->processRunningJobs($queueName);
                }
            }

            // And wait a bit to give them the time to finish
            $this->daemon->wait();
        }

        // Set the daemon as died
        $this->daemon->requiescantInPace();

        $this->ioWriter->success('All done: Queue Daemon ended running. No more ScheduledJobs will be processed.');

        return 0;
    }

    /**
     * Checks that the Damons in the database without a didedOn date are still alive (running).
     */
    private function checkAliveDaemons(): void
    {
        if ($this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $this->ioWriter->infoLineNoBg('Checking struggler Daemons...');
            $this->ioWriter->commentLineNoBg('Daemons are "struggler" if they are not running anymore.');
        }

        /** @var Daemon $daemon */
        $strugglers = [];
        while (null !== $daemon = $this->entityManager->getRepository('SHQCommandsQueuesBundle:Daemon')
                ->findNextAlive($this->daemon->getIdentity())) {
            if (false === $this->isDaemonStillRunning($daemon)) {
                $daemon->requiescatInPace(Daemon::MORTIS_STRAGGLER);
                $this->entityManager->flush();
                $this->entityManager->detach($daemon);
                $strugglers[] = $daemon;
            }
        }

        if (0 >= count($strugglers) && $this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $this->ioWriter->infoLineNoBg('No Struggler Daemons found.');

            return;
        }

        $this->ioWriter->infoLineNoBg(\Safe\sprintf('Found <success-nobg>%s</success-nobg> struggler Daemon(s).', count($strugglers)));
        $this->ioWriter->commentLineNoBg('Their "diedOn" date is set to NOW and mortisCausa is "struggler".');

        $table = [];
        /** @var Daemon $strugglerDaemon */
        foreach ($strugglers as $strugglerDaemon) {
            $age     = $strugglerDaemon->getDiedOn()->diff($strugglerDaemon->getBornOn());
            $table[] = [
                \Safe\sprintf('<%s>%s</>', 'success-nobg', "\xE2\x9C\x94"),
                $strugglerDaemon->getPid(),
                $strugglerDaemon->getHost(),
                $strugglerDaemon->getBornOn()->format('Y-m-d H:i:s'),
                $strugglerDaemon->getDiedOn()->format('Y-m-d H:i:s'),
                $age->format('%h hours, %i minutes and %s seconds.'),
                $strugglerDaemon->getMortisCausa(),
            ];
        }
        $this->ioWriter->table(
            ['', 'PID', 'Host', 'Born on', 'Died On', 'Age', 'Mortis Causa'],
            $table
        );

        $this->daemon->getProfiler()->aliveDaemonsJustCheked();
    }

    /**
     * Checks a Daemon is still running checking its process still exists.
     *
     * @param Daemon $daemon
     *
     * @return bool
     */
    private function isDaemonStillRunning(Daemon $daemon): bool
    {
        // Get the running processes with the Daemon's PID
        exec(\Safe\sprintf('ps -ef | grep %s', $daemon->getPid()), $lines);

        // Search the line with this command name: this indicates the process is still running
        foreach ($lines as $line) {
            if (false !== strpos($line, $this->getName())) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $queueName
     */
    private function processRunningJobs(string $queueName): void
    {
        $currentlyRunningProgress = null;
        if ($this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
            $this->ioWriter->infoLineNoBg(\Safe\sprintf(
                'Checking <success-nobg>%s</success-nobg> running jobs on queue "%s"...',
                $this->daemon->countRunningJobs($queueName), $queueName
            ));
            $currentlyRunningProgress = ProgressBar::createProgressBar(ProgressBar::FORMAT_PROCESS_RUNNING_JOBS, $this->output, $this->daemon->countRunningJobs($queueName));
        }
        $this->daemon->checkRunningJobs($queueName, $currentlyRunningProgress);
    }
}
