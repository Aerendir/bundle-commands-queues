<?php

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Service;

use Doctrine\ORM\EntityManager;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Config\DaemonConfig;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Daemon;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Repository\JobRepository;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Util\JobsMarker;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Util\Profiler;
use SerendipityHQ\Bundle\ConsoleStyles\Console\Style\SerendipityHQStyle;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * The Daemon that listens for new jobs to process.
 */
class QueuesDaemon
{
    /** @var Daemon $me Is the Daemon object */
    private $me;

    /** @var bool $canSleep */
    private $canSleep = false;

    /** @var array $config */
    private $config;

    /** @var EntityManager $entityManager */
    private $entityManager;

    /** @var JobsManager $jobsManager */
    private $jobsManager;

    /** @var JobsMarker $processMarker Used to change the status of Jobs during their execution */
    private $jobsMarker;
    
    /** @var  JobRepository $jobsRepo */
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
     * @param DaemonConfig         $config
     * @param EntityManager $entityManager
     * @param JobsManager   $jobsManager
     * @param JobsMarker    $jobsMarker
     * @param Profiler      $profiler
     */
    public function __construct(DaemonConfig $config, EntityManager $entityManager, JobsManager $jobsManager, JobsMarker $jobsMarker, Profiler $profiler)
    {
        $this->config = $config;
        $this->entityManager = $entityManager;
        $this->jobsManager = $jobsManager;
        $this->jobsMarker = $jobsMarker;
        $this->jobsRepo = $this->entityManager->getRepository('SHQCommandsQueuesBundle:Job');
        $this->profiler = $profiler;
    }

