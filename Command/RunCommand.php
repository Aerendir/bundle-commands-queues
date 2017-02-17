<?php

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Command;

use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Daemon;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Service\QueuesDaemon;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs the daemon that listens for new Joobs to process.
 */
class RunCommand extends AbstractQueuesCommand
{
    /** @var QueuesDaemon $daemon */
    private $daemon;

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
        parent::execute($input, $output);

        // Do the initializing operations
        $this->daemon = $this->getContainer()->get('commands_queues.do_not_use.daemon');
        $this->daemon->initialize($this->getIoWriter());
        $this->daemon->printProfilingInfo();

        // Check that the Daemons in the database that are still running are really still running
        $this->checkAliveDaemons();

        $this->getIoWriter()->success('Waiting for new ScheduledJobs to process...');
        $this->getIoWriter()->commentLineNoBg('To quit the Queues Daemon use CONTROL-C.');

        // Run the Daemon
        while ($this->daemon->isAlive()) {
            // Start processing the next Job in the queue
            $this->daemon->processNextJob();

            // Then process jobs already running
            $runningJobsCheckInterval = $this->getContainer()->getParameter('commands_queues.running_jobs_check_interval');
            if ($this->daemon->getProfiler()->getCurrentIteration() % $runningJobsCheckInterval === 0 && $this->daemon->hasRunningJobs()) {
                if ($this->getIoWriter()->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                    $this->getIoWriter()->infoLineNoBg(sprintf('Checking %s running jobs...', $this->daemon->countRunningJobs()));
                }
                $this->daemon->checkRunningJobs();
            }

            // Check alive daemons
            $aliveDaemonsCheckInterval = $this->getContainer()->getParameter('commands_queues.alive_daemons_check_interval');
            if ($this->daemon->getProfiler()->getCurrentIteration() % $aliveDaemonsCheckInterval === 0) {
                if ($this->getIoWriter()->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                    $this->getIoWriter()->infoLineNoBg('Checking alive Daemons...');
                }
                $this->checkAliveDaemons();
            }

            // Free some memory
            $optimizationInterval = $this->getContainer()->getParameter('commands_queues.optimization_interval');
            if ($this->daemon->getProfiler()->getCurrentIteration() % $optimizationInterval === 0) {
                if ($this->getIoWriter()->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                    $this->getIoWriter()->infoLineNoBg('Optimizing the Daemons...');
                }
                $this->daemon->optimize();
            }

            // Print profiling info
            $printProfilingInfoInterval = $this->getContainer()->getParameter('commands_queues.print_profiling_info_interval');
            if (
                (microtime(true) - $this->daemon->getProfiler()->getLastMicrotime()) >= $printProfilingInfoInterval
                && $this->getIoWriter()->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE
            ) {
                $this->daemon->printProfilingInfo();
            }
        }

        $this->getIoWriter()->note('Entering shutdown sequence.');

        // Wait for the currently running jobs to finish
        $remainedJobs = $this->daemon->countRunningJobs();
        $currentlyRunningProgress = new ProgressBar($output, $remainedJobs);
        $currentlyRunningProgress->setFormat('<info-nobg>[>] Processing job %current%/%max% (%percent:3s%% )</info-nobg><comment-nobg> %elapsed:6s%/%estimated:-6s%</comment-nobg>');

        while ($this->daemon->hasRunningJobs()) {
            // Continue to process running jobs
            $this->daemon->checkRunningJobs($currentlyRunningProgress);

            // And wait a bit to give them the time to finish
            $this->daemon->wait();
        }

        // Set the daemon as died
        $this->daemon->requiescantInPace();

        $this->getIoWriter()->success('All done: Queue Daemon ended running. No more ScheduledJobs will be processed.');

        return 0;
    }

    /**
     * Checks that the Damons in the database without a didedOn date are still alive (running).
     */
    private function checkAliveDaemons()
    {
        $strugglers = [];
        /** @var Daemon $daemon */
        while (null !== $daemon = $this->getEntityManager()->getRepository('SHQCommandsQueuesBundle:Daemon')
                ->findNextAlive($this->daemon->getIdentity())) {
            if (false === $this->isDaemonStillRunning($daemon)) {
                $daemon->requiescatInPace(Daemon::MORTIS_STRAGGLER);
                $this->getEntityManager()->flush();
                $this->getEntityManager()->detach($daemon);
                $strugglers[] = $daemon;
            }
        }

        if (false === empty($strugglers) && $this->getIoWriter()->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $this->getIoWriter()->infoLineNoBg(
                sprintf(
                    'Found %s struggler Daemon(s).',
                    count($strugglers)
                )
            );
            $this->getIoWriter()->commentLineNoBg('Daemons are "struggler" if they are not running anymore.');
            $this->getIoWriter()->noteLineNoBg('Their "diedOn" date is set to NOW and mortisCausa is "struggler".');
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
            $this->getIoWriter()->table(
                ['', 'PID', 'Host', 'Born on', 'Died On', 'Age', 'Mortis Causa'],
                $table
            );
        }
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
}
