<?php

namespace SerendipityHQ\Bundle\QueuesBundle\Command;

use Doctrine\ORM\EntityManager;
use SerendipityHQ\Bundle\ConsoleStyles\Console\Formatter\SerendipityHQOutputFormatter;
use SerendipityHQ\Bundle\ConsoleStyles\Console\Style\SerendipityHQStyle;
use SerendipityHQ\Bundle\QueuesBundle\Model\Job;
use SerendipityHQ\Bundle\QueuesBundle\Util\JobsMarker;
use SerendipityHQ\Bundle\QueuesBundle\Util\Profiler;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessBuilder;
use Symfony\Component\VarDumper\VarDumper;

/**
 * Command to manage the queue.
 */
class QueuesRunCommand extends ContainerAwareCommand
{
    /** @var  EntityManager */
    private $entityManager;

    /** @var  string $env */
    private $env;

    /** @var  SerendipityHQStyle $ioWriter */
    private $ioWriter;

    /** @var  bool $pcntlLoaded */
    private $pcntlLoaded;

    /** @var  JobsMarker $processMarker Used to change the status of Jobs during their execution */
    private $jobsMarker;

    /** @var  Profiler $profiler */
    private $profiler;

    /** @var array $runningJobs Keeps track of the started jobs in the queue. */
    private $runningJobs = [];

    /**
     * @var  bool $shutdown If this is true, the process will be shutdown. This will be true when a PCNTL SIGTERM
     *                      signal is intercepted.
     */
    private $shutdown;

    /** @var  bool $verbosity */
    private $verbosity;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('queues:run')
            ->setDescription('Start the daemon to continuously process the queue.');
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return bool
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->entityManager = $this->getContainer()->get('queues.entity_manager');
        $this->env = $input->getOption('env');
        $this->ioWriter = new SerendipityHQStyle($input, $output);
        $this->ioWriter->setFormatter(new SerendipityHQOutputFormatter(true));
        $this->jobsMarker = new JobsMarker($this->entityManager);
        $this->verbosity = $output->getVerbosity();

        $this->ioWriter->title('SerendipityHQ Queue Bundle Daemon');

        // Disable logging in Doctrine
        $this->entityManager->getConfiguration()->setSQLLogger(null);

        // Setup pcntl signals so it is possible to manage process
        $this->setupPcntlSignals();

        // Force garbage collection (used by JobsMarker::markJobAsClosed()
        gc_enable();

        // Now start the profiler
        $this->profiler = new Profiler();

        if ($this->verbosity >= SymfonyStyle::VERBOSITY_VERBOSE) {
            $this->ioWriter->infoLineNoBg('Starting the Daemon...');
            $this->ioWriter->table(
                ['Profiling info'],
                $this->profiler->profile()
            );
        }

        $this->ioWriter->infoLineNoBg(sprintf('My PID is "%s".', getmygid()));
        $this->ioWriter->success('Waiting for new ScheduledJobs to process...');
        $this->ioWriter->commentLineNoBg('To quit the Queues Daemon use CONTROL-C.');