    /**
     * Initializes the Daemon.
     *
     * @param string|null $daemon
     * @param SerendipityHQStyle $ioWriter
     * @param OutputInterface $output
     */
    public function initialize($daemon, SerendipityHQStyle $ioWriter, OutputInterface $output)
    {
        $this->ioWriter = $ioWriter;
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

        // Start the profiler
        $this->profiler->start($this->config->getMaxRuntime(), $this->getConfig()->getQueues());

        // Initialize the JobsManager
        $this->jobsManager->initialize($ioWriter);

        // Disable logging in Doctrine
        $this->entityManager->getConfiguration()->setSQLLogger(null);

        // Configure the repository
        $this->jobsRepo->configure($this->config->getRepoConfig());

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
    public function isAlive() : bool
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
                    sprintf('Max runtime of "%s" seconds reached.', $this->config->getMaxRuntime())
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
     * @return null|bool
     */
    public function processNextJob(string $queueName)
    {
        // If the max_concurrent_jobs number is reached, don't process one more job
        if ($this->countRunningJobs($queueName) >= $this->config->getQueue($queueName)['max_concurrent_jobs']) {
            return null;
        }

        // Get the next job to process
        $job = $this->jobsRepo->findNextRunnableJob($queueName);

        // If no more jobs exists in the queue
        if (null === $job) {
            // This queue has no more Jobs: for it the Daemon can sleep
            $this->canSleep = true;
            return null;
        }

        // This queue has another Job: for it the Daemon can't sleep as the next cycle is required to check if there are other Jobs
        $this->canSleep = false;

        // Start processing the Job
        $now = new \DateTime();
        $info = [
            'started_at' => $now,
        ];
        if ($this->verbosity >= OutputInterface::VERBOSITY_NORMAL) {
            $this->ioWriter->infoLineNoBg(sprintf(
                    '[%s] Job "%s" on Queue "%s": Initializing the process.',
                    $now->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue()
                ));

            if (false !== strpos($job->getCommand(), 'mark-as-cancelled')  && $this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                $this->ioWriter->warningLineNoBg(sprintf(
                    '[%s] Job "%s" on Queue "%s": This will mark as CANCELLED childs of #%s.',
                    $now->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue(), $job->getArguments()[0]
                ));
            }

            if ($job->isRetry() && $this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                $this->ioWriter->noteLineNoBg(sprintf(
                    '[%s] Job "%s" on Queue "%s": This is a retry of original process #%s.',
                    $now->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue(), $job->getRetryOf()->getId()
                ));
            }
        }

        // Create the process for the scheduled job
        $process = $this->jobsManager->createJobProcess($job);

        // Try to start the process
        try {
            $process->start();
        } catch (\Exception $e) {
            // Something went wrong starting the process: close it as failed
            $info['output'] = 'Failing start the process.';
            $info['output_error'] = $e;

            // Check if it can be retried and if the retry were successful
            if ($job->getRetryStrategy()->canRetry() && true === $this->retryFailedJob($job, $info, 'Job didn\'t started as its process were aborted.')) {
                // Exit
                return null;
            }

            $this->jobsMarker->markJobAsAborted($job, $info, $this->me);
            if ($this->verbosity >= OutputInterface::VERBOSITY_NORMAL) {
                $this->ioWriter->infoLineNoBg(sprintf(
                    '[%s] Job "%s" on Queue "%s": The process didn\'t started due to some errors. See them in the'
                    .' logs of the Job.', $now->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue()
                ));
            }

            return null;
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
        $info['job'] = $job;
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
    public function canSleep() : bool
    {
        return $this->canSleep;
    }

    /**
     * @return bool
     */
    public function hasToCheckAliveDaemons() : bool
    {
        return microtime(true) - $this->getProfiler()->getAliveDaemonsLastCheckedAt() >= $this->getConfig()->getAliveDaemonsCheckInterval();
    }

    /**
     * @param string $queueName
     * @return bool
     */
    public function hasToCheckRunningJobs(string $queueName) : bool
    {
        // The number of iterations is reached and the queue has currently running Jobs
        return microtime(true) - $this->getProfiler()->getRunningJobsLastCheckedAt($queueName) >= $this->getConfig()->getRunningJobsCheckInterval($queueName)
            && $this->hasRunningJobs($queueName);
    }

    /**
     * @param string $queueName
     * @return bool
     */
    public function hasRunningJobs(string $queueName = null) : bool
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
    public function countRunningJobs(string $queueName = null) : int
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
     * @param string $queueName
     * @param null|ProgressBar $progressBar
     */
    public function checkRunningJobs(string $queueName, ProgressBar $progressBar = null)
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
                    '[%s] Job "%s" on Queue "%s": Checking status...',
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
        //$job = $runningJob['job'];

        /** @var Process $process */
        //$process = $runningJob['process'];

        //  If it is not already terminated...
        if (false === $runningJob['process']->isTerminated()) {
            // ... and it is still pending but its process were effectively started
            if ($runningJob['job']->getStatus() === Job::STATUS_PENDING && $runningJob['process']->isStarted()) {
                // Mark it as running (those checks will avoid an unuseful query to the DB)
                $this->jobsMarker->markJobAsRunning($runningJob['job']);
            }

            // And print its PID (available only if the process is already running)
            if ($runningJob['process']->isStarted() && $this->verbosity >= OutputInterface::VERBOSITY_VERBOSE) {
                $this->ioWriter->infoLineNoBg(sprintf(
                        '[%s] Job "%s" on Queue "%s": Process is currently running with PID "%s".',
                        $now->format('Y-m-d H:i:s'), $runningJob['job']->getId(), $runningJob['job']->getQueue(), $runningJob['process']->getPid())
                );
            }

            // ... don't continue to process it as we have to wait it will terminate
            return false;
        }

        $processHandler = 'handleSuccessfulJob';
        if (false === $runningJob['process']->isSuccessful()) {
            $processHandler = 'handleFailedJob';
        }

        // Handle a successful or a failed job
        $this->{$processHandler}($runningJob['job'], $runningJob['process']);

        // The process is terminated
//        VarDumper::dump($process->getIdleTimeout());
//        VarDumper::dump($process->getStopSignal());
//        VarDumper::dump($process->getTermSignal());
//        VarDumper::dump($process->getTimeout());
//        VarDumper::dump($process->hasBeenSignaled());
//        VarDumper::dump($process->hasBeenStopped());

        // If it has a final status, Remove the Job from the Entity Manager
        if (
            $runningJob['job']->getStatus() === Job::STATUS_ABORTED
            || $runningJob['job']->getStatus() === Job::STATUS_FINISHED
            || $runningJob['job']->getStatus() === Job::STATUS_FAILED
            || $runningJob['job']->getStatus() === Job::STATUS_CANCELLED
        ) {
            $this->entityManager->detach($runningJob['job']);
        }

        // First set to false, then unset to free up memory ASAP
        $now =
        $runningJob = null;
        //$process =
        //$job = null;
        unset($now, $runningJob/*, $process, $job*/);

        $this->wait();

        return true;
    }

    /**
     * @return bool
     */
    public function hasToOptimize() : bool
    {
        return microtime(true) - $this->getProfiler()->getLastOptimizationAt() >= $this->getConfig()->getOptimizationInterval()
            && $this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE;
    }

    /**
     * @return bool
     */
    public function hasToPrintProfilingInfo() : bool
    {
        return microtime(true) - $this->getProfiler()->getLastMicrotime() >= $this->getConfig()->getProfilingInfoInterval();
    }

    /**
     * Optimizes the usage of memory.
     */
    public function optimize()
    {
        // Force the garbage collection
        $cycles = gc_collect_cycles();
        $this->ioWriter->infoLineNoBg(sprintf('Collected %s cycles.', $cycles));

        $this->getProfiler()->optimized();
    }

    /**
     * Prints the current profiling info.
     */
    public function printProfilingInfo()
    {
        $this->ioWriter->table(
            ['', 'Profiling info'],
            $this->profiler->profile()
        );
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
    public function getConfig() : DaemonConfig
    {
        return $this->config;
    }

    /**
     * @return Daemon
     */
    public function getIdentity() : Daemon
    {
        return $this->me;
    }

    /**
     * @param string $queueName
     * @return int
     */
    public function getJobsToLoad(string $queueName)
    {
        return $this->getConfig()->getQueue($queueName)['max_concurrent_jobs'] - $this->countRunningJobs($queueName);
    }

    /**
     * @return Profiler
     */
    public function getProfiler() : Profiler
    {
        return $this->profiler;
    }

    /**
     * @param Job     $job
     * @param Process $process
     */
    final protected function handleFailedJob(Job $job, Process $process)
    {
        $info = $this->jobsManager->buildDefaultInfo($process);

        // Check if it can be retried
        if ($job->getRetryStrategy()->canRetry() && true === $this->retryFailedJob($job, $info, 'Process failed but can be retried.')) {
            // Exit
            return;
        }

        $this->jobsMarker->markJobAsFailed($job, $info);
        $this->ioWriter->errorLineNoBg(sprintf(
                '[%s] Job "%s" on Queue "%s": Process failed (no retries allowed)',
                $job->getClosedAt()->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue()
            ));

        $this->handleChildsOfFailedJob($job);
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
            '[%s] Job "%s" on Queue "%s": Process succeded.',
            $job->getClosedAt()->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue()));
    }

    /**
     * @param Job $job
     */
    final protected function handleChildsOfFailedJob(Job $job)
    {
        if ($job->getChildDependencies()->count() > 0) {
            if ($this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                $this->ioWriter->noteLineNoBg(sprintf(
                    '[%s] Job "%s" on Queue "%s": Creating a Job to mark childs as as CANCELLED.',
                    $job->getClosedAt()->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue()
                ));
            }

            $cancelChildsJobs = $job->createCancelChildsJob();
            $this->entityManager->persist($cancelChildsJobs);

            // Flush it immediately to start the process as soon as possible
            $this->entityManager->flush($cancelChildsJobs);
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
                    $signal = 'SIGTERM';
                    $this->mustDie = true;
                    break;
                case SIGINT:
                    $signal = 'SIGINT';
                    $this->mustDie = true;
                    break;
                default:
                    $signal = 'Unknown '.$signo;
            }

            if ($this->verbosity >= OutputInterface::VERBOSITY_NORMAL) {
                $this->ioWriter->warning(sprintf('%s signal received.', $signal));
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
                '[%s] Job "%s" on Queue "%s": %s.',
                $job->getClosedAt()->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue(), $retryReason)
        );
        if ($this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $this->ioWriter->noteLineNoBg(sprintf(
                    '[%s] Job "%s" on Queue "%s": Retry with Job "%s" (Attempt #%s/%s).',
                    $job->getClosedAt()->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue(), $retryJob->getId(), $retryJob->getRetryStrategy()->getAttempts(), $retryJob->getRetryStrategy()->getMaxAttempts())
            );
        }

        return true;
    }

