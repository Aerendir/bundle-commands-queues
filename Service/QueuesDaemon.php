<?php

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Service;

use Doctrine\ORM\EntityManager;
use SerendipityHQ\Bundle\ConsoleStyles\Console\Style\SerendipityHQStyle;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Daemon;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Util\JobsMarker;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Util\Profiler;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

/**
 * The Daemon that listens for new jobs to process.
 */
class QueuesDaemon
{
    /** @var Daemon $me Is the Daemon object */
    private $me;

    /** @var array $config */
    private $config;

    /** @var EntityManager $entityManager */
    private $entityManager;

    /** @var JobsManager $jobsManager */
    private $jobsManager;

    /** @var JobsMarker $processMarker Used to change the status of Jobs during their execution */
    private $jobsMarker;

    /** @var SerendipityHQStyle $ioWriter */
    private $ioWriter;

    /**
     * @var bool If this is true, mustRun returns false and the Daemon dies.
     *           This will be true when a PCNTL SIGTERM signal is intercepted or when the max runtime execution
     *           is reached.
     */
    private $stop;

    /** @var bool $pcntlLoaded */
    private $pcntlLoaded;

    /** @var Profiler $profiler */
    private $profiler;

    /** @var array $runningJobs Keeps track of the started jobs in the queue. */
    private $runningJobs = [];

    /** @var bool $verbosity */
    private $verbosity;

    /**
     * @param array $config
     * @param EntityManager $entityManager
     * @param JobsManager $jobsManager
     * @param JobsMarker $jobsMarker
     * @param Profiler $profiler
     */
    public function __construct(array $config, EntityManager $entityManager, JobsManager $jobsManager, JobsMarker $jobsMarker, Profiler $profiler)
    {
        $this->config = $config;
        $this->entityManager = $entityManager;
        $this->jobsManager = $jobsManager;
        $this->jobsMarker = $jobsMarker;
        $this->profiler = $profiler;
    }

    /**
     * Initializes the Daemon.
     *
     * @param SerendipityHQStyle $ioWriter
     */
    public function initialize(SerendipityHQStyle $ioWriter)
    {
        $this->ioWriter = $ioWriter;
        $this->verbosity = $this->ioWriter->getVerbosity();

        $this->ioWriter->title('SerendipityHQ Queue Bundle Daemon');
        $ioWriter->infoLineNoBg('Starting the Daemon...');

        // Save the Daemon to the Database
        $this->me = new Daemon(gethostname(), getmypid(), $this->config);
        $this->entityManager->persist($this->me);
        $this->entityManager->flush();
        $ioWriter->successLineNoBg(sprintf('I\'m Daemon "%s@%s".', $this->me->getPid(), $this->me->getHost()));

        // Start the profiler
        $this->profiler->start($this->config['max_runtime']);

        // Initialize the JobsManager
        $this->jobsManager->initialize($ioWriter);

        // Disable logging in Doctrine
        $this->entityManager->getConfiguration()->setSQLLogger(null);

        // Force garbage collection (used by JobsMarker::markJobAsClosed()
        gc_enable();

        // Setup pcntl signals so it is possible to manage process
        $this->setupPcntlSignals();
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
        $this->entityManager->flush();
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
        if (true === $this->stop) {
            return false;
        }

        // The max_runtime is reached
        if ($this->profiler->isMaxRuntimeReached()) {
            if ($this->verbosity >= SymfonyStyle::VERBOSITY_NORMAL) {
                $this->ioWriter->successLineNoBg(
                    sprintf('Max runtime of "%s" seconds reached.', $this->config['max_runtime'])
                );
            }

            return false;
        }

        return true;
    }

