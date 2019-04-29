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

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Service;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\ORMInvalidArgumentException;
use Doctrine\ORM\TransactionRequiredException;
use Doctrine\ORM\UnitOfWork;
use RuntimeException;
use Safe\Exceptions\StringsException;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job;
use SerendipityHQ\Bundle\ConsoleStyles\Console\Style\SerendipityHQStyle;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * Manages the Jobs.
 */
class JobsManager
{
    /** @var EntityManager $entityManager */
    private static $entityManager;

    /** @var SerendipityHQStyle $ioWriter */
    private static $ioWriter;

    /** @var string $env */
    private $env;

    /** @var string $kernelRootDir */
    private $kernelRootDir;

    /** @var int $verbosity */
    private $verbosity;

    /**
     * @param string $kernelRootDir
     */
    public function __construct(string $kernelRootDir)
    {
        $this->kernelRootDir = $kernelRootDir;
    }

    /**
     * @param EntityManagerInterface $entityManager
     * @param SerendipityHQStyle     $ioWriter
     */
    public function initialize(EntityManagerInterface $entityManager, SerendipityHQStyle $ioWriter): void
    {
        // This is to make static analysis pass
        if ( ! $entityManager instanceof EntityManager) {
            throw new \RuntimeException('You need to pass an EntityManager instance.');
        }

        $env                 = $ioWriter->getInput()->getOption('env');
        self::$entityManager = $entityManager;
        self::$ioWriter      = $ioWriter;
        $this->env           = is_string($env) ? $env : 'dev';
        $this->verbosity     = $ioWriter->getVerbosity();
    }

    /**
     * Handles the detach of a Job.
     *
     * IMplements a custom logic to detach Jobs linked to the detaching one.
     *
     * @param Job $job
     *
     * @throws OptimisticLockException
     * @throws ORMInvalidArgumentException
     * @throws TransactionRequiredException
     * @throws ORMException
     * @throws StringsException
     */
    public static function detach(Job $job): void
    {
        $tree        = self::calculateJobsTree($job);
        $detached    = [];
        $notDetached = [];

        foreach ($tree as $jobInTreeId) {
            /** @var Job|null $jobInTree */
            $jobInTree = self::$entityManager->find(Job::class, $jobInTreeId);

            if (null === $jobInTree) {
                if (self::$ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                    // Add the current Job to the already detached
                    $detached[$jobInTreeId] = '#' . $jobInTreeId;
                    self::$ioWriter->successLineNoBg(\Safe\sprintf(
                        "Job <info-nobg>#%s</info-nobg> is not managed and so it hasn't been detached.",
                        $jobInTreeId
                    ));
                }
                continue;
            }

            if (false === $jobInTree->canBeDetached()) {
                if (self::$ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                    self::$ioWriter->infoLineNoBg(\Safe\sprintf(
                        'Skipping detaching Job <success-nobg>#%s</success-nobg> [Em: %s] because <success-nobg>%s</success-nobg>.',
                        $jobInTree->getId(), self::guessJobEmState($jobInTree), $jobInTree->getCannotBeDetachedBecause()
                    ));
                }

                $notDetached[$jobInTree->getId()] = '#' . $jobInTree->getId();

                continue;
            }

            // Now detach the Job
            self::$entityManager->detach($jobInTree);
            if (self::$ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                self::$ioWriter->successLineNoBg(\Safe\sprintf('Job <info-nobg>#%s</info-nobg> detached.', $jobInTree->getId()));
            }
            // Add the current Job to the already detached
            $jobInTreeId            = $jobInTree->getId();
            $detached[$jobInTreeId] = '#' . $jobInTreeId;
        }

        if (self::$ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
            self::$ioWriter->infoLineNoBg(\Safe\sprintf(
                'Job <success-nobg>#%s</success-nobg> and its linked Jobs detached.', $job->getId()
            ));

            // Print detached
            if (false === empty($detached)) {
                self::$ioWriter->commentLineNoBg(\Safe\sprintf('Detached: %s', implode(', ', $detached)));
            }

            // Print not detached
            if (false === empty($notDetached)) {
                self::$ioWriter->commentLineNoBg(\Safe\sprintf('Not Detached: %s', implode(', ', $notDetached)));
            }
        }
    }

