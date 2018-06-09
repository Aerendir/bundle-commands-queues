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

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Service;

use Doctrine\ORM\EntityManager;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Config\DaemonConfig;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Daemon;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Repository\JobRepository;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Util\JobsMarker;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Util\Profiler;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Util\ProgressBar;
use SerendipityHQ\Bundle\ConsoleStyles\Console\Style\SerendipityHQStyle;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * The Daemon that listens for new jobs to process.
 */
class QueuesDaemon
{
    /** @var Daemon $me Is the Daemon object */
    private $me;

    /** @var array $canSleep */
    private $canSleep = [];

    /** @var array $config */
    private $config;

    /** @var EntityManager $entityManager */
    private $entityManager;

    /** @var JobsManager $jobsManager */
    private $jobsManager;

    /** @var JobsMarker $processMarker Used to change the status of Jobs during their execution */
    private $jobsMarker;

    /** @var JobRepository $jobsRepo */
    private $jobsRepo;

    /** @var SerendipityHQStyle $ioWriter */
    private $ioWriter;

    /**
     * @var bool If this is true, mustRun returns false and the Daemon dies.
     *           This will be true when a PCNTL SIGTERM signal is intercepted or when the max runtime execution
     *           is reached.
     */
    private $mustDie;

    /** @var bool $pcntlLoaded */
    private $pcntlLoaded;

    /** @var Profiler $profiler */
    private $profiler;

    /** @var array $runningJobs Keeps track of the started jobs in the queue. */
    private $runningJobs = [];

    /** @var bool $verbosity */
    private $verbosity;

    /**
     * @param DaemonConfig  $config
     * @param EntityManager $entityManager
     * @param JobsManager   $jobsManager
     * @param JobsMarker    $jobsMarker
     * @param Profiler      $profiler
     */
    public function __construct(DaemonConfig $config, EntityManager $entityManager, JobsManager $jobsManager, JobsMarker $jobsMarker, Profiler $profiler)
    {
        $this->config        = $config;
        $this->entityManager = $entityManager;
        $this->jobsManager   = $jobsManager;
        $this->jobsMarker    = $jobsMarker;
        $this->jobsRepo      = $this->entityManager->getRepository('SHQCommandsQueuesBundle:Job');
        $this->profiler      = $profiler;
    }

    /**
     * Initializes the Daemon.
     *
     * @param                    $daemon
     * @param SerendipityHQStyle $ioWriter
     * @param OutputInterface    $output
     *
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    public function initialize($daemon, SerendipityHQStyle $ioWriter, OutputInterface $output)
    {
        $this->ioWriter  = $ioWriter;
        $this->verbosity = $this->ioWriter->getVerbosity();

        $this->ioWriter->title('SerendipityHQ Queue Bundle Daemon');
        $ioWriter->infoLineNoBg('Starting the Daemon...');

        // Initialize the configuration
        $this->config->initialize($daemon);

        // Save the Daemon to the Database
        $this->me = new Daemon(gethostname(), getmypid(), $this->config);
        $this->entityManager->persist($this->me);
        $this->entityManager->flush($this->me);
        $ioWriter->successLineNoBg(sprintf(
            'I\'m Daemon "%s@%s" (ID: %s).', $this->me->getPid(), $this->me->getHost(), $this->me->getId())
        );

        // First of all setup Memprof if required
        $this->setupMemprof();

        // Start the profiler
        Profiler::setDependencies($this->ioWriter, $this->entityManager->getUnitOfWork());
        $this->profiler->start(
            $this->getIdentity()->getPid(), $this->config->getMaxRuntime(), $this->getConfig()->getQueues()
        );

        // Initialize the JobsManager
        $this->jobsManager->initialize($this->entityManager, $ioWriter);

        // Disable logging in Doctrine
        $this->entityManager->getConfiguration()->setSQLLogger(null);
        $this->entityManager->getConfiguration()->setSecondLevelCacheEnabled(false);

        // Configure the repository
        $this->jobsRepo->configure($this->config->getRepoConfig(), $this->ioWriter);

        // Force garbage collection (used by JobsMarker::markJobAsClosed()
        gc_enable();

        // Setup pcntl signals so it is possible to manage process
        $this->setupPcntlSignals();

        // Check for Jobs started by a previous Daemon
        $this->checkStaleJobs($output);
    }

    /**
     * Sets the Daemon as died.
     *
     * Requiescant In Pace (May it Rest In Pace).
     *
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    public function requiescantInPace()
    {
        $this->me->requiescatInPace();
        $this->entityManager->persist($this->me);
        $this->entityManager->flush($this->me);
    }

    /**
     * Whil this returns true, the Daemon will continue to run.
     *
     * This service is not meant to be retrieved outside of RunCommand.
     *
     * @return bool
     */
    public function isAlive(): bool
    {
        // Increment the iterations counter
        $this->profiler->hitIteration();

        if (true === $this->pcntlLoaded) {
            pcntl_signal_dispatch();
        }

        // This is true if a SIGTERM or a SIGINT signal is received
        if (true === $this->mustDie) {
            return false;
        }

        // The max_runtime is reached
        if ($this->profiler->isMaxRuntimeReached()) {
            if ($this->verbosity >= OutputInterface::VERBOSITY_NORMAL) {
                $this->ioWriter->warning(
                    sprintf('Max runtime of <success-nobg>%s</success-nobg> seconds reached.', $this->config->getMaxRuntime())
                );
            }

            return false;
        }

        return true;
    }

