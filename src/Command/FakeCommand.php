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

use Exception;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A Fake command to use as fake Job.
 */
class FakeCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this
            ->setName('queues:test:fake')
            ->setDescription('[INTERNAL] A Job to test the behaviors of SHQCommandsQueuesBundle. Returns randomly exceptions, and false or true results.')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'The ID of the Job.')
            ->addOption('trigger-error', 'te', InputOption::VALUE_NONE, 'If passed, triggers some errors to see how the queue deals with them.');
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @throws Exception
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getOption('id');
        if (false === is_numeric($id)) {
            throw new InvalidArgumentException('The passed ID is not valid.');
        }

        $triggerError = null === $input->getOption('trigger-error') ? random_int(0, 10) : 1;
        $duration     = random_int(10, 10000);
        // Ok, all gone well (fingers crossed? :P )
        $output->writeln([
            'Hello!',
            "I'm TestQueue #" . $id . ', a command to test the Queue Daemon.',
            'During my execution I will trigger some random conditions to show you how Queue Daemon will',
            'deals with them.',
            '',
            'The total duration of this script will be of about "' . $duration . '" seconds.',
        ]);

        // If the rand doesn't return a number divisible by two (is just a random condition)
        if (0 !== $triggerError % 2) {
            // ... Randomly throw an exception
            throw new RuntimeException("I've just decided to throw a nice exception! Ah ah ah ah!");
        }

        // If the rand doesn't return a number divisible by two (is just a random condition)
        if (0 !== random_int(0, 10) % 2) {
            $output->writeln('Mmm, I think I will randomly return false!');
            // ... Randomly return false
            return 1;
        }

        // Emulate a duration to execute the command
        $rand = random_int(0, 10);
        $output->writeln(\Safe\sprintf("I'm so tired... I will sleep for %s seconds... Good bye, see you soon! :)", $rand));
        \Safe\sleep($rand);

        // Ok, all gone well (fingers crossed? :P )
        $output->writeln('Hello! I just woke up! :) ... Finito.');

        return 0;
    }
}
