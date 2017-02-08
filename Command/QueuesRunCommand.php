<?php

namespace SerendipityHQ\Bundle\QueuesBundle\Command;

use SerendipityHQ\Bundle\ConsoleStyles\Console\Formatter\SerendipityHQOutputFormatter;
use SerendipityHQ\Bundle\ConsoleStyles\Console\Style\SerendipityHQStyle;
use SerendipityHQ\Bundle\QueuesBundle\Entity\Daemon;
use SerendipityHQ\Bundle\QueuesBundle\Service\QueuesDaemon;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to manage the queue.
 */
class QueuesRunCommand extends ContainerAwareCommand
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
        // Create the Input/Output writer
        $ioWriter = new SerendipityHQStyle($input, $output);
        $ioWriter->setFormatter(new SerendipityHQOutputFormatter(true));

        // Do the initializing operations
        $this->daemon = $this->getContainer()->get('queues.do_not_use.daemon');
        $this->daemon->initialize($ioWriter);
        $this->daemon->printProfilingInfo();

        // Check that the Daemons in the database that are still running are really still running
        $this->checkAliveDaemons($ioWriter);

        $ioWriter->success('Waiting for new ScheduledJobs to process...');
        $ioWriter->commentLineNoBg('To quit the Queues Daemon use CONTROL-C.');

        // Run the Daemon
        while ($this->daemon->isAlive()) {
            // Start processing the next in the queue
            $this->daemon->processNextJob();

            // Then process jobs already running
            $runningJobsCheckInterval = $this->getContainer()->getParameter('queues.running_jobs_check_interval');
            if ($this->daemon->getProfiler()->getCurrentIteration() % $runningJobsCheckInterval === 0) {
                if ($ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                    $ioWriter->infoLineNoBg('Checking running jobs...');
                }
                $this->daemon->checkRunningJobs();
            }

            // Check alive daemons
            $aliveDaemonsCheckInterval = $this->getContainer()->getParameter('queues.alive_daemons_check_interval');
            if ($this->daemon->getProfiler()->getCurrentIteration() % $aliveDaemonsCheckInterval === 0) {
                if ($ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                    $ioWriter->infoLineNoBg('Checking alive Daemons...');
                }
                $this->checkAliveDaemons($ioWriter);
            }

            // Free some memory
            $optimizationInterval = $this->getContainer()->getParameter('queues.optimization_interval');
            if ($this->daemon->getProfiler()->getCurrentIteration() % $optimizationInterval === 0) {
                if ($ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                    $ioWriter->infoLineNoBg('Optimizing the Daemons...');
                }
                $this->daemon->optimize();
            }

            // Print profiling info
            $printProfilingInfoInterval = $this->getContainer()->getParameter('queues.print_profiling_info_interval');
            if (
                (microtime(true) - $this->daemon->getProfiler()->getLastMicrotime()) >= $printProfilingInfoInterval
                && $ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE
            ) {
                $this->daemon->printProfilingInfo();
            }
        }

        $ioWriter->note('Entering shutdown sequence.');

        // Wait for the currently running jobs to finish
        $remainedJobs = $this->daemon->countRunningJobs();
        $progress = new ProgressBar($output, $remainedJobs);
        $progress->setFormat('<success-nobg>%current%</success-nobg>/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%');
        if (0 < $remainedJobs) {
            $progress->start();
            $ioWriter->writeln('');
        }
        while ($this->daemon->hasRunningJobs()) {
            // Continue to process running jobs
            $this->daemon->checkRunningJobs();

            $progress->setProgress($remainedJobs - $this->daemon->countRunningJobs());
            $ioWriter->writeln('');

            // And wait a bit to give them the time to finish
            $this->daemon->wait();
        }

        // A simple line separator
        if (0 < $remainedJobs) {
            $ioWriter->writeln('');
        }

        // Set the daemon as died
        $this->daemon->requiescantInPace();

        $ioWriter->success('All done: Queue Daemon ended running. No more ScheduledJobs will be processed.');

        return 0;
    }

    /**
     * Checks that the Damons in the database without a didedOn date are still alive (running).
     *
     * @param SerendipityHQStyle $ioWriter
     */
    private function checkAliveDaemons(SerendipityHQStyle $ioWriter)
    {
        $strugglers = [];
        while (null !== $daemon = $this->getContainer()->get('queues.do_not_use.entity_manager')
                ->getRepository('QueuesBundle:Daemon')->findNextAlive($this->daemon->getIdentity())) {
            if (false === $this->isDaemonStillRunning($daemon)) {
                $daemon->requiescatInPace(Daemon::MORTIS_STRAGGLER);
                $this->getContainer()->get('queues.do_not_use.entity_manager')->flush();
                $this->getContainer()->get('queues.do_not_use.entity_manager')->detach($daemon);
                $strugglers[] = $daemon;
            }
        }

        if (false === empty($strugglers) && $ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $ioWriter->infoLineNoBg(
                sprintf(
                    'Found %s struggler Daemon(s).',
                    count($strugglers)
                )
            );
            $ioWriter->commentLineNoBg('Daemons are "struggler" if they are not running anymore.');
            $ioWriter->noteLineNoBg('Their "diedOn" date is set to NOW and mortisCausa is "struggler".');
            $table = [];
            /** @var Daemon $strugglerDaemon */
            foreach ($strugglers as $strugglerDaemon) {
                $table[] = [
                    sprintf('<%s>%s</>', 'success-nobg', "\xE2\x9C\x94"),
                    $strugglerDaemon->getPid(),
                    $strugglerDaemon->getHost(),
                    $strugglerDaemon->getBornOn()->format('Y-m-d H:i:s'),
                    $strugglerDaemon->getDiedOn()->format('Y-m-d H:i:s'),
                    $strugglerDaemon->getMortisCausa(),
                ];
            }
            $ioWriter->table(
                ['', 'PID', 'Host', 'Born on', 'Died On', 'Mortis Causa'],
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
