<?php

namespace SerendipityHQ\Bundle\QueuesBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to manage the queue.
 */
class QueueRunCommand extends ContainerAwareCommand
{
    /** @var  string $env */
    private $env;

    /** @var  OutputInterface $output */
    private $output;

    /** @var  bool $pcntlLoaded */
    private $pcntlLoaded;

    /**
     * @var  bool $shutdown If this is true, the process will be shutdown. This will be true when a PCNTL SIGTERM
     *                      signal is intercepted.
     */
    private $shutdown;

    /** @var  bool $verbose */
    private $verbose;

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
        $this->env = $input->getOption('env');
        $this->output = $output;
        $this->verbose = $input->getOption('verbose');

        // Start the Queue process
        if ($this->verbose) {
            $output->writeln('Starting Queue.');
        }

        // Setup pcntl signals so it is possible to manage process
        $this->setupPcntlSignals();

        $this->processQueue();
    }

    /**
     * Starts the daemon that listens for new ScheduledJobs.
     */
    private function processQueue()
    {
        $i = 0;
        while(true) {
            if ($this->pcntlLoaded) {
                pcntl_signal_dispatch();
            }

            if ($this->shutdown) {
                break;
            }

            $this->output->writeln(sprintf('Cycle %s.', $i));
            $i++;
        }

        $this->output->writeln('Entering shutdown sequence.');
        $this->output->writeln('All done: process is shutdown.');
    }

    private function setupPcntlSignals()
    {
        $this->pcntlLoaded = extension_loaded('pcntl');
        $message = 'PCNTL extension is not loaded. Signals cannot be processd.';

        // If the PCNTL extension is loaded...
        if ($this->pcntlLoaded) {
            pcntl_signal(SIGTERM, $this->getSignalsHandler());
            pcntl_signal(SIGINT, $this->getSignalsHandler());

            $message = 'PCNTL is available: signals will be processed';
        }

        if ($this->verbose) {
            $this->output->writeln($message);
        }
    }

    /**
     * Return the closure that manages the PCNTL signals.
     * @return \Closure
     */
    private function getSignalsHandler()
    {
        return function($signo) {
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

            if ($this->verbose) {
                $this->output->writeln(sprintf('%s signal received.', $signal));
            }
        };
    }
}
