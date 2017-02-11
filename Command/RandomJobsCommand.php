<?php

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Command;

use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job;
use SerendipityHQ\Bundle\ConsoleStyles\Console\Formatter\SerendipityHQOutputFormatter;
use SerendipityHQ\Bundle\ConsoleStyles\Console\Style\SerendipityHQStyle;
use SerendipityHQ\Component\ThenWhen\Strategy\ConstantStrategy;
use SerendipityHQ\Component\ThenWhen\Strategy\ExponentialStrategy;
use SerendipityHQ\Component\ThenWhen\Strategy\LinearStrategy;
use SerendipityHQ\Component\ThenWhen\Strategy\LiveStrategy;
use SerendipityHQ\Component\ThenWhen\Strategy\NeverRetryStrategy;
use SerendipityHQ\Component\ThenWhen\Strategy\StrategyInterface;
use SerendipityHQ\Component\ThenWhen\Strategy\TimeFixedStrategy;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class SynchOrdersCommand.
 */
class RandomJobsCommand extends AbstractQueuesCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('queues:random-jobs')
            ->setDescription('[INTERNAL] Generates random Jobs to test SHQCommandsQueuesBundle.')
            ->setDefinition(
                new InputDefinition([
                    new InputArgument('how_many', InputArgument::OPTIONAL, 'How many random Jobs would you like to create?', 10),
                    new InputOption('batch', null, InputOption::VALUE_OPTIONAL, 'The number of Jobs in a batch.', 100),
                    new InputOption('no-future-jobs', null),
                    new InputOption('retry-strategies', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'The allowed retry strategies', ['constant', 'exponential', 'linear', 'live', 'never_retry', 'time_fixed'])
                ])
            );
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

        $this->getIoWriter()->title('SerendipityHQ Queue Bundle Daemon');
        $this->getIoWriter()->info(sprintf('Starting generating %s ranoom jobs...', $input->getArgument('how_many')));

        // Generate the random jobs
        $progress = new ProgressBar($output, $input->getArgument('how_many'));
        $progress->setFormat('<success-nobg>%current%</success-nobg>/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%');
        $progress->start();

        $progress->setRedrawFrequency($input->getOption('batch'));

        $jobs = [];
        for ($i = 0; $i < $input->getArgument('how_many'); $i++) {
            // First: we create a Job to push to the queue
            $arguments = '--id='.($i+1);
            $scheduledJob = new Job('queues:test', $arguments);

            // Set a random retry strategy
            if (0 < count($input->getOption('retry-strategies'))) {
                $scheduledJob->setRetryStrategy($this->getRandomRetryStrategy($input->getOption('retry-strategies')));
            }

            // Decide if this will be executed in the future
            if (false === $input->getOption('no-future-jobs')) {
                $condition = rand(0, 10);
                if (7 <= $condition) {
                    $days = rand(1, 10);
                    $future = new \DateTime();
                    $future->modify('+' . $days . ' day');
                    $scheduledJob->setExecuteAfterTime($future);
                }
            }

            // Decide if this has a dependency on another job
            $condition = rand(0, 10);
            // Be sure there is at least one already created Job!!!
            if (7 <= $condition && 0 < count($jobs)) {
                // Decide how many dependencies it has
                $howMany = rand(1, count($jobs) - 1);

                for ($ii = 0; $ii <= $howMany; $ii++) {
                    $parentJob = rand(0, count($jobs) - 1);
                    $scheduledJob->addParentDependency($jobs[$parentJob]);
                }
            }

            $this->getContainer()->get('doctrine')->getManager()->persist($scheduledJob);
            $jobs[] = $scheduledJob;

            if ($i % $input->getOption('batch') === 0) {
                $this->getContainer()->get('doctrine')->getManager()->flush();
                $jobs = [];
                $this->getContainer()->get('doctrine')->getManager()->clear();
            }

            $progress->advance();
        }

        $this->getContainer()->get('doctrine')->getManager()->flush();
        $progress->finish();

        $this->getIoWriter()->write("\n\n");
        $this->getIoWriter()->success(sprintf('All done: %s random jobs generated!', $input->getArgument('how_many')));

        return 0;
    }

    /**
     * @param array $strategies
     *
     * @return StrategyInterface
     */
    private function getRandomRetryStrategy($strategies) : StrategyInterface
    {
        // Pick a random strategy
        $strategy = $strategies[rand(0, count($strategies) - 1)];
        $maxAttempts = rand(1,3);
        $incrementBy = rand(1,10);
        $timeUnit = StrategyInterface::TIME_UNIT_SECONDS;//$this->getRandomTimeUnit();

        switch ($strategy) {
            case 'constant':
                return new ConstantStrategy($maxAttempts, $incrementBy, $timeUnit);
                break;
            case 'exponential':
                $exponentialBase = rand(2,5);
                return new ExponentialStrategy($maxAttempts, $incrementBy, $timeUnit, $exponentialBase);
                break;
            case 'linear':
                return new LinearStrategy($maxAttempts, $incrementBy, $timeUnit);
                break;
            case 'live':
                return new LiveStrategy($maxAttempts);
                break;
            case 'time_fixed':
                // Sum $maxAttempts and $incrementBy to be sure the time window is sufficiently large
                return new TimeFixedStrategy($maxAttempts, $maxAttempts + $incrementBy, $timeUnit);
                break;
            case 'never_retry':
            default:
                return new NeverRetryStrategy();
                break;
        }
    }
}