    /**
     * @param Job $removingJob
     *
     * @throws ORMException
     */
    public function remove(Job $removingJob): void
    {
        // When the Job is being removed from the database, it has to first
        // be removed from its parent dependencies to avoid foreign key errors.
        /** @var Job $parentDependency */
        foreach ($removingJob->getParentDependencies() as $parentDependency) {
            $parentDependency->removeChildDependency($removingJob);
        }

        // If this is a cancelling Job, we need to first remove the association
        // with the cancelled Jobs
        /** @var Job $cancelledJob */
        foreach ($removingJob->getCancelledJobs() as $cancelledJob) {
            $cancelledJob->removeCancelledBy();
        }

        $removingJob->removeFirstRetriedJob();
        $removingJob->removeRetriedBy();
        $removingJob->removeRetryOf();

        self::$entityManager->remove($removingJob);
    }

    /**
     * Refreshes the entire tree of Jobs.
     *
     * This has to be used to be sure that all the Job that are
     * in any way linked with the given Job are managed by the Entitymanager
     * to avoid errors about new not managed entities.
     *
     * @param Job $job
     *
     * @throws OptimisticLockException
     * @throws ORMInvalidArgumentException
     * @throws TransactionRequiredException
     * @throws ORMException
     */
    public function refreshTree(Job $job): void
    {
        $jobsTree = self::calculateJobsTree($job);

        foreach ($jobsTree as $jobId) {
            $jobInTree = self::$entityManager->find(Job::class, $jobId);

            if ($jobInTree instanceof Job && false === $jobInTree->isStatusWorking()) {
                self::$entityManager->refresh($jobInTree);
            }
        }
    }

    /**
     * @param Process $process
     *
     * @return array
     */
    public function buildDefaultInfo(Process $process): array
    {
        return [
            'output'    => $process->getOutput() . $process->getErrorOutput(),
            'exit_code' => $process->getExitCode(),
            'debug'     => [
                'exit_code_text'    => $process->getExitCodeText(),
                'complete_command'  => $process->getCommandLine(),
                'input'             => $process->getInput(),
                'env'               => $process->getEnv(),
                'working_directory' => $process->getWorkingDirectory(),
            ],
        ];
    }

    /**
     * @param Job  $job
     * @param bool $allowProd
     *
     * @return Process
     */
    public function createJobProcess(Job $job, bool $allowProd): Process
    {
        $arguments = [];

        // Prepend php
        $arguments[] = 'php';

        // Add the console
        $arguments[] = $this->findConsole();

        // The command to execute
        $arguments[] = $job->getCommand();

        // Decide the environment to use
        $env         = $allowProd ? $this->env : 'dev';
        $arguments[] = '--env=' . $env;

        // Verbosity level (only if not normal = agument verbosity not set in command)
        if (OutputInterface::VERBOSITY_NORMAL !== $this->verbosity) {
            $arguments[] = $this->guessVerbosityLevel();
        }

        if ($job->isAwareOfJob()) {
            $arguments[] = '--job-id=' . $job->getId();
        }

        // The arguments of the command
        $arguments = array_merge($arguments, $job->getArguments());

        return new Process($arguments);
    }

    /**
     * @param Job $job
     *
     * @return string
     */
    public static function guessJobEmState(Job $job): string
    {
        switch (self::$entityManager->getUnitOfWork()->getEntityState($job)) {
            case UnitOfWork::STATE_DETACHED:
                $state = 'Detached';
                break;
            case UnitOfWork::STATE_NEW:
                $state = 'New';
                break;
            case UnitOfWork::STATE_MANAGED:
                $state = 'Managed';
                break;
            case UnitOfWork::STATE_REMOVED:
                $state = 'Removed';
                break;
            default:
                $state = 'Unknown';
        }

        return $state;
    }