        // Start processing the queue
        return $this->processQueue();
    }

    /**
     * Starts the daemon that listens for new ScheduledJobs.
     */
    private function processQueue()
    {
        $i = 0;
        while(true) {
            if (true === $this->pcntlLoaded) {
                pcntl_signal_dispatch();
            }

            // If a SIGTERM or a SIGINT signal is dispatched, this will be true
            if (true === $this->shutdown) {
                break;
            }

            // Start processing the jobs in the queue
            $this->processJobs();

            // Then process jobs already running
            $this->processRunningJobs();

            // Free some memory
            if (10000 === $i) {
                // Force the garbage collection after a command is closed
                gc_collect_cycles();

                // Clear the entity manager to avoid unuseful consumption of memory
                $this->entityManager->clear();

                //$this->ioWriter->table(['Profiling info'], $this->profiler->profile());
                $i = 0;
            }

            $i++;
        }

        $this->ioWriter->note('Entering shutdown sequence.');
        $this->ioWriter->success('All done: Queue Daemon ended running. No more ScheduledJobs will be processed.');

        return 0;
    }

    /**
     * Processes the ScheduledJobs in the queue.
     */
    private function processJobs()
    {
        $jobs = $this->entityManager->getRepository(Job::class)->findBy(['status' => Job::STATUS_NEW]);

        foreach ($jobs as $job) {
            $this->processJob($job);
        }
    }

    /**
     * Processes the Jobs already running.
     */
    private function processRunningJobs()
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
     * @param Job $job
     */
    private function processJob(Job $job)
    {
        $now = new \DateTime();
        $info = [
            'started_at' => $now
        ];

        if ($this->verbosity >= SymfonyStyle::VERBOSITY_NORMAL) {
            $this->ioWriter->infoLineNoBg(sprintf('[%s] Job "%s" on Queue "%s": Initializing the process.', $now->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue()));
        }

        // Create the process for the scheduled job
        $process = $this->createJobProcess($job);

        // Try to start the process
        try {
            $process->start();
        } catch (\Exception $e) {
            // Something went wrong starting the process: close it as failed
            $info['output'] = 'Failing start the process.';
            $info['output_error'] = $e;

            $this->jobsMarker->markJobAsAborted($job, $info);
            $this->ioWriter->infoLineNoBg(sprintf('[%s] Job "%s" on Queue "%s": The process didn\'t started due to some errors. See them in the logs of the Job.', $now->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue()));
            return;
        }

        // Now start processing the job
        $this->jobsMarker->markJobAsPending($job, $info);

        // Now add the process to the runningJobs list to keep track of it later
        $info['job'] = $job;
        $info['process'] = $process;
        // This info is already in the Job itself, so we don't need it anymore
        unset($info['started_at']);
        $this->runningJobs[] = $info;

        $this->wait();
    }

    /**
     * @param array $runningJob
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
     * @param Job $job
     * @param Process $process
     */
    protected final function handleFailedJob(Job $job, Process $process)
    {
        $info = $this->buildDefaultInfo($process);
        $this->jobsMarker->markJobAsFailed($job, $info);
        $this->ioWriter->errorLineNoBg(sprintf('[%s] Job "%s" on Queue "%s": Process failed.', $job->getClosedAt()->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue()));
    }

    /**
     * @param Job $job
     * @param Process $process
     */
    protected final function handleSuccessfulJob(Job $job, Process $process)
    {
        $info = $this->buildDefaultInfo($process);
        $this->jobsMarker->markJobAsFinished($job, $info);
        $this->ioWriter->successLineNoBg(sprintf('[%s] Job "%s" on Queue "%s": Process succeded.', $job->getClosedAt()->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue()));
    }

    /**
     * @param Process $process
     * @return array
     */
    private function buildDefaultInfo(Process $process)
    {
        return [
            'output' => $process->getOutput() . $process->getErrorOutput(),
            'exit_code' => $process->getExitCode(),
            'debug' => [
                'exit_code_text' => $process->getExitCodeText(),
                'complete_command' => $process->getCommandLine(),
                'input' => $process->getInput(),
                'options' => $process->getOptions(),
                'env' => $process->getEnv(),
                'working_directory' => $process->getWorkingDirectory(),
                'enhanced_sigchild_compatibility' => $process->getEnhanceSigchildCompatibility(),
                'enhanced_windows_compatibility' => $process->getEnhanceWindowsCompatibility()
            ]
        ];
    }

    /**
     * @param Job $job
     * @return \Symfony\Component\Process\Process
     */
    private function createJobProcess(Job $job)
    {
        $processBuilder = new ProcessBuilder();
        $arguments = [];

        // Prepend php
        $arguments[] = 'php';

        // Add the console
        $arguments[] = $this->findConsole();

        // The command to execute
        $arguments[] = $job->getCommand();

        // Environment to use
        $arguments[] = '--env=' . $this->env;

        // Verbosity level
        $arguments[] = $this->guessVerbosityLevel();

        // The arguments of the command
        $arguments = array_merge($arguments, $job->getArguments());

        // Build the command to be run
        $processBuilder->setArguments($arguments);

        return $processBuilder->getProcess();
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
                    $this->shutdown = true;
                    break;
                case SIGINT:
                    $signal = 'SIGINT';
                    $this->shutdown = true;
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
            $this->ioWriter->note('PCNTL extension is not loaded. Signals cannot be processd.');
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

    /**
     * Finds the path to the console file.
     *
     * @return string
     * @throws \RuntimeException If the console file cannot be found.
     */
    private function findConsole() : string
    {
        $kernelDir = $this->getContainer()->getParameter('kernel.root_dir');

        if (file_exists($kernelDir.'/console')) {
            return $kernelDir.'/console';
        }

        if (file_exists($kernelDir.'/../bin/console')) {
            return $kernelDir.'/../bin/console';
        }

        throw new \RuntimeException('Unable to find the console file. You should check your Symfony installation. The console file should be in /app/ folder or in /bin/ folder.');
    }

    /**
     * @return string
     */
    private function guessVerbosityLevel() : string
    {
        switch ($this->verbosity) {
            case OutputInterface::VERBOSITY_QUIET:
                return '-q';
                break;
            case OutputInterface::VERBOSITY_VERBOSE:
                return '-vv';
                break;
            case OutputInterface::VERBOSITY_VERY_VERBOSE:
                return '-vv';
                break;
            case OutputInterface::VERBOSITY_DEBUG:
                return '-vvv';
                break;
            case OutputInterface::VERBOSITY_NORMAL:
            default:
                // This WILL NEVER be reached as default
                return '';
        }
    }

    /**
     * This is required to permit to Doctrine to write to the Database.
     *
     * Without this method, it is possible that a process marked as running is not persisted before itself is marked as
     * closed.
     *
     * This method ensures a delay between writings on the database.
     */
    private function wait()
    {
        $waitTimeInMs = mt_rand(500, 1000);
        usleep($waitTimeInMs * 1E3);
    }
}
