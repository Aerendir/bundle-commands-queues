<?php

namespace SerendipityHQ\Bundle\QueuesBundle\Service;

use Doctrine\ORM\EntityManager;
use SerendipityHQ\Bundle\ConsoleStyles\Console\Formatter\SerendipityHQOutputFormatter;
use SerendipityHQ\Bundle\ConsoleStyles\Console\Style\SerendipityHQStyle;
use SerendipityHQ\Bundle\QueuesBundle\Model\Job;
use SerendipityHQ\Bundle\QueuesBundle\Util\JobsMarker;
use SerendipityHQ\Bundle\QueuesBundle\Util\Profiler;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

/**
 * The Daemon that listens for new jobs to process.
 */
class QueuesDaemon
{
    /** @var  array $config */
    private $config;

    /** @var  EntityManager $entityManager */
    private $entityManager;

    /** @var  JobsManager $jobsManager */
    private $jobsManager;

    /** @var  JobsMarker $processMarker Used to change the status of Jobs during their execution */
    private $jobsMarker;

    /** @var  SerendipityHQStyle $ioWriter */
    private $ioWriter;

    /**
     * @var  bool $stop If this is true, mustRun returns false and the Daemon dies.
     *                  This will be true when a PCNTL SIGTERM signal is intercepted or when the max runtime execution
     *                  is reached.
     */
    private $stop;

    /** @var  bool $pcntlLoaded */
    private $pcntlLoaded;

    /** @var  Profiler $profiler */
    private $profiler;

    /** @var array $runningJobs Keeps track of the started jobs in the queue. */
    private $runningJobs = [];

    /** @var  bool $verbosity */
    private $verbosity;

    /**
     * @param EntityManager $entityManager
     */
    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * Initializes the Daemon.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    public function initialize(InputInterface $input, OutputInterface $output)
    {
        // Start the profiler
        $this->profiler->start($this->config['max_runtime']);

        // initialize the JobsManager
        $this->jobsManager->initialize($input, $output);

        // Create the Input/Output writer
        $this->ioWriter = new SerendipityHQStyle($input, $output);
        $this->ioWriter->setFormatter(new SerendipityHQOutputFormatter(true));

        // Set the verbosity
        $this->verbosity = $output->getVerbosity();

        // Disable logging in Doctrine
        $this->entityManager->getConfiguration()->setSQLLogger(null);

        // Force garbage collection (used by JobsMarker::markJobAsClosed()
        gc_enable();

        // Setup pcntl signals so it is possible to manage process
        $this->setupPcntlSignals();
    }

    /**
     * Whil this returns true, the Daemon will continue to run.
     *
     * This service is not meant to be retrieved outside of QueuesRunCommand.
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
                $this->ioWriter->successLineNoBg(sprintf('Max runtime of "%s" seconds reached.', $this->config['max_runtime']));
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

        $job = $this->entityManager->getRepository(Job::class)->findOneBy(['status' => Job::STATUS_NEW, 'createdAt' => 'ASC']);

        // If no more jobs exists in the queue
        if (null === $job) {
            // Wait the configured idle_time and then return
            if ($this->verbosity >= SymfonyStyle::VERBOSITY_NORMAL) {
                $this->ioWriter->infoLineNoBg(sprintf('No more jobs: idling for %s seconds.', $this->config['idle_time']));
            }
            sleep($this->config['idle_time']);
            return;
        }

        $now = new \DateTime();
        $info = [
            'started_at' => $now
        ];
        if ($this->verbosity >= SymfonyStyle::VERBOSITY_NORMAL) {
            $this->ioWriter->infoLineNoBg(sprintf('[%s] Job "%s" on Queue "%s": Initializing the process.', $now->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue()));
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

            $this->jobsMarker->markJobAsAborted($job, $info);
            if ($this->verbosity >= SymfonyStyle::VERBOSITY_NORMAL) {
                $this->ioWriter->infoLineNoBg(sprintf('[%s] Job "%s" on Queue "%s": The process didn\'t started due to some errors. See them in the logs of the Job.', $now->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue()));
            }
            return;
        }

        // Now start processing the job
        $this->jobsMarker->markJobAsPending($job, $info);

        // Now add the process to the runningJobs list to keep track of it later
        $info['job'] = $job;
        $info['process'] = $process;
        // This info is already in the Job itself, so we don't need it anymore
        unset($info['started_at']);

        // Save the just created new Job into the running jobs queue
        $this->runningJobs[] = $info;

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
     * @return int
     */
    public function countRunningJobs() : int
    {
        return count($this->runningJobs);
    }

