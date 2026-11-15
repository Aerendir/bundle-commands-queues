<?php

declare(strict_types=1);

/*
 * This file is part of the Serendipity HQ Commands Queues Bundle.
 *
 * Copyright (c) Adamo Aerendir Crespi <aerendir@serendipityhq.com>.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Command;

use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Service\QueuesManager;
use SerendipityHQ\Component\ThenWhen\Strategy\LiveStrategy;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Creates some failing Jobs.
 */
#[AsCommand(name: self::NAME, description: '[INTERNAL] Generates some linked failing Jobs.')]
final class TestFailingJobsCommand extends Command
{
    /** @var string */
    public const NAME = 'queues:test:failing-jobs';

    /** @var string */
    private const QUEUE_1 = 'queue_1';

    public function __construct(private readonly QueuesManager $queuesManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDefinition(
                new InputDefinition([
                    new InputOption('id', 'id', InputOption::VALUE_REQUIRED),
                    new InputOption('trigger-error', 'te', InputOption::VALUE_OPTIONAL),
                ])
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $job1 = new Job(TestFakeCommand::NAME, '--id=1 --trigger-error=true');
        $job1->setRetryStrategy(new LiveStrategy(3))->setQueue(self::QUEUE_1);
        $this->queuesManager->schedule($job1);

        $job2 = new Job(TestFakeCommand::NAME, '--id=2 --trigger-error=true');
        $job2->setQueue(self::QUEUE_1)->addParentDependency($job1);
        $this->queuesManager->schedule($job2);

        $job3 = new Job(TestFakeCommand::NAME, '--id=3 --trigger-error=true');
        $job3->setQueue(self::QUEUE_1);
        $job2->addChildDependency($job3);
        $this->queuesManager->schedule($job3);

        return Command::SUCCESS;
    }
}