    /**
     * @param OutputInterface $output
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
            $progressBar = new ProgressBar($output, $staleJobsCount);
            $progressBar->setFormat('<info-nobg>[>] Processing job <comment-nobg>%current%</comment-nobg>/%max% (%percent%%)</info-nobg><comment-nobg> %elapsed:6s%/%estimated:-6s%  (%memory:-6s%)</comment-nobg>');
        }

        // There are stale Jobs
        if ($this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $this->ioWriter->infoLineNoBg(sprintf('Found <comment-nobg>%s</comment-nobg> stale Jobs: start processing them.', $staleJobsCount));
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
                '[%s] Job "%s" on Queue "%s": Process were stale so it were marked as FAILED.',
                $job->getClosedAt()->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue()
            ));

            $this->handleChildsOfFailedJob($job);
        }

        // Free up memory
        $progressBar = null;
        unset($progressBar);
    }

    /**
     * @param Job $job
     * @param array $info
     * @param string $retryReason
     * @return bool
     */
    private function retryStaleJob(Job $job, array $info, string $retryReason)
    {
        $retryingJob = $this->jobsMarker->markStaleJobAsRetried($job, $info);
        $this->ioWriter->warningLineNoBg(sprintf(
                '[%s] Job "%s" on Queue "%s": %s.',
                $job->getClosedAt()->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue(), $retryReason)
        );
        $this->ioWriter->noteLineNoBg(sprintf(
                '[%s] Job "%s" on Queue "%s": This will be retried with Job "%s".',
                $job->getClosedAt()->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue(), $retryingJob->getId(), $retryingJob->getRetryStrategy()->getAttempts(), $retryingJob->getRetryStrategy()->getMaxAttempts())
        );

        return true;
    }
}