    /**
     * Processes the next Job in the queue.
     *
     * @param string $queueName
     *
     * @return bool|void
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    public function processNextJob(string $queueName)
    {
        // If the max_concurrent_jobs number is reached, don't process one more job
        if ($this->countRunningJobs($queueName) >= $this->config->getQueue($queueName)['max_concurrent_jobs']) {
            return;
        }

        // Get the next job to process
        $job = $this->jobsRepo->findNextRunnableJob($queueName);

        // If no more jobs exists in the queue
        if (null === $job) {
            // This queue has no more Jobs: for it the Daemon can sleep
            $this->canSleep[$queueName] = true;

            return;
        }

        // This queue has another Job: for it the Daemon can't sleep as the next cycle is required to check if there are other Jobs
        $this->canSleep[$queueName] = false;

        // Start processing the Job
        $now  = new \DateTime();
        $info = [
            'started_at' => $now,
        ];
        if ($this->verbosity >= OutputInterface::VERBOSITY_NORMAL) {
            $this->ioWriter->infoLineNoBg(sprintf(
                '[%s] Job <success-nobg>#%s</success-nobg> on Queue <success-nobg>%s</success-nobg>: Initializing the process.',
                $now->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue()
            ));
        }

        if ($job->isTypeCancelling()) {
            if ($this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                $this->ioWriter->warningLineNoBg(sprintf(
                    '[%s] Job <success-nobg>#%s</success-nobg> on Queue <success-nobg>%s</success-nobg>: This will mark as CANCELLED childs of #%s.',
                    $now->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue(), $job->getArguments()[0]
                ));
            }

            // Add the ID of the cancelling Job to the command to execute
            $job->addArgument(sprintf('--cancelling-job-id=%s', $job->getId()));
        }

        if ($job->isTypeRetrying() && $this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $this->ioWriter->noteLineNoBg(sprintf(
                '[%s] Job <success-nobg>#%s</success-nobg> on Queue <success-nobg>%s</success-nobg>: This is a retry of original Job <success-nobg>#%s</success-nobg> (Childs: %s).',
                $now->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue(), $job->getRetryOf()->getId(), $job->getChildDependencies()->count()
            ));
        }

        // Create the process for the scheduled job
        $process = $this->jobsManager->createJobProcess($job);

        // Try to start the process
        try {
            $process->start();
        } catch (\Throwable $e) {
            // Something went wrong starting the process: close it as failed
            $info['output']       = 'Failing start the process.';
            $info['output_error'] = $e;

            // Check if it can be retried and if the retry were successful
            if ($job->getRetryStrategy()->canRetry() && true === $this->retryFailedJob($job, $info, 'Job didn\'t started as its process were aborted.')) {
                // Exit
                return;
            }

            $cancellingJob = $this->handleChildsOfFailedJob($job);
            $this->jobsMarker->markJobAsAborted($job, $info, $this->me);
            if ($this->verbosity >= OutputInterface::VERBOSITY_NORMAL) {
                $this->ioWriter->errorLineNoBg(sprintf(
                    '[%s] Job <success-nobg>#%s</success-nobg> on Queue <success-nobg>%s</success-nobg>: The process didn\'t started due to some errors. See them in the'
                    . ' logs of the Job.', $now->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue()
                ));
                if (false !== $cancellingJob) {
                    $this->ioWriter->errorLineNoBg(sprintf('The Job #%s will mark its childs as cancelled.', $cancellingJob->getId()));
                }
            }

            return;
        }

        /*
         * Mark the Job as pending.
         *
         * When we mark it as Running we want its PID printed in the Console and saved to the database.
         * If we mark now the Job as Running, on a very busy server may happen that at this point the real process isn't
         * already started and so the Job hasn't already a PID.
         * Marking it as pending now allows us to save its PID in the database later, when we will process again this
         * Job as a running one.
         *
         * At that time, if it recognized as a running job, its process is really started and itt will have a PID for
         * sure.
         */
        $this->jobsMarker->markJobAsPending($job, $info, $this->me);

