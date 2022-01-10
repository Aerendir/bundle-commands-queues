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

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Service;

use Carbon\Carbon;
use Countable;
use DateTime;
use Doctrine\Common\Persistence\Mapping\MappingException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\ORMInvalidArgumentException;
use Doctrine\ORM\TransactionRequiredException;
use Exception;
use RuntimeException;
use Safe\Exceptions\ArrayException;
use Safe\Exceptions\PcntlException;
use Safe\Exceptions\StringsException;
use function Safe\getmypid;
use function Safe\pcntl_signal_dispatch;
use function Safe\sleep;
use function Safe\sprintf;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Config\DaemonConfig;
use SerendipityHQ\Bundle\CommandsQueuesBundle\DependencyInjection\Configuration;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Daemon;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Repository\JobRepository;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Util\JobsMarker;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Util\JobsUtil;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Util\Profiler;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Util\ProgressBarFactory;
use SerendipityHQ\Bundle\ConsoleStyles\Console\Style\SerendipityHQStyle;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The Daemon that listens for new jobs to process.
 */
final class QueuesDaemon
{
    /**
     * @var string
     */
    private const OPTIONS = 'options';

    /**
     * @var string
     */
    private const __ID = '--id';

    /**
     * @var string
     */
    private const JOB = 'job';

    /**
     * @var string
     */
    private const PROCESS = 'process';

    /**
     * @var string
     */
    private const GET_CLOSED_AT = 'getClosedAt';

    /** @var Daemon $me Is the Daemon object */
    private $me;

    /** @var array $canSleep */
    private $canSleep = [];

    /** @var DaemonConfig $config */
    private $config;

    /** @var EntityManagerInterface $entityManager */
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
    private $mustDie = false;

    /** @var bool $pcntlLoaded */
    private $pcntlLoaded = false;

    /** @var Profiler $profiler */
    private $profiler;

    /** @var array $runningJobs Keeps track of the started jobs in the queue. */
    private $runningJobs = [];

    /** @var int $verbosity */
    private $verbosity;

    /**
     * @var \Doctrine\ORM\Configuration
     */
    private $configuration;

    /**
     * @param DaemonConfig           $config
     * @param EntityManagerInterface $entityManager
     * @param JobsManager            $jobsManager
     * @param JobsMarker             $jobsMarker
     * @param Profiler               $profiler
     */
    public function __construct(DaemonConfig $config, EntityManagerInterface $entityManager, JobsManager $jobsManager, JobsMarker $jobsMarker, Profiler $profiler, \Doctrine\ORM\Configuration $configuration)
    {
        $this->config        = $config;
        $this->entityManager = $entityManager;
        $this->jobsManager   = $jobsManager;
        $this->jobsMarker    = $jobsMarker;
        $this->profiler      = $profiler;

        /** @var JobRepository $jobsRepo */
        $jobsRepo            = $this->entityManager->getRepository(Job::class);
        $this->jobsRepo      = $jobsRepo;
        $this->configuration = $configuration;
    }

    /**
     * Initializes the Daemon.
     *
     * @param string|null        $daemon
     * @param bool               $allowProd
     * @param SerendipityHQStyle $ioWriter
     * @param OutputInterface    $output
     *
     * @throws OptimisticLockException
     * @throws Exception
     * @throws StringsException
     */
    public function initialize(?string $daemon, bool $allowProd, SerendipityHQStyle $ioWriter, OutputInterface $output): void
    {
        $this->ioWriter  = $ioWriter;
        $this->verbosity = $this->ioWriter->getVerbosity();

        $this->ioWriter->title('SerendipityHQ Queue Bundle Daemon');
        $ioWriter->infoLineNoBg('Starting the Daemon...');

        // Initialize the configuration
        $this->config->initialize($daemon, $allowProd);

        // Save the Daemon to the Database
        $hostname = \Safe\gethostname();
        if (false === \is_string($hostname)) {
            throw new RuntimeException('Impossible to get the host name.');
        }

        $this->me = new Daemon($hostname, getmypid(), $this->config);
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
        $this->configuration->setSQLLogger();
        $this->configuration->setSecondLevelCacheEnabled(false);

        // Configure the repository
        $this->jobsRepo->configure($this->config->getRepoConfig(), $this->ioWriter);

        // Force garbage collection (used by JobsMarker::markJobAsClosed()
        \gc_enable();

        // Setup pcntl signals so it is possible to manage process
        $this->setupPcntlSignals();

        // Check for Jobs started by a previous Daemon
        $this->checkStaleJobs($output);

        // Remove expired Jobs
        foreach ($this->getConfig()->getQueues() as $queueName) {
            $this->purgeExpiredJobs($queueName);
        }
    }

