<?php

declare(strict_types=1);

/*
 * This file is part of the SHQCommandsQueuesBundle.
 *
 * Copyright Adamo Aerendir Crespi 2017.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @author    Adamo Aerendir Crespi <hello@aerendir.me>
 * @copyright Copyright (C) 2017 Aerendir. All rights reserved.
 * @license   MIT License.
 */

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Command;

use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job;
use SerendipityHQ\Component\ThenWhen\Strategy\LiveStrategy;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Creates some failing Jobs.
 */
class TestFailingJobsCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this
            ->setName('queues:test:failing-jobs')
            ->setDescription('[INTERNAL] Generates some linked failing Jobs.')
            ->setDefinition(
                new InputDefinition([
                    new InputOption('id', 'id', InputOption::VALUE_REQUIRED),
                    new InputOption('trigger-error', 'te', InputOption::VALUE_OPTIONAL),
                ])
            );
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return bool
     */
    protected function execute(InputInterface $input, OutputInterface $output): void
    {
        $job1 = new Job('queues:test', '--id=1 --trigger-error=true');
        $job1->setRetryStrategy(new LiveStrategy(3))->setQueue('queue_1');
        $this->getContainer()->get('queues')->schedule($job1);

        $job2 = new Job('queues:test', '--id=2 --trigger-error=true');
        $job2->setQueue('queue_1')->addParentDependency($job1);
        $this->getContainer()->get('queues')->schedule($job2);

        $job3 = new Job('queues:test', '--id=3 --trigger-error=true');
        $job3->setQueue('queue_1');
        $job2->addChildDependency($job3);
        $this->getContainer()->get('queues')->schedule($job3);
    }
}
