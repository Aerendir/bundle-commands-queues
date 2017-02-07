<?php

namespace SerendipityHQ\Bundle\QueuesBundle\Command;

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
    /** @var  QueuesDaemon $daemon */
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
        $this->daemon = $this->getContainer()->get('queues.do_not_use.daemon');
        
        // Do the initializing operations
        $this->daemon->initialize($input, $output);

        $this->daemon->say('SerendipityHQ Queue Bundle Daemon', 'title');
        $this->daemon->say('Starting the Daemon...', 'infoLineNoBg');
        $this->daemon->sayProfilingInfo();
        $this->daemon->say(sprintf('My PID is "%s".', getmygid()), 'infoLineNoBg');
        $this->daemon->say('Waiting for new ScheduledJobs to process...', 'success');
        $this->daemon->say('To quit the Queues Daemon use CONTROL-C.', 'commentLineNoBg');

        // Run the Daemon
        while($this->daemon->isAlive()) {
            // Start processing the next in the queue
            $this->daemon->processNextJob();

            // Then process jobs already running
            $this->daemon->processRunningJobs();

            // Optimize memory usage
            $this->daemon->optimize();
        }

        $this->daemon->say('Entering shutdown sequence.', 'note');

        // Wait for the currently running jobs to finish
        $remainedJobs = $this->daemon->countRunningJobs();
        while($this->daemon->hasRunningJobs()) {
            // Create a progress bar to display the remaining jobs to process
            $progress = null;
            if (null !== $progress) {
                $progress = new ProgressBar($output, $remainedJobs);
                $progress->start();
            }

            // Continue to process running jobs
            $this->daemon->processRunningJobs();

            $progress->setProgress($remainedJobs - $this->daemon->countRunningJobs());

            // And wait a bit to give them the time to finish
            $this->daemon->wait();
        }

        $this->daemon->say('All done: Queue Daemon ended running. No more ScheduledJobs will be processed.', 'success');

        return 0;
    }
}
