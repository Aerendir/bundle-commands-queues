<?php

namespace SerendipityHQ\Bundle\QueuesBundle\Command;

use Doctrine\ORM\EntityManager;
use SerendipityHQ\Bundle\QueuesBundle\Model\ScheduledJob;
use SerendipityHQ\Bundle\QueuesBundle\Util\Profiler;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\ProcessBuilder;
use Symfony\Component\VarDumper\VarDumper;

/**
 * Command to manage the queue.
 */
class QueueRunCommand extends ContainerAwareCommand
{
    /** @var  EntityManager */
    private $entityManager;

    /** @var  string $env */
    private $env;

    /** @var  SymfonyStyle $ioWriter */
    private $ioWriter;

    /** @var  bool $pcntlLoaded */
    private $pcntlLoaded;

    /** @var  Profiler $profiler */
    private $profiler;

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
            ->setName('queue:run')
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
        // First of all start the profile
        $this->profiler = new Profiler();
        $this->entityManager = $this->getContainer()->get('queues.entity_manager');
        $this->env = $input->getOption('env');
        $this->ioWriter = new SymfonyStyle($input, $output);
        $this->verbosity = $output->getVerbosity();

        // Force garbage collection
        gc_enable();

        $this->ioWriter->title('SerendipityHQ Queue Bundle Daemon');

        // Disable logging in Doctrine
        $this->entityManager->getConfiguration()->setSQLLogger(null);

        // Setup pcntl signals so it is possible to manage process
        $this->setupPcntlSignals();

        if ($this->verbosity >= SymfonyStyle::VERBOSITY_VERBOSE) {
            $this->ioWriter->block('Starting the Daemon...', 'INFO', 'fg=blue');
            $this->ioWriter->table(
                ['Profiling info'],
                $this->profiler->profile()
            );
        }

        $this->ioWriter->success('Waiting for new ScheduledJobs to process...');
        $this->ioWriter->comment('To quit the Queue Daemon use CONTROL-C.');

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
            $this->processScheduledJobs();

            if (10000 === $i) {
                $this->ioWriter->table(['Profiling info'], $this->profiler->profile());
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
    private function processScheduledJobs()
    {
        $scheduledJobs = $this->entityManager->getRepository(ScheduledJob::class)->findBy(['status' => ScheduledJob::STATE_NEW]);

        foreach ($scheduledJobs as $scheduledJob) {
            $this->processScheduledJob($scheduledJob);
        }

        // Clear the entity manager to avoid unuseful consumption of memory
        $this->entityManager->clear();
    }

    /**
     * @param ScheduledJob $scheduledJob
     */
    private function processScheduledJob(ScheduledJob $scheduledJob)
    {
        $this->ioWriter->section(sprintf('ScheduledJob "%s" on Queue "%s"', $scheduledJob->getId(), $scheduledJob->getQueue()));
        $this->ioWriter->writeln(sprintf('<info>ScheduledJob %s: Start processing.</info>', $scheduledJob->getId()));

        // Now start processing the job
        $this->markScheduledJobAsRunning($scheduledJob);

        // Create the process for the scheduled job
        $process = $this->createScheduledJobProcess($scheduledJob);
        $process->start();

        $this->markScheduledJobAsClosed($scheduledJob, ScheduledJob::STATE_FINISHED);
        $this->ioWriter->writeln(sprintf('<info>ScheduledJob %s: Processed.</info>', $scheduledJob->getId()));

        // Force the garbage collection at the end of the command execution;
        gc_collect_cycles();
    }

    /**
     * @param ScheduledJob $scheduledJob
     * @return \Symfony\Component\Process\Process
     */
    private function createScheduledJobProcess(ScheduledJob $scheduledJob)
    {
        $processBuilder = new ProcessBuilder();

        // The command to execute
        $command = $scheduledJob->getCommand();

        // Environment to use
        $environment = '--env=' . $this->env;

        // Verbosity level
        $verbosity = $this->guessVerbosityLevel();

        // The arguments of the command
        $arguments = $scheduledJob->getArguments();

        // Add other relvant arguments
        $arguments[] = $environment;
        $arguments[] = $verbosity;
        if (false === empty($arguments)) {
            $arguments = array_map(['Symfony\Component\Process\ProcessUtils', 'escapeArgument'], $arguments);
        }

        // Find the console
        $console = $this->findConsole();

        // Build the command to be run
        $processBuilder
            ->add('php')
            ->add($console)
            ->add($command)
            ->setArguments($arguments);


        return $processBuilder->getProcess();
    }

    /**
     * @param ScheduledJob $scheduledJob
     */
    private function markScheduledJobAsRunning(ScheduledJob $scheduledJob)
    {
        $reflection = new \ReflectionClass($scheduledJob);

        $state = $reflection->getProperty('status');
        $state->setAccessible(true);
        $state->setValue($scheduledJob, ScheduledJob::STATE_RUNNING);

        $startedAt = $reflection->getProperty('startedAt');
        $startedAt->setAccessible(true);
        $startedAt->setValue($scheduledJob, new \DateTime());

        $this->entityManager->flush();
    }

    /**
     * @param ScheduledJob $scheduledJob
     * @param string $currentStatus
     */
    private function markScheduledJobAsClosed(ScheduledJob $scheduledJob, string $currentStatus)
    {
        $reflection = new \ReflectionClass($scheduledJob);

        $status = $reflection->getProperty('status');
        $status->setAccessible(true);
        $status->setValue($scheduledJob, $currentStatus);

        $closedAt = $reflection->getProperty('closedAt');
        $closedAt->setAccessible(true);
        $closedAt->setValue($scheduledJob, new \DateTime());

        $this->entityManager->flush();
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
                $this->ioWriter->block(sprintf('%s signal received.', $signal), 'OK', 'fg=green');
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
            $this->ioWriter->block('PCNTL is available: signals will be processed.', 'OK', 'fg=green');
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
}
