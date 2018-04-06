<?php

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
use Doctrine\ORM\UnitOfWork;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job;
use SerendipityHQ\Bundle\ConsoleStyles\Console\Style\SerendipityHQStyle;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessBuilder;

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

    /** @var string $verbosity */
    private $verbosity;

    /**
     * @param string $kernelRootDir
     */
    public function __construct(string $kernelRootDir)
    {
        $this->kernelRootDir = $kernelRootDir;
    }

    /**
     * @param EntityManager      $entityManager
     * @param SerendipityHQStyle $ioWriter
     */
    public function initialize(EntityManager $entityManager, SerendipityHQStyle $ioWriter)
    {
        self::$entityManager = $entityManager;
        self::$ioWriter      = $ioWriter;
        $this->env           = $ioWriter->getInput()->getOption('env');
        $this->verbosity     = $ioWriter->getVerbosity();
    }

    /**
     * Handles the detach of a Job.
     *
     * IMplements a custom logic to detach Jobs linked to the detaching one.
     *
     * @param Job $job
     */
    public static function detach(Job $job)
    {
        $tree        = self::calculateJobsTree($job);
        $detached    = [];
        $notDetached = [];

        foreach ($tree as $jobInTree) {
            $jobInTree = self::$entityManager->find('SHQCommandsQueuesBundle:Job', $jobInTree);

            if (null === $jobInTree) {
                if (self::$ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                    // Add the current Job to the already detached
                    $detached[$jobInTree->getId()] = '#' . $jobInTree->getId();
                    self::$ioWriter->successLineNoBg(sprintf(
                        'Job <info-nobg>#%s</info-nobg> is not managed and so it will not has to be detached.',
                        $jobInTree->getId()
                    ));
                }
                continue;
            }

            if (false === $jobInTree->canBeDetached()) {
                if (self::$ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                    self::$ioWriter->infoLineNoBg(sprintf(
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
                self::$ioWriter->successLineNoBg(sprintf('Job <info-nobg>#%s</info-nobg> detached.', $jobInTree->getId()));
            }
            // Add the current Job to the already detached
            $detached[$jobInTree->getId()] = '#' . $jobInTree->getId();
        }

        if (self::$ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
            self::$ioWriter->infoLineNoBg(sprintf(
                'Job <success-nobg>#%s</success-nobg> and its linked Jobs detached.', $job->getId()
            ));

            // Print detached
            if (false === empty($detached)) {
                self::$ioWriter->commentLineNoBg(sprintf('Detached: %s', implode(', ', $detached)));
            }

            // Print not detached
            if (false === empty($notDetached)) {
                self::$ioWriter->commentLineNoBg(sprintf('Not Detached: %s', implode(', ', $notDetached)));
            }
        }
    }

    /**
     * Refreshes the entire tree of Jobs.
     *
     * @param Job $job
     */
    public function refreshTree(Job $job)
    {
        $jobsTree = self::calculateJobsTree($job);

        foreach ($jobsTree as $jobId) {
            $job = self::$entityManager->find(Job::class, $jobId);

            if (null !== $job && false === $job->isStatusWorking()) {
                self::$entityManager->refresh($job);
            }
        }
    }

    /**
     * @param Process $process
     *
     * @compatibility Symfony 3 and 4
     *
     * @return array
     */
    public function buildDefaultInfo(Process $process)
    {
        return [
            'output'    => $process->getOutput() . $process->getErrorOutput(),
            'exit_code' => $process->getExitCode(),
            'debug'     => [
                'exit_code_text'                  => $process->getExitCodeText(),
                'complete_command'                => $process->getCommandLine(),
                'input'                           => $process->getInput(),
                'options'                         => method_exists($process, 'getOptions') ? $process->getOptions() : 'You are using Symfony 4 and options are not available in this version.',
                'env'                             => $process->getEnv(),
                'working_directory'               => $process->getWorkingDirectory(),
                'enhanced_sigchild_compatibility' => method_exists($process, 'getEnhanceSigchildCompatibility') ? $process->getEnhanceSigchildCompatibility() : true,
                'enhanced_windows_compatibility'  => method_exists($process, 'getEnhanceWindowsCompatibility') ? $process->getEnhanceWindowsCompatibility() : true,
            ],
        ];
    }

    /**
     * @param Job $job
     *
     * @return \Symfony\Component\Process\Process
     */
    public function createJobProcess(Job $job)
    {
        $arguments = [];

        // Prepend php
        $arguments[] = 'php';

        // Add the console
        $arguments[] = $this->findConsole();

        // The command to execute
        $arguments[] = $job->getCommand();

        // Environment to use
        $arguments[] = '--env=' . $this->env;

        // Verbosity level (only if not normal = agument verbosity not set in command)
        if (OutputInterface::VERBOSITY_NORMAL !== $this->verbosity) {
            $arguments[] = $this->guessVerbosityLevel();
        }

        if ($job->isAwareOfJob()) {
            $arguments[] = '--job-id=' . $job->getId();
        }

        // The arguments of the command
        $arguments = array_merge($arguments, $job->getArguments());

        // Build the command to be run (@compatibility Symfony 3 and 4)
        return class_exists(ProcessBuilder::class, false)
            ? (new ProcessBuilder())->setArguments($arguments)->getProcess()
            : new Process($arguments);
    }

    /**
     * @param Job $job
     *
     * @return string
     */
    public static function guessJobEmState(Job $job)
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
     * @param array $tree The buil tree
     *
     * @return array
     */
    private static function calculateJobsTree(Job $job, &$tree = [])
    {
        if (null !== $job->getChildDependencies() && 0 < count($job->getChildDependencies())) {
            /** @var Job $childDependency Detach child deps * */
            foreach ($job->getChildDependencies() as $childDependency) {
                if (false === in_array($childDependency->getId(), $tree)) {
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
                if (false === in_array($parentDependency->getId(), $tree)) {
                    // Add it to the tree
                    $tree[] = $parentDependency->getId();

                    // Visit the child
                    self::calculateJobsTree($parentDependency, $tree);
                }
            }
        }

        // Detach the cancelling Job if any
        if (null !== $job->getCancelledBy() && false === in_array($job->getCancelledBy()->getId(), $tree)) {
            $tree[] = $job->getCancelledBy()->getId();

            // Visit the child
            self::calculateJobsTree($job->getCancelledBy(), $tree);
        }

        /* @var Job $retryingDependency Detach cancelled Jobs **/
        if (null !== $job->getCancelledJobs() && 0 < count($job->getCancelledJobs())) {
            foreach ($job->getCancelledJobs() as $cancelledJob) {
                if (false === in_array($cancelledJob->getId(), $tree)) {
                    // Add it to the tree
                    $tree[] = $cancelledJob->getId();

                    // Visit the child
                    self::calculateJobsTree($cancelledJob, $tree);
                }
            }
        }

        // Detach the retried Job
        if (null !== $job->getRetryOf() && false === in_array($job->getRetryOf()->getId(), $tree)) {
            $tree[] = $job->getRetryOf()->getId();

            // Visit the child
            self::calculateJobsTree($job->getRetryOf(), $tree);
        }

        // And the first retried one
        if (null !== $job->getFirstRetriedJob() && false === in_array($job->getFirstRetriedJob()->getId(), $tree)) {
            $tree[] = $job->getFirstRetriedJob()->getId();

            // Visit the child
            self::calculateJobsTree($job->getFirstRetriedJob(), $tree);
        }

        // The retrying one if any
        if (null !== $job->getRetriedBy() && false === in_array($job->getRetriedBy()->getId(), $tree)) {
            $tree[] = $job->getRetriedBy()->getId();

            // Visit the child
            self::calculateJobsTree($job->getRetriedBy(), $tree);
        }

        // And all the retrying Jobs
        /* @var Job $retryingDependency Detach retryingDeps **/
        if (null !== $job->getRetryingJobs() && 0 < count($job->getRetryingJobs())) {
            foreach ($job->getRetryingJobs() as $retryingJob) {
                if (false === in_array($retryingJob->getId(), $tree)) {
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
     * @throws \RuntimeException if the console file cannot be found
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

        throw new \RuntimeException('Unable to find the console file. You should check your Symfony installation. The console file should be in /app/ folder or in /bin/ folder.');
    }

    /**
     * @return string|null
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