        // Now add the process to the runningJobs list to keep track of it later
        $info['job']     = $job;
        $info['process'] = $process;
        // This info is already in the Job itself, so we don't need it anymore
        unset($info['started_at']);

        // Save the just created new Job into the running jobs queue
        $this->runningJobs[$queueName][] = $info;

        // Wait some millisedonds to permit Doctrine to finish writings (sometimes it is slower than the Daemon)
        $this->wait();

        return true;
    }

    /**
     * @return bool
     */
    public function canSleep(): bool
    {
        foreach ($this->canSleep as $can) {
            if (false === $can) {
                return false;
            }
        }

        if ($this->hasRunningJobs()) {
            return false;
        }

        return true;
    }

    /**
     * @return bool
     */
    public function hasToCheckAliveDaemons(): bool
    {
        return microtime(true) - $this->getProfiler()->getAliveDaemonsLastCheckedAt() >= $this->getConfig()->getAliveDaemonsCheckInterval();
    }

    /**
     * @param string $queueName
     *
     * @return bool
     */
    public function hasToCheckRunningJobs(string $queueName): bool
    {
        // The number of iterations is reached and the queue has currently running Jobs
        return microtime(true) - $this->getProfiler()->getRunningJobsLastCheckedAt($queueName) >= $this->getConfig()->getRunningJobsCheckInterval($queueName)
            && $this->hasRunningJobs($queueName);
    }

    /**
     * @param string $queueName
     *
     * @return bool
     */
    public function hasRunningJobs(string $queueName = null): bool
    {
        return $this->countRunningJobs($queueName) > 0 ? true : false;
    }

    /**
     * Returns the number of currently running Jobs.
     *
     * @param string $queueName
     *
     * @return int
     */
    public function countRunningJobs(string $queueName = null): int
    {
        // If the queue name is not passed, the count is on all the processing queues
        if (null === $queueName) {
            $runningJobs = 0;
            foreach ($this->runningJobs as $currentlyRunning) {
                $runningJobs += count($currentlyRunning);
            }

            // Return the overall amount
            return $runningJobs;
        }

        return isset($this->runningJobs[$queueName]) ? count($this->runningJobs[$queueName]) : 0;
    }

    /**
     * Processes the Jobs already running or pending.
     *
     * @param string                                             $queueName
     * @param \Symfony\Component\Console\Helper\ProgressBar|null $progressBar
     */
    public function checkRunningJobs(string $queueName, \Symfony\Component\Console\Helper\ProgressBar $progressBar = null)
    {
        foreach ($this->runningJobs[$queueName] as $index => $runningJob) {
            $now = new \DateTime();

            /** @var Job $checkingJob */
            $checkingJob = $runningJob['job'];
            /** @var Process $checkingProcess */
            $checkingProcess = $runningJob['process'];

            if (null !== $progressBar) {
                $progressBar->advance();
                $this->ioWriter->writeln('');
            }

            if ($this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                $this->ioWriter->infoLineNoBg(sprintf(
                    '[%s] Job <success-nobg>#%s</success-nobg> on Queue <success-nobg>%s</success-nobg>: Checking status...',
                    $now->format('Y-m-d H:i:s'), $checkingJob->getId(), $checkingJob->getQueue(), $checkingProcess->getPid()
                    ));
            }

            // If the running job is processed correctly...
            if (true === $this->processRunningJob($runningJob)) {
                // ... Unset it from the running jobs queue
                unset($this->runningJobs[$queueName][$index]);
            }
        }
    }

    /**
     * @param array $runningJob
     *
     * @return bool
     */
    public function processRunningJob(array $runningJob)
    {
        $now = new \DateTime();

        /** @var Job $job */
        $job = $runningJob['job'];

        /** @var Process $process */
        $process = $runningJob['process'];

        //  If it is not already terminated...
        if (false === $process->isTerminated()) {
            // ... and it is still pending but its process were effectively started
            if (Job::STATUS_PENDING === $job->getStatus() && $process->isStarted()) {
                // Mark it as running (those checks will avoid an unuseful query to the DB)
                $this->jobsMarker->markJobAsRunning($job);
            }

            // And print its PID (available only if the process is already running)
            if ($process->isStarted() && $this->verbosity >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                $this->ioWriter->infoLineNoBg(sprintf(
                        '[%s] Job <success-nobg>#%s</success-nobg> on Queue <success-nobg>%s</success-nobg>: Process is currently running with PID "%s".',
                        $now->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue(), $process->getPid())
                );
            }

            // ... don't continue to process it as we have to wait it will terminate
            return false;
        }

        $processHandler = 'handleSuccessfulJob';
        if (false === $process->isSuccessful()) {
            $processHandler = 'handleFailedJob';
        }

        // Handle a successful or a failed job
        $this->{$processHandler}($job, $process);

        // If this is a cancelling Job or a retrying one, refresh the entire tree
        if ($job->isTypeCancelling()) {
            $startJobId = str_replace('--id=', '', $job->getArguments()[0]);
            $this->jobsManager->refreshTree($this->entityManager->getRepository('SHQCommandsQueuesBundle:Job')->findOneById($startJobId));
        }

        if ($job->isStatusFinished() && false === $job->isTypeCancelling()) {
            $this->jobsManager->refreshTree($job);
        }

        // Now detach the Job
        JobsManager::detach($job);

        $this->wait();

        return true;
    }

    /**
     * @return bool
     */
    public function hasToOptimize(): bool
    {
        if (false === isset($this->entityManager->getUnitOfWork()->getIdentityMap()[Job::class])) {
            return false;
        }

        return $this->getConfig()->getManagedEntitiesTreshold() < count($this->entityManager->getUnitOfWork()->getIdentityMap()[Job::class]);
    }

    /**
     * @return bool
     */
    public function hasToPrintProfilingInfo(): bool
    {
        return microtime(true) - $this->getProfiler()->getLastMicrotime() >= $this->getConfig()->getProfilingInfoInterval();
    }

    /**
     * Optimizes the usage of memory.
     */
    public function optimize()
    {
        $this->profiler->profile();

        // First try a soft detach
        foreach ($this->entityManager->getUnitOfWork()->getIdentityMap()[Job::class] as $job) {
            JobsManager::detach($job);
        }

        // Check if the optimization worked
        if ($this->hasToOptimize()) {
            if ($this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                $this->ioWriter->warning(sprintf('Trying to hard detach all Jobs to free more space.'));
                $this->ioWriter->infoLineNoBg('Emptying the queue of still running Jobs...');
            }
            // Wait for the currently running jobs to finish
            while ($this->hasRunningJobs()) {
                foreach ($this->getConfig()->getQueues() as $queueName) {
                    if ($this->hasRunningJobs($queueName)) {
                        $this->checkRunningJobs($queueName);
                    }
                }

                // And wait a bit to give them the time to finish
                $this->wait();
            }

            // Comepletely clear the entity manager
            $this->entityManager->clear();
        }

        // Force the garbage collection
        $cycles = gc_collect_cycles();
        if ($this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
            $this->ioWriter->infoLineNoBg(sprintf('Collected <success-nobg>%s</success-nobg> cycles.', $cycles));
        }

        $this->getProfiler()->optimized();

        $this->profiler->profile();
        $this->profiler->printProfilingInfo();

        $this->ioWriter->success(sprintf('Optimization completed.'));
    }

    /**
     * Put the Daemon in sleep.
     */
    public function sleep()
    {
        sleep($this->getConfig()->getIdleTime());
    }

    /**
     * This is required to permit to Doctrine to write to the Database.
     *
     * Without this method, it is possible that a process marked as running is not persisted before itself is marked as
     * closed.
     *
     * This method ensures a delay between writings on the database.
     */
    public function wait()
    {
        $waitTimeInMs = mt_rand(500, 1000);
        usleep($waitTimeInMs * 1E3);
    }

    /**
     * @return DaemonConfig
     */
    public function getConfig(): DaemonConfig
    {
        return $this->config;
    }

    /**
     * @return Daemon
     */
    public function getIdentity(): Daemon
    {
        return $this->me;
    }

    /**
     * @param string $queueName
     *
     * @return int
     */
    public function getJobsToLoad(string $queueName)
    {
        return $this->getConfig()->getQueue($queueName)['max_concurrent_jobs'] - $this->countRunningJobs($queueName);
    }

    /**
     * @return Profiler
     */
    public function getProfiler(): Profiler
    {
        return $this->profiler;
    }

    /**
     * @todo Those methods can be used to better define the failure cause:
     *
     * - $process->getIdleTimeout()
     * - $process->getStopSignal()
     * - $process->getTermSignal()
     * - $process->getTimeout()
     * - $process->hasBeenSignaled()
     * - $process->hasBeenStopped()
     *
     * @param Job     $job
     * @param Process $process
     *
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    final protected function handleFailedJob(Job $job, Process $process)
    {
        $info = $this->jobsManager->buildDefaultInfo($process);

        // Check if it can be retried
        if ($job->getRetryStrategy()->canRetry() && true === $this->retryFailedJob($job, $info, 'Job failed but can be retried.')) {
            // Exit
            return;
        }

        $cancellingJob = $this->handleChildsOfFailedJob($job);

        $this->jobsMarker->markJobAsFailed($job, $info);

        $message = sprintf(
            '[%s] Job <success-nobg>#%s</success-nobg> on Queue <success-nobg>%s</success-nobg>: Job failed (no retries allowed).',
            $job->getClosedAt()->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue()
        );

        if (false !== $cancellingJob) {
            $message = sprintf('%s The Job #%s will mark its childs as cancelled.', $message, $cancellingJob->getId());
        }

        $this->ioWriter->errorLineNoBg($message);
    }

    /**
     * @param Job     $job
     * @param Process $process
     */
    final protected function handleSuccessfulJob(Job $job, Process $process)
    {
        $info = $this->jobsManager->buildDefaultInfo($process);
        $this->jobsMarker->markJobAsFinished($job, $info);

        $this->ioWriter->successLineNoBg(sprintf(
            '[%s] Job <success-nobg>#%s</success-nobg> on Queue <success-nobg>%s</success-nobg>: Process succeded.',
            $job->getClosedAt()->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue()));
    }

    /**
     * @param Job $job
     *
     * @return bool|Job Returns false or the Job object
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    final protected function handleChildsOfFailedJob(Job $job)
    {
        if ($job->getChildDependencies()->count() > 0) {
            if ($this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                $this->ioWriter->noteLineNoBg(sprintf(
                    '[%s] Job <success-nobg>#%s</success-nobg> on Queue <success-nobg>%s</success-nobg>: Creating a Job to mark childs as as CANCELLED.',
                    (new \DateTime())->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue()
                ));
            }

            $cancelChildsJobs = $job->createCancelChildsJob();
            $this->entityManager->persist($cancelChildsJobs);

            // Flush it immediately to start the process as soon as possible
            $this->entityManager->flush($cancelChildsJobs);

            return $cancelChildsJobs;
        }

        if ($this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $this->ioWriter->noteLineNoBg(sprintf(
                '[%s] Job <success-nobg>#%s</success-nobg> on Queue <success-nobg>%s</success-nobg>: No childs found. The Job to mark childs as CANCELLED will not be created.',
                (new \DateTime())->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue()
            ));
        }

        return false;
    }

    /**
     * Enables Memprof if required.
     */
    private function setupMemprof()
    {
        // Intialize php-memprof
        if (true === $this->ioWriter->getInput()->getOption('enable-memprof')) {
            if (false === $this->profiler->enableMemprof()) {
                $this->ioWriter->errorLineNoBg('MEMPROF extension is not loaded: --enable-memprof will be ignored.');
            }

            $this->ioWriter->successLineNoBg('MEMPROF is available: memory profiling is enabled.');
        }
    }

    /**
     * Sets the PCNTL signals handlers.
     */
    private function setupPcntlSignals()
    {
        // The callback to use as signal handler
        $signalHandler = function ($signo) {
            switch ($signo) {
                case SIGTERM:
                    $signal        = 'SIGTERM';
                    $this->mustDie = true;
                    break;
                case SIGINT:
                    $signal        = 'SIGINT';
                    $this->mustDie = true;
                    break;
                default:
                    $signal = 'Unknown ' . $signo;
            }

            if ($this->verbosity >= OutputInterface::VERBOSITY_NORMAL) {
                $this->ioWriter->warning(sprintf('<success-nobg>%s</success-nobg> signal received.', $signal));
            }
        };

        $this->pcntlLoaded = extension_loaded('pcntl');

        // If the PCNTL extension is not loded ...
        if (false === $this->pcntlLoaded) {
            if ($this->verbosity >= OutputInterface::VERBOSITY_NORMAL) {
                $this->ioWriter->note('PCNTL extension is not loaded. Signals cannot be processd.');
            }

            return;
        }

        // PCNTL Signals are available: configure them
        pcntl_signal(SIGTERM, $signalHandler);
        pcntl_signal(SIGINT, $signalHandler);

        if ($this->verbosity >= OutputInterface::VERBOSITY_NORMAL) {
            $this->ioWriter->successLineNoBg('PCNTL is available: signals will be processed.');
        }
    }

    /**
     * @param Job    $job
     * @param array  $info
     * @param string $retryReason
     *
     * @return bool
     */
    private function retryFailedJob(Job $job, array $info, string $retryReason)
    {
        $retryJob = $this->jobsMarker->markFailedJobAsRetried($job, $info);
        $this->ioWriter->warningLineNoBg(sprintf(
                '[%s] Job <success-nobg>#%s</success-nobg> on Queue <success-nobg>%s</success-nobg>: %s.',
                $job->getClosedAt()->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue(), $retryReason)
        );
        if ($this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $this->ioWriter->noteLineNoBg(sprintf(
                '[%s] Job <success-nobg>#%s</success-nobg> on Queue "%s": Retry with Job "#%s" (Attempt #%s/%s).',
                    $job->getClosedAt()->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue(), $retryJob->getId(), $retryJob->getRetryStrategy()->getAttempts(), $retryJob->getRetryStrategy()->getMaxAttempts())
            );
        }

        return true;
    }

    /**
     * @param OutputInterface $output
     *
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    private function checkStaleJobs(OutputInterface $output)
    {
        if ($this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $this->ioWriter->infoLineNoBg('Checking stale jobs...');
            $this->ioWriter->commentLineNoBg('Jobs are "stale" if their status is already PENDING or RUNNING when the Daemon is started.');
        }

        $staleJobsCount = $this->jobsRepo->countStaleJobs();

        // No stale Jobs
        if (0 >= $staleJobsCount && $this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $this->ioWriter->infoLineNoBg('No stale Jobs found.');

            return;
        }

        $progressBar = null;
        if ($this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $progressBar = ProgressBar::createProgressBar(ProgressBar::FORMAT_PROCESS_STALE_JOBS, $output, $staleJobsCount);
        }

        // There are stale Jobs
        if ($this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $this->ioWriter->infoLineNoBg(sprintf('Found <success-nobg>%s</success-nobg> stale Jobs: start processing them.', $staleJobsCount));
        }

        $stales = [];
        /** @var Job $job */
        while (null !== $job = $this->jobsRepo->findNextStaleJob($stales)) {
            $stales[] = $job->getId();

            if ($this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                $progressBar->advance();
                $this->ioWriter->writeln('');
            }

            // Something went wrong starting the process: close it as failed
            $info['output'] = 'Job were stale.';

            if ($this->getConfig()->getRetryStaleJobs($job->getQueue())) {
                $this->retryStaleJob($job, $info, 'Job were stale.');
                continue;
            }

            $this->jobsMarker->markJobAsFailed($job, $info);
            $this->ioWriter->errorLineNoBg(sprintf(
                '[%s] Job <success-nobg>#%s</success-nobg> on Queue <success-nobg>%s</success-nobg>: Process were stale so it were marked as FAILED.',
                $job->getClosedAt()->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue()
            ));

            $this->handleChildsOfFailedJob($job);
        }
    }

    /**
     * @param Job    $job
     * @param array  $info
     * @param string $retryReason
     *
     * @return bool
     */
    private function retryStaleJob(Job $job, array $info, string $retryReason)
    {
        $retryingJob = $this->jobsMarker->markStaleJobAsRetried($job, $info);
        $this->ioWriter->warningLineNoBg(sprintf(
                '[%s] Job <success-nobg>#%s</success-nobg> on Queue <success-nobg>%s</success-nobg>: %s.',
                $job->getClosedAt()->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue(), $retryReason)
        );
        $this->ioWriter->noteLineNoBg(sprintf(
                '[%s] Job <success-nobg>#%s</success-nobg> on Queue "%s": This will be retried with Job <success-nobg>#%s</success-nobg>.',
                $job->getClosedAt()->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue(), $retryingJob->getId(), $retryingJob->getRetryStrategy()->getAttempts(), $retryingJob->getRetryStrategy()->getMaxAttempts())
        );

        return true;
    }
}