    /**
     * Processes the next Job in the queue.
     */
    public function processNextJob()
    {
        // If the max_concurrent_jobs number is reached, don't process one more job
        if ($this->countRunningJobs() >= $this->config['max_concurrent_jobs']) {
            return;
        }

        // Get the next job to process
        $job = $this->entityManager->getRepository('SHQCommandsQueuesBundle:Job')->findNextRunnableJob();

        // If no more jobs exists in the queue
        if (null === $job) {
            if ($this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                $this->ioWriter->infoLineNoBg(sprintf('No Jobs to process. Idling for %s seconds...', $this->config['idle_time']));
            }
            sleep($this->config['idle_time']);

            return;
        }

        // Start processing the Job
        $now = new \DateTime();
        $info = [
            'started_at' => $now,
        ];
        if ($this->verbosity >= SymfonyStyle::VERBOSITY_NORMAL) {
            $this->ioWriter->infoLineNoBg(sprintf(
                    '[%s] Job "%s" on Queue "%s": Initializing the process.',
                    $now->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue()
                ));

            if (false !== strpos($job->getCommand(), 'mark-as-cancelled')) {
                $this->ioWriter->noteLineNoBg(sprintf(
                    '[%s] Job "%s" on Queue "%s": This will mark as CANCELLED childs of #%s.',
                    $now->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue(), $job->getArguments()[0]
                ));
            }

            if ($job->isRetry()) {
                $this->ioWriter->noteLineNoBg(sprintf(
                    '[%s] Job "%s" on Queue "%s": this is a retry of original process #%s.',
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

            // Check if it can be retried
            if (true === $this->retryJob($job, $info, 'Job didn\'t started as its process were aborted.')) {
                // Exit
                return;
            }

            $this->jobsMarker->markJobAsAborted($job, $info, $this->me);
            if ($this->verbosity >= SymfonyStyle::VERBOSITY_NORMAL) {
                $this->ioWriter->infoLineNoBg(sprintf(
                    '[%s] Job "%s" on Queue "%s": The process didn\'t started due to some errors. See them in the'
                    .' logs of the Job.', $now->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue()
                ));
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
        $info['job'] = $job;
        $info['process'] = $process;
        // This info is already in the Job itself, so we don't need it anymore
        unset($info['started_at']);

        // Save the just created new Job into the running jobs queue
        $this->runningJobs[] = $info;

        // Wait some millisedonds to permit Doctrine to finish writings (sometimes it is slower than the Daemon)
        $this->wait();
    }

    /**
     * @return bool
     */
    public function hasRunningJobs() : bool
    {
        return $this->countRunningJobs() > 0 ? true : false;
    }

    /**
     * Returns the number of currently running Jobs.
     *
     * @return int
     */
    public function countRunningJobs() : int
    {
        return count($this->runningJobs);
    }

    /**
     * Processes the Jobs already running.
     */
    public function checkRunningJobs(ProgressBar $progressBar = null)
    {
        foreach ($this->runningJobs as $index => $runningJob) {
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
                unset($this->runningJobs[$index]);
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
            if ($job->getStatus() === Job::STATUS_PENDING && $process->isStarted()) {
                // Mark it as running (those checks will avoid an unuseful query to the DB)
                $this->jobsMarker->markJobAsRunning($job);
            }

            // And print its PID (available only if the process is already running)
            if ($process->isStarted() && $this->verbosity >= SymfonyStyle::VERBOSITY_NORMAL) {
                $this->ioWriter->infoLineNoBg(sprintf(
                        '[%s] Job "%s" on Queue "%s": Process is currently running with PID "%s".',
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

        // The process is terminated
//        VarDumper::dump($process->getIdleTimeout());
//        VarDumper::dump($process->getStopSignal());
//        VarDumper::dump($process->getTermSignal());
//        VarDumper::dump($process->getTimeout());
//        VarDumper::dump($process->hasBeenSignaled());
//        VarDumper::dump($process->hasBeenStopped());

        // If it has to be retried, Remove the Job from the Entity Manager
        if ($job->getStatus() !== Job::STATUS_RETRIED) {
            //$this->entityManager->detach($job);
        }

        // First set to false, then unset to free up memory ASAP
        $now =
        $process =
        $job = null;
        unset($now, $process, $job);

        $this->wait();

        return true;
    }

    /**
     * Optimizes the usage of memory.
     */
    public function optimize()
    {
        // Force the garbage collection
        gc_collect_cycles();
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
     * @return Daemon
     */
    public function getIdentity() : Daemon
    {
        return $this->me;
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
        if (true === $this->retryJob($job, $info, 'Job failed')) {
            // Exit
            return;
        }

        $this->jobsMarker->markJobAsFailed($job, $info);
        $this->ioWriter->errorLineNoBg(sprintf(
                '[%s] Job "%s" on Queue "%s": Process failed (no retries allowed)',
                $job->getClosedAt()->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue()
            ));

        if ($job->getChildDependencies()->count() > 0) {
            $this->ioWriter->noteLineNoBg(sprintf(
                '[%s] Job "%s" on Queue "%s": Creating a Job to mark childs as as CANCELLED.',
                $job->getClosedAt()->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue()
            ));

            $cancelChildsJobs = $job->createCancelChildsJob();
            $this->entityManager->persist($cancelChildsJobs);

            // Flush it immediately to start the process as soon as possible
            $this->entityManager->flush($cancelChildsJobs);
        }
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
     * Sets the PCNTL signals handlers.
     */
    private function setupPcntlSignals()
    {
        // The callback to use as signal handler
        $signalHandler = function ($signo) {
            switch ($signo) {
                case SIGTERM:
                    $signal = 'SIGTERM';
                    $this->stop = true;
                    break;
                case SIGINT:
                    $signal = 'SIGINT';
                    $this->stop = true;
                    break;
                default:
                    $signal = 'Unknown '.$signo;
            }

            if ($this->verbosity >= SymfonyStyle::VERBOSITY_NORMAL) {
                $this->ioWriter->successLineNoBg(sprintf('%s signal received.', $signal));
            }
        };

        $this->pcntlLoaded = extension_loaded('pcntl');

        // If the PCNTL extension is not loded ...
        if (false === $this->pcntlLoaded) {
            if ($this->verbosity >= SymfonyStyle::VERBOSITY_NORMAL) {
                $this->ioWriter->note('PCNTL extension is not loaded. Signals cannot be processd.');
            }

            return;
        }

        // PCNTL Signals are available: configure them
        pcntl_signal(SIGTERM, $signalHandler);
        pcntl_signal(SIGINT, $signalHandler);

        if ($this->verbosity >= SymfonyStyle::VERBOSITY_NORMAL) {
            $this->ioWriter->successLineNoBg('PCNTL is available: signals will be processed.');
        }
    }

    /**
     * @param Job $job
     * @param array $info
     * @param string $retryReason
     * @return bool
     */
    private function retryJob(Job $job, array $info, string $retryReason)
    {
        // Check if it can be retried
        if (false === $job->getRetryStrategy()->canRetry()) {
            return false;
        }

        $retryJob = $this->jobsMarker->markJobAsRetried($job, $info);
        $this->ioWriter->errorLineNoBg(sprintf(
                '[%s] Job "%s" on Queue "%s": %s.',
                $job->getClosedAt()->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue(), $retryReason)
        );
        $this->ioWriter->noteLineNoBg(sprintf(
                '[%s] Job "%s" on Queue "%s": Retry with Job "%s" (Attempt #%s/%s).',
                $job->getClosedAt()->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue(), $retryJob->getId(), $retryJob->getRetryStrategy()->getAttempts(), $retryJob->getRetryStrategy()->getMaxAttempts())
        );

        return true;
    }
}
