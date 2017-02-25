<?php

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Command;

use Doctrine\ORM\EntityManager;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Daemon;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Service\QueuesDaemon;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Util\JobsMarker;
use SerendipityHQ\Bundle\ConsoleStyles\Console\Formatter\SerendipityHQOutputFormatter;
use SerendipityHQ\Bundle\ConsoleStyles\Console\Style\SerendipityHQStyle;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
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
    /** @var QueuesDaemon $daemon */
    private $daemon;
    
    /** @var  EntityManager $entityManager */
    private $entityManager;
    
    /** @var  JobsMarker $jobsMarker */
    private $jobsMarker;
    
    /** @var  SerendipityHQStyle $ioWriter */
    private $ioWriter;

    /** @var  OutputInterface $output */
    private $output;

    /**
     * @param QueuesDaemon $daemon
     * @param EntityManager $entityManager
     * @param JobsMarker $jobsMarker
     */
    public function __construct(QueuesDaemon $daemon, EntityManager $entityManager, JobsMarker $jobsMarker)
    {
        $this->daemon = $daemon;
        $this->entityManager = $entityManager;
        $this->jobsMarker = $jobsMarker;

        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('queues:run')
            ->setDescription('Start the daemon to continuously process the queue.')
            ->setDefinition(
                new InputDefinition([
                    new InputArgument('daemon', InputArgument::OPTIONAL, 'The Daemon to run.'),
                    new InputOption('enable-memprof', null),
                ])
            );
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int The status code of the command
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;

        // Create the Input/Output writer
        $this->ioWriter = new SerendipityHQStyle($input, $output);
        $this->ioWriter->setFormatter(new SerendipityHQOutputFormatter(true));

        // Do the initializing operations
        $this->daemon->initialize($input->getArgument('daemon'), $this->ioWriter, $output);

        // Check that the Daemons in the database that are still running are really still running
        $this->checkAliveDaemons();
        $this->daemon->printProfilingInfo();

        $this->ioWriter->success('Waiting for new ScheduledJobs to process...');
        $this->ioWriter->commentLineNoBg('To quit the Queues Daemon use CONTROL-C.');

        // Run the Daemon
        while ($this->daemon->isAlive()) {
            // First process Jobs already running in each queue
            foreach ($this->daemon->getConfig()->getQueues() as $queueName) {
                if ($this->daemon->hasToCheckRunningJobs($queueName)) {
                    $this->processRunningJobs($queueName);
                } elseif ($this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                    $this->ioWriter->infoLineNoBg(sprintf('No running jobs to check on queue %s', $queueName));
                }
            }

            // Then initialize new Jobs for each queue if possible
            foreach ($this->daemon->getConfig()->getQueues() as $queueName) {
                $jobsToLoad = $this->daemon->getJobsToLoad($queueName);
                if (0 < $jobsToLoad) {
                    if ($this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                        $this->ioWriter->infoLineNoBg(sprintf('Trying to initialize "%s" new Jobs for queue "%s"...', $jobsToLoad, $queueName));
                        $initializingJobs = new ProgressBar($output, $jobsToLoad);
                        $initializingJobs->setFormat('<info-nobg>[>] Job "%current%"/%max% initialized (%percent:3s%% )</info-nobg><comment-nobg> %elapsed:6s%/%estimated:-6s% (%memory:-6s%)</comment-nobg>');
                    }
                    for ($i = 0; $i < $jobsToLoad; $i++) {
                        // Start processing the next Job in the queue
                        if (null === $this->daemon->processNextJob($queueName)) {
                            if ($this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                                $this->ioWriter->infoLineNoBg(sprintf('Queue "%s" is empty: no more Jobs to initialize.', $queueName));
                            }
                            // The next Job is null: exit this queue and pass to the next one
                            break;
                        }

                        if (isset($initializingJobs)) {
                            $initializingJobs->advance();
                            $this->ioWriter->writeln('');
                        }
                    }
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
                $this->daemon->printProfilingInfo();
            }

            // If the daemon can sleep, make it sleep
            if ($this->daemon->canSleep()) {
                if ($this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                    $this->ioWriter->infoLineNoBg(sprintf(
                        'No Jobs to process. Idling for %s seconds...', $this->daemon->getConfig()->getIdleTime()
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
    private function checkAliveDaemons()
    {
        if ($this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $this->ioWriter->infoLineNoBg('Checking struggler Daemons...');
            $this->ioWriter->commentLineNoBg('Daemons are "struggler" if they are not running anymore.');
        }

        $strugglers = [];
        /** @var Daemon $daemon */
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

        $this->ioWriter->infoLineNoBg(sprintf('Found %s struggler Daemon(s).', count($strugglers)));
        $this->ioWriter->commentLineNoBg('Their "diedOn" date is set to NOW and mortisCausa is "struggler".');

        $table = [];
        /** @var Daemon $strugglerDaemon */
        foreach ($strugglers as $strugglerDaemon) {
            $age = $strugglerDaemon->getDiedOn()->diff($strugglerDaemon->getBornOn());
            $table[] = [
                sprintf('<%s>%s</>', 'success-nobg', "\xE2\x9C\x94"),
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
    private function isDaemonStillRunning(Daemon $daemon)
    {
        // Get the running processes with the Daemon's PID
        exec(sprintf('ps -ef | grep %s', $daemon->getPid()), $lines);

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
    private function processRunningJobs(string $queueName)
    {
        $currentlyRunningProgress = null;
        if ($this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
            $this->ioWriter->infoLineNoBg(sprintf(
                'Checking <comment-nobg>%s</comment-nobg> running jobs on queue "%s"...',
                $this->daemon->countRunningJobs($queueName), $queueName
            ));
            $currentlyRunningProgress = new ProgressBar($this->output, $this->daemon->countRunningJobs($queueName));
            $currentlyRunningProgress->setFormat('<info-nobg>[>] Processing job "%current%"/%max% (%percent:3s%% )</info-nobg><comment-nobg> %elapsed:6s%/%estimated:-6s% (%memory:-6s%)</comment-nobg>');
        }
        $this->daemon->checkRunningJobs($queueName, $currentlyRunningProgress);
    }
}