    /**
     * Processes the Jobs already running.
     */
    public function processRunningJobs()
    {
        foreach ($this->runningJobs as $index => $runningJob) {
            // If the running job is porcessed correctly...
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

        // If the current status of the job is Pending but its process started and is not already terminated
        if ($job->getStatus() === Job::STATUS_PENDING && $process->isStarted() && false === $process->isTerminated()) {
            // Mark it as running (those checks will avoid an unuseful query to the DB)
            $this->jobsMarker->markJobAsRunning($job);

            // And print its PID (available only if the process is already running)
            if ($this->verbosity >= SymfonyStyle::VERBOSITY_NORMAL) {
                $this->ioWriter->infoLineNoBg(sprintf('[%s] Job "%s" on Queue "%s": Process is currently running with PID "%s".', $now->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue(), $process->getPid()));
            }
        }

        if (false === $process->isTerminated()) {
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
        // Free some memory if this is the %n iteration
        if ($this->profiler->getCurrentIteration() %10000 === 0) {
            // Force the garbage collection after a command is closed
            gc_collect_cycles();

            // Clear the entity manager to avoid unuseful consumption of memory
            $this->entityManager->clear();

            $this->sayProfilingInfo();
        }
    }

    /**
     * @param $message
     * @param string $method
     */
    public function say($message, string $method)
    {
        if ($this->verbosity >= SymfonyStyle::VERBOSITY_NORMAL) {
            $this->ioWriter->$method($message);
        }
    }

    /**
     * Prints the current profiling info.
     */
    public function sayProfilingInfo()
    {
        $this->ioWriter->table(
            ['Profiling info'],
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
     * @param array $config
     * @param EntityManager $entityManager
     * @param JobsManager $jobsManager
     * @param JobsMarker $jobsMarker
     * @param Profiler $profiler
     */
    public function setDependencies(array $config, EntityManager $entityManager, JobsManager $jobsManager, JobsMarker $jobsMarker, Profiler $profiler)
    {
        $this->config = $config;
        $this->entityManager = $entityManager;
        $this->jobsManager = $jobsManager;
        $this->jobsMarker = $jobsMarker;
        $this->profiler = $profiler;
    }

    /**
     * @param Job $job
     * @param Process $process
     */
    protected final function handleFailedJob(Job $job, Process $process)
    {
        $info = $this->jobsManager->buildDefaultInfo($process);
        $this->jobsMarker->markJobAsFailed($job, $info);
        $this->ioWriter->errorLineNoBg(sprintf('[%s] Job "%s" on Queue "%s": Process failed.', $job->getClosedAt()->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue()));
    }

    /**
     * @param Job $job
     * @param Process $process
     */
    protected final function handleSuccessfulJob(Job $job, Process $process)
    {
        $info = $this->jobsManager->buildDefaultInfo($process);
        $this->jobsMarker->markJobAsFinished($job, $info);
        $this->ioWriter->successLineNoBg(sprintf('[%s] Job "%s" on Queue "%s": Process succeded.', $job->getClosedAt()->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue()));
    }

    /**
     * Sets the PCNTL signals handlers.
     */
    private function setupPcntlSignals()
    {
        // The callback to use as signal handler
        $signalHandler = function($signo) {
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
                    $signal = 'Unknown ' . $signo;
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

        return;
    }
}