    /**
     * This will build the entire Jobs tree.
     *
     * It adds to the tree all childs and parents, and all other linked Jobs to the one given and its childs.
     *
     * @param Job   $job
     * @param array $tree The build tree
     *
     * @return int[]
     */
    private static function calculateJobsTree(Job $job, array &$tree = []): array
    {
        if (null !== $job->getChildDependencies() && 0 < $job->getChildDependencies()->count()) {
            /** @var Job $childDependency Detach child deps * */
            foreach ($job->getChildDependencies() as $childDependency) {
                if (false === in_array($childDependency->getId(), $tree, true)) {
                    // Add it to the tree
                    $tree[] = $childDependency->getId();

                    // Visit the child
                    self::calculateJobsTree($childDependency, $tree);
                }
            }
        }

        if (null !== $job->getParentDependencies() && 0 < count($job->getParentDependencies())) {
            /** @var Job $parentDependency Detach parend deps * */
            foreach ($job->getParentDependencies() as $parentDependency) {
                if (false === in_array($parentDependency->getId(), $tree, true)) {
                    // Add it to the tree
                    $tree[] = $parentDependency->getId();

                    // Visit the child
                    self::calculateJobsTree($parentDependency, $tree);
                }
            }
        }

        // Detach the cancelling Job if any
        if (null !== $job->getCancelledBy() && false === in_array($job->getCancelledBy()->getId(), $tree, true)) {
            $tree[] = $job->getCancelledBy()->getId();

            // Visit the child
            self::calculateJobsTree($job->getCancelledBy(), $tree);
        }

        /* @var Job $retryingDependency Detach cancelled Jobs **/
        if (0 < $job->getCancelledJobs()->count()) {
            foreach ($job->getCancelledJobs() as $cancelledJob) {
                if (false === in_array($cancelledJob->getId(), $tree, true)) {
                    // Add it to the tree
                    $tree[] = $cancelledJob->getId();

                    // Visit the child
                    self::calculateJobsTree($cancelledJob, $tree);
                }
            }
        }

        // Detach the retried Job
        if (null !== $job->getRetryOf() && false === in_array($job->getRetryOf()->getId(), $tree, true)) {
            $tree[] = $job->getRetryOf()->getId();

            // Visit the child
            self::calculateJobsTree($job->getRetryOf(), $tree);
        }

        // And the first retried one
        if (null !== $job->getFirstRetriedJob() && false === in_array($job->getFirstRetriedJob()->getId(), $tree, true)) {
            $tree[] = $job->getFirstRetriedJob()->getId();

            // Visit the child
            self::calculateJobsTree($job->getFirstRetriedJob(), $tree);
        }

        // The retrying one if any
        if (null !== $job->getRetriedBy() && false === in_array($job->getRetriedBy()->getId(), $tree, true)) {
            $tree[] = $job->getRetriedBy()->getId();

            // Visit the child
            self::calculateJobsTree($job->getRetriedBy(), $tree);
        }

        // And all the retrying Jobs
        /* @var Job $retryingDependency Detach retryingDeps **/
        if (null !== $job->getRetryingJobs() && 0 < count($job->getRetryingJobs())) {
            foreach ($job->getRetryingJobs() as $retryingJob) {
                if (false === in_array($retryingJob->getId(), $tree, true)) {
                    // Add it to the tree
                    $tree[] = $retryingJob->getId();

                    // Visit the child
                    self::calculateJobsTree($retryingJob, $tree);
                }
            }
        }

        return $tree;
    }

    /**
     * Finds the path to the console file.
     *
     * @throws RuntimeException if the console file cannot be found
     *
     * @return string
     */
    private function findConsole(): string
    {
        if (file_exists($this->kernelRootDir . '/console')) {
            return $this->kernelRootDir . '/console';
        }

        if (file_exists($this->kernelRootDir . '/../bin/console')) {
            return $this->kernelRootDir . '/../bin/console';
        }

        throw new RuntimeException('Unable to find the console file. You should check your Symfony installation. The console file should be in /app/ folder or in /bin/ folder.');
    }

    /**
     * @return string
     */
    private function guessVerbosityLevel(): string
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