    /**
     * Sets the Daemon as died.
     *
     * Requiescant In Pace (May it Rest In Pace).
     *
     * @throws OptimisticLockException
     * @throws ORMException
     * @throws Exception
     */
    public function requiescantInPace(): void
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
     * @param bool $hitIteration the iteration has to be hit only from the RunCommand
     *
     * @throws StringsException
     * @throws PcntlException
     *
     * @return bool
     */
    public function isAlive(bool $hitIteration = false): bool
    {
        // Increment the iterations counter
        if ($hitIteration) {
            $this->profiler->hitIteration();
        }

        if (true === $this->pcntlLoaded) {
            pcntl_signal_dispatch();
        }

        // This is true if a SIGTERM or a SIGINT signal is received
        if (true === $this->mustDie) {
            return false;
        }

        // The daemon_max_runtime is reached
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
     * @param string $queueName
     *
     * @throws PcntlException
     * @throws StringsException
     *
     * @return bool
     */
    public function canInitializeNewJobs(string $queueName): bool
    {
        // If the queue_max_concurrent_jobs number is reached, don't process one more job
        return $this->isAlive() && false === $this->countRunningJobs($queueName) >= $this->config->getQueue($queueName)[Configuration::QUEUE_MAX_CONCURRENT_JOBS_KEY];
    }

    /**
     * Processes the next Job in the queue.
     *
     * @param string $queueName
     *
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws StringsException
     * @throws Exception
     *
     * @return bool
     */
    public function processNextJob(string $queueName): bool
    {
        // If the queue_max_concurrent_jobs number is reached, don't process one more job
        if ($this->countRunningJobs($queueName) >= $this->config->getQueue($queueName)[Configuration::QUEUE_MAX_CONCURRENT_JOBS_KEY]) {
            return false;
        }

        // Get the next job to process
        $job = $this->jobsRepo->findNextRunnableJob($queueName);

        // If no more jobs exists in the queue
        if (null === $job) {
            // This queue has no more Jobs: for it the Daemon can sleep
            $this->canSleep[$queueName] = true;

            return false;
        }

        // This queue has another Job: for it the Daemon can't sleep as the next cycle is required to check if there are other Jobs
        $this->canSleep[$queueName] = false;

        // Start processing the Job
        $now  = new DateTime();
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
                    $now->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue(), $job->getInput()[self::OPTIONS][self::__ID]
                ));
            }

            // Add the ID of the cancelling Job to the command to execute
            if (false === $job->isTypeRetrying()) {
                $job->addOption('--cancelling-job-id', (string) $job->getId());
            }
        }

        if ($job->isTypeRetrying() && $this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $jobRetryOf = $job->getRetryOf();
            if (null === $jobRetryOf) {
                throw new RuntimeException(sprintf('The job of which this one (ID: %s) is a retry is not set.', $job->getId()));
            }

            $this->ioWriter->noteLineNoBg(sprintf(
                '[%s] Job <success-nobg>#%s</success-nobg> on Queue <success-nobg>%s</success-nobg>: This is a retry of original Job <success-nobg>#%s</success-nobg> (Childs: %s).',
                $now->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue(), $jobRetryOf->getId(), $job->getChildDependencies()->count()
            ));
        }

        // Create the process for the scheduled job
        $process = $this->jobsManager->createJobProcess($job, $this->getConfig()->isProdAllowed());

        // Try to start the process
        try {
            $process->start();
        } catch (Throwable $throwable) {
            // Something went wrong starting the process: close it as failed
            $info['output']                = 'Failing start the process.';
            $info['debug']['output_error'] = $throwable->getMessage();

            // Check if it can be retried and if the retry were successful
            if ($job->getRetryStrategy()->canRetry()) {
                // Exit
                return ! $this->retryFailedJob($job, $info, "Job didn't started as its process were aborted.");
            }

            $cancellingJob = $this->handleChildsOfFailedJob($job);
            $this->jobsMarker->markJobAsAborted($job, $info, $this->me);
            if ($this->verbosity >= OutputInterface::VERBOSITY_NORMAL) {
                $this->ioWriter->errorLineNoBg(sprintf(
                    "[%s] Job <success-nobg>#%s</success-nobg> on Queue <success-nobg>%s</success-nobg>: The process didn't started due to some errors. See them in the"
                    . ' logs of the Job.', $now->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue()
                ));
                if ($cancellingJob instanceof Job) {
                    $this->ioWriter->errorLineNoBg(sprintf('The Job #%s will mark its childs as cancelled.', $cancellingJob->getId()));
                }
            }

            return false;
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
         * At that time, if it recognized as a running job, its process is really started and it will have a PID for sure.
         */
        $this->jobsMarker->markJobAsPending($job, $info, $this->me);

        // Now add the process to the runningJobs list to keep track of it later
        $info[self::JOB]     = $job;
        $info[self::PROCESS] = $process;
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

        return ! $this->hasRunningJobs();
    }

    /**
     * @return bool
     */
    public function hasToCheckAliveDaemons(): bool
    {
        if (0 === $this->getConfig()->getAliveDaemonsCheckInterval()) {
            return false;
        }

        return \microtime(true) - $this->getProfiler()->getAliveDaemonsLastCheckedAt() >= $this->getConfig()->getAliveDaemonsCheckInterval();
    }

    /**
     * @param string $queueName
     *
     * @return bool
     */
    public function hasToCheckRunningJobs(string $queueName): bool
    {
        // The number of iterations is reached and the queue has currently running Jobs
        return \microtime(true) - $this->getProfiler()->getRunningJobsLastCheckedAt($queueName) >= $this->getConfig()->getRunningJobsCheckInterval($queueName)
               && $this->hasRunningJobs($queueName);
    }

    /**
     * @param string|null $queueName
     *
     * @return bool
     */
    public function hasRunningJobs(string $queueName = null): bool
    {
        return $this->countRunningJobs($queueName) > 0;
    }

    /**
     * Returns the number of currently running Jobs.
     *
     * @param string|null $queueName
     *
     * @return int
     */
    public function countRunningJobs(string $queueName = null): int
    {
        // If the queue name is not passed, the count is on all the processing queues
        if (null === $queueName) {
            $runningJobs = 0;
            foreach ($this->runningJobs as $currentlyRunning) {
                $runningJobs += \is_countable($currentlyRunning) ? \count($currentlyRunning) : 0;
            }

            // Return the overall amount
            return $runningJobs;
        }

        return isset($this->runningJobs[$queueName]) ? \count($this->runningJobs[$queueName]) : 0;
    }

    /**
     * Processes the Jobs already running or pending.
     *
     * @param string           $queueName
     * @param ProgressBar|null $progressBar
     *
     * @throws StringsException
     * @throws OptimisticLockException
     * @throws ORMInvalidArgumentException
     * @throws TransactionRequiredException
     * @throws ORMException
     */
    public function checkRunningJobs(string $queueName, ProgressBar $progressBar = null): void
    {
        foreach ($this->runningJobs[$queueName] as $index => $runningJob) {
            $now = new DateTime();

            /** @var Job $checkingJob */
            $checkingJob = $runningJob[self::JOB];
            /** @var Process $checkingProcess */
            $checkingProcess = $runningJob[self::PROCESS];

            if (null !== $progressBar) {
                $progressBar->advance();
                $this->ioWriter->writeln('');
            }

            if ($this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                $this->ioWriter->infoLineNoBg(sprintf(
                    '[%s] Job <success-nobg>#%s [%s]</success-nobg> on Queue <success-nobg>%s</success-nobg>: Checking status...',
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
     * @throws StringsException
     * @throws OptimisticLockException
     * @throws ORMInvalidArgumentException
     * @throws TransactionRequiredException
     * @throws ORMException
     * @throws Exception
     *
     * @return bool
     */
    public function processRunningJob(array $runningJob): bool
    {
        $now = new DateTime();

        /** @var Job $job */
        $job = $runningJob[self::JOB];

        /** @var Process $process */
        $process = $runningJob[self::PROCESS];

        //  If it is not already terminated...
        if (false === $process->isTerminated()) {
            // ... and it is still pending but its process were effectively started
            if (Job::STATUS_PENDING === $job->getStatus() && $process->isStarted()) {
                // Mark it as running (those checks will avoid an unuseful query to the DB)
                $this->jobsMarker->markJobAsRunning($job);
            }

            // And print its PID (available only if the process is already running)
            if ($this->verbosity >= OutputInterface::VERBOSITY_VERY_VERBOSE && $process->isStarted()) {
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
            $startJobId = null;
            $input      = $job->getInput();
            if (null !== $input && isset($input[self::OPTIONS][self::__ID])) {
                $startJobId = $input[self::OPTIONS][self::__ID];
            }

            if (false === \is_numeric($startJobId)) {
                throw new RuntimeException('Impossible to obtain the start Job ID.');
            }

            $startJob = $this->jobsRepo->findOneById((int) $startJobId);

            if ( ! $startJob instanceof Job) {
                // The job may not exist anymore if it expired and so was deleted
                $this->ioWriter->infoLineNoBg(sprintf("The starting job <success-nobg>%s</success-nobg> doesn't exist anymore: nothing more to do...", $startJobId));

                return true;
            }

            $this->jobsManager->refreshTree($startJob);
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

        return $this->getConfig()->getManagedEntitiesTreshold() < (
            \is_array($this->entityManager->getUnitOfWork()->getIdentityMap()[Job::class]) ||
            $this->entityManager->getUnitOfWork()->getIdentityMap()[Job::class] instanceof Countable
                ? \count($this->entityManager->getUnitOfWork()->getIdentityMap()[Job::class])
                : 0
            );
    }

    /**
     * @return bool
     */
    public function hasToProfile(): bool
    {
        return \microtime(true) - $this->getProfiler()->getLastMicrotime() >= $this->getConfig()->getProfilingInfoInterval();
    }

    /**
     * Optimizes the usage of memory.
     *
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws StringsException
     * @throws TransactionRequiredException
     * @throws MappingException
     * @throws Exception
     */
    public function optimize(): void
    {
        $this->profiler->profile();

        // First try a soft detach
        $identityMap = $this->entityManager->getUnitOfWork()->getIdentityMap();
        if (isset($identityMap[Job::class])) {
            foreach ($identityMap[Job::class] as $job) {
                JobsManager::detach($job);
            }
        }

        // Check if the optimization worked
        if ($this->isAlive() && $this->hasToOptimize()) {
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

            // We need to be sure that there are no jobs running, so it is possible to flush safely
            foreach ($this->getConfig()->getQueues() as $queueName) {
                // Remove jobs that are older than queue_max_retention_days
                $this->purgeExpiredJobs($queueName);
            }

            // Clear again the EntityManager to free up all the memory
            $this->entityManager->clear();
        }

        // Force the garbage collection
        $cycles = \gc_collect_cycles();
        if ($this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
            $this->ioWriter->infoLineNoBg(sprintf('Collected <success-nobg>%s</success-nobg> cycles.', $cycles));
        }

        $this->getProfiler()->optimized();

        $this->profiler->profile();
        $this->profiler->printProfilingInfo();

        $this->ioWriter->success(sprintf('Optimization completed.'));
    }

    /**
     * Removes from the queue all the Jobs older than queue_max_retention_days.
     *
     * This method doesn't purge Jobs if there are any still running
     * (as it needs to completely clear the entity manager and then flush).
     *
     * @param string $queueName
     *
     * @throws MappingException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws StringsException
     */
    public function purgeExpiredJobs(string $queueName): void
    {
        if ($this->hasRunningJobs()) {
            return;
        }

        // Clear now the entity manager to make it completely free of Jobs
        $this->entityManager->clear();

        // @todo move this to the creating QueueConfig object
        $maxRetentionDays = $this->config->getQueue($queueName)[Configuration::QUEUE_MAX_RETENTION_DAYS_KEY];
        $maxRetentionDate = new Carbon();
        $maxRetentionDate = $maxRetentionDate->subDays($maxRetentionDays);
        if ($this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $this->ioWriter->infoLineNoBg(sprintf('Expired jobs: removing from queue <success-nobg>%s</success-nobg>...', $queueName));
            $this->ioWriter->commentLineNoBg(sprintf('Removing expired jobs (older than %s days - before %s...', $maxRetentionDays, $maxRetentionDate->format(JobsUtil::TIME_FORMAT)));
        }

        $removingJobs = $this->jobsRepo->findExpiredJobs($queueName, $maxRetentionDate);

        /** @var Job $removingJob */
        foreach ($removingJobs as $removingJob) {
            $this->ioWriter->infoLineNoBg(sprintf('Job <success-nobg>%s</success-nobg>: removing...', $removingJob->getId()));

            if (false === $removingJob->canBeRemoved()) {
                $this->ioWriter->infoLineNoBg(sprintf('Job <success-nobg>%s</success-nobg>: cannot be removed because %s. Skipping', $removingJob->getId(), $removingJob->getCannotBeRemovedBecause()));

                continue;
            }

            $this->jobsManager->remove($removingJob);
        }

        $this->entityManager->flush();
    }

    /**
     * Put the Daemon in sleep.
     */
    public function sleep(): void
    {
        sleep($this->getConfig()->getSleepFor());
    }

    /**
     * This is required to permit to Doctrine to write to the Database.
     *
     * Without this method, it is possible that a process marked as running is not persisted before itself is marked as
     * closed.
     *
     * This method ensures a delay between writings on the database.
     *
     * @throws Exception
     */
    public function wait(): void
    {
        // 1E3 = 10 to the power of 3 = 1000.
        // This returns a type double and not an int as it may be expected.
        // https://stackoverflow.com/q/55772858/1399706
        $waitTimeInMs = \random_int(500, 1000) * 1E3;
        \usleep((int) $waitTimeInMs);
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
    public function getJobsToLoad(string $queueName): int
    {
        return $this->getConfig()->getQueue($queueName)[Configuration::QUEUE_MAX_CONCURRENT_JOBS_KEY] - $this->countRunningJobs($queueName);
    }

    /**
     * @return Profiler
     */
    public function getProfiler(): Profiler
    {
        return $this->profiler;
    }

    /**
     * This is used as error handler, so it will result as dead code: DO NOT REMOVE!
     *
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
     * @throws OptimisticLockException
     * @throws StringsException
     * @throws ArrayException
     * @throws ORMException
     * @throws Exception
     */
    private function handleFailedJob(Job $job, Process $process): void
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
            JobsUtil::getFormattedTime($job, self::GET_CLOSED_AT), $job->getId(), $job->getQueue()
        );

        if ($cancellingJob instanceof Job) {
            $message = sprintf('%s The Job #%s will mark its childs as cancelled.', $message, $cancellingJob->getId());
        }

        $this->ioWriter->errorLineNoBg($message);
    }

    /**
     * @param Job     $job
     * @param Process $process
     *
     * @throws Exception
     */
    private function handleSuccessfulJob(Job $job, Process $process): void
    {
        $info = $this->jobsManager->buildDefaultInfo($process);
        $this->jobsMarker->markJobAsFinished($job, $info);

        $this->ioWriter->successLineNoBg(sprintf(
            '[%s] Job <success-nobg>#%s</success-nobg> on Queue <success-nobg>%s</success-nobg>: Process succeded.',
            JobsUtil::getFormattedTime($job, self::GET_CLOSED_AT), $job->getId(), $job->getQueue()));
    }

    /**
     * @param Job $job
     *
     * @throws OptimisticLockException
     * @throws StringsException
     * @throws ORMException
     *
     * @return bool|Job Returns false or the Job object
     */
    private function handleChildsOfFailedJob(Job $job)
    {
        if ($job->getChildDependencies()->count() > 0) {
            if ($this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                $this->ioWriter->noteLineNoBg(sprintf(
                    '[%s] Job <success-nobg>#%s</success-nobg> on Queue <success-nobg>%s</success-nobg>: Creating a Job to mark childs as as CANCELLED.',
                    (new DateTime())->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue()
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
                (new DateTime())->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue()
            ));
        }

        return false;
    }

    /**
     * Enables Memprof if required.
     */
    private function setupMemprof(): void
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
    private function setupPcntlSignals(): void
    {
        // The callback to use as signal handler
        $signalHandler = function ($signo): void {
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
                $this->ioWriter->warning(sprintf('%s signal received.', $signal));
            }
        };

        $this->pcntlLoaded = \extension_loaded('pcntl');

        // If the PCNTL extension is not loded ...
        if (false === $this->pcntlLoaded) {
            if ($this->verbosity >= OutputInterface::VERBOSITY_NORMAL) {
                $this->ioWriter->note('PCNTL extension is not loaded. Signals cannot be processd.');
            }

            return;
        }

        // PCNTL Signals are available: configure them
        \pcntl_signal(SIGTERM, $signalHandler);
        \pcntl_signal(SIGINT, $signalHandler);

        if ($this->verbosity >= OutputInterface::VERBOSITY_NORMAL) {
            $this->ioWriter->successLineNoBg('PCNTL is available: signals will be processed.');
        }
    }

    /**
     * @param Job    $job
     * @param array  $info
     * @param string $retryReason
     *
     * @throws ArrayException
     * @throws ORMException
     * @throws StringsException
     *
     * @return bool
     */
    private function retryFailedJob(Job $job, array $info, string $retryReason): bool
    {
        $retryJob = $this->jobsMarker->markFailedJobAsRetried($job, $info);
        $this->ioWriter->warningLineNoBg(sprintf(
                '[%s] Job <success-nobg>#%s</success-nobg> on Queue <success-nobg>%s</success-nobg>: %s.',
                JobsUtil::getFormattedTime($job, self::GET_CLOSED_AT), $job->getId(), $job->getQueue(), $retryReason)
        );
        if ($this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $this->ioWriter->noteLineNoBg(sprintf(
                    '[%s] Job <success-nobg>#%s</success-nobg> on Queue "%s": Retry with Job "#%s" (Attempt #%s/%s).',
                    JobsUtil::getFormattedTime($job, self::GET_CLOSED_AT), $job->getId(), $job->getQueue(), $retryJob->getId(), $retryJob->getRetryStrategy()->getAttempts(), $retryJob->getRetryStrategy()->getMaxAttempts())
            );
        }

        return true;
    }

    /**
     * @param OutputInterface $output
     *
     * @throws OptimisticLockException
     * @throws NonUniqueResultException
     * @throws StringsException
     * @throws ArrayException
     * @throws ORMException
     * @throws Exception
     */
    private function checkStaleJobs(OutputInterface $output): void
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
            $progressBar = ProgressBarFactory::createProgressBar(ProgressBarFactory::FORMAT_PROCESS_STALE_JOBS, $output, $staleJobsCount);
        }

        // There are stale Jobs
        if ($this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $this->ioWriter->infoLineNoBg(sprintf('Found <success-nobg>%s</success-nobg> stale Jobs: start processing them.', $staleJobsCount));
        }

        $stales = [];
        while (null !== $job = $this->jobsRepo->findNextStaleJob($stales)) {
            $info     = [];
            $stales[] = $job->getId();

            if ($this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                if (null !== $progressBar) {
                    $progressBar->advance();
                }

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
                JobsUtil::getFormattedTime($job, self::GET_CLOSED_AT), $job->getId(), $job->getQueue()
            ));

            $this->handleChildsOfFailedJob($job);
        }
    }

    /**
     * @param Job    $job
     * @param array  $info
     * @param string $retryReason
     *
     * @throws ArrayException
     * @throws ORMException
     * @throws StringsException
     *
     * @return bool
     */
    private function retryStaleJob(Job $job, array $info, string $retryReason): bool
    {
        $retryingJob = $this->jobsMarker->markStaleJobAsRetried($job, $info);
        $this->ioWriter->warningLineNoBg(sprintf(
                '[%s] Job <success-nobg>#%s</success-nobg> on Queue <success-nobg>%s</success-nobg>: %s.',
                JobsUtil::getFormattedTime($job, self::GET_CLOSED_AT), $job->getId(), $job->getQueue(), $retryReason)
        );
        $this->ioWriter->noteLineNoBg(sprintf(
                '[%s] Job <success-nobg>#%s</success-nobg> on Queue "%s": This will be retried with Job <success-nobg>#%s</success-nobg> (already retried %s times of %s).',
                JobsUtil::getFormattedTime($job, self::GET_CLOSED_AT), $job->getId(), $job->getQueue(), $retryingJob->getId(), $retryingJob->getRetryStrategy()->getAttempts(), $retryingJob->getRetryStrategy()->getMaxAttempts())
        );

        return true;
    }
}
