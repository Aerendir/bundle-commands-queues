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

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Util;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMInvalidArgumentException;
use Doctrine\Persistence\Proxy;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Daemon;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job;
use SerendipityHQ\Bundle\ConsoleStyles\Console\Style\SerendipityHQStyle;
use SerendipityHQ\Component\ThenWhen\Strategy\LiveStrategy;
use SerendipityHQ\Component\ThenWhen\ThenWhen;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Changes the status of Jobs during their execution attaching to them execution info.
 */
final class JobsMarker
{
    /** @var string */
    private const DEBUG = 'debug';

    /** @var EntityManager $entityManager */
    private static EntityManagerInterface $entityManager;

    private static ?SerendipityHQStyle $ioWriter = null;

    public function __construct(EntityManagerInterface $entityManager)
    {
        // This is to make static analysis pass
        if ( ! $entityManager instanceof EntityManager) {
            throw new \RuntimeException('You need to pass an EntityManager instance.');
        }

        self::$entityManager = $entityManager;
    }

    public function setIoWriter(SerendipityHQStyle $ioWriter): void
    {
        self::$ioWriter = $ioWriter;
    }

    public static function createReflectedJob(Job $job): \ReflectionClass
    {
        $reflectedClass = new \ReflectionClass($job);

        // If the $job is a Doctrine proxy...
        if ($job instanceof Proxy) {
            // ... This gets the real object, the one that the Proxy extends
            $reflectedClass = $reflectedClass->getParentClass();

            if ( ! $reflectedClass instanceof \ReflectionClass) {
                throw new \RuntimeException("Impossible to get the reflected Job class from the Doctrine's proxy.");
            }
        }

        return $reflectedClass;
    }

    public function markJobAsAborted(Job $job, array $info, Daemon $daemon): void
    {
        $this->markJobAsClosed($job, Job::STATUS_ABORTED, $info, $daemon);
    }

    public function markJobAsCancelled(Job $job, array $info): void
    {
        $this->markJobAsClosed($job, Job::STATUS_CANCELLED, $info);
    }

    public function markJobAsFailed(Job $job, array $info): void
    {
        // If this Job is a retry of another one, mark also the retried as finished
        if ($job->isTypeRetrying()) {
            $retryOf = $job->getRetryOf();

            if ( ! $retryOf instanceof Job) {
                throw new \RuntimeException('The job of which this is a retry is not set.');
            }

            $this->markParentsAsRetryFailed($retryOf);
        }

        $this->markJobAsClosed($job, Job::STATUS_FAILED, $info);
    }

    public function markJobAsFinished(Job $job, array $info): void
    {
        // If this Job is a retry of another one, mark also the retried as finished
        if ($job->isTypeRetrying()) {
            $retryOf = $job->getRetryOf();

            if ( ! $retryOf instanceof Job) {
                throw new \RuntimeException('The job of which this is a retry is not set.');
            }

            $this->markParentsAsRetrySucceeded($retryOf);
        }

        $this->markJobAsClosed($job, Job::STATUS_SUCCEEDED, $info);
    }

    public function markJobAsPending(Job $job, array $info, Daemon $daemon): void
    {
        $this->markJob($job, Job::STATUS_PENDING, $info, $daemon);
    }

    /**
     * @return Job the created retry Job
     */
    public function markFailedJobAsRetried(Job $failedJob, array $info): Job
    {
        // Create a new retry Job
        $retryingJob = $failedJob->createRetryForFailed();

        return $this->markJobAsRetried($failedJob, $retryingJob, $info);
    }

    /**
     * @return Job the created retry Job
     */
    public function markStaleJobAsRetried(Job $staleJob, array $info): Job
    {
        // Create a new retry Job
        $retryingJob = $staleJob->createRetryForStale();

        return $this->markJobAsRetried($staleJob, $retryingJob, $info);
    }

    public function markJobAsRunning(Job $job): void
    {
        $this->markJob($job, Job::STATUS_RUNNING);
    }

    private function markJobAsClosed(Job $job, string $status, array $info, ?Daemon $daemon = null): void
    {
        $info['closed_at'] = new \DateTime();
        $this->markJob($job, $status, $info, $daemon);
    }

    private function markJobAsRetried(Job $retriedJob, Job $retryingJob, array $info): Job
    {
        $this->updateChildDependencies($retryingJob);

        self::$entityManager->persist($retryingJob);

        $this->markJobAsClosed($retriedJob, Job::STATUS_RETRIED, $info);

        return $retryingJob;
    }

    private function markParentsAsRetryFailed(Job $retriedJob): void
    {
        if ($retriedJob->isTypeRetrying()) {
            $retryOf = $retriedJob->getRetryOf();

            if ( ! $retryOf instanceof Job) {
                throw new \RuntimeException('The job of which this is a retry is not set.');
            }

            $this->markParentsAsRetryFailed($retryOf);
        }

        $this->markJob($retriedJob, Job::STATUS_RETRY_FAILED);
    }

    private function markParentsAsRetrySucceeded(Job $retriedJob): void
    {
        if ($retriedJob->isTypeRetrying()) {
            $retryOf = $retriedJob->getRetryOf();

            if ( ! $retryOf instanceof Job) {
                throw new \RuntimeException('The job of which this is a retry is not set.');
            }

            $this->markParentsAsRetrySucceeded($retryOf);
        }

        $this->markJob($retriedJob, Job::STATUS_RETRY_SUCCEEDED);
    }

    private function markJob(Job $job, string $status, array $info = [], ?Daemon $daemon = null): void
    {
        $oldStatus = $job->getStatus();
        self::updateJob($job, $status, $info, $daemon);

        $ioWriter        = self::$ioWriter;
        $tryAgainBuilder = ThenWhen::createRetryStrategyBuilder();
        $tryAgainBuilder
            ->setStrategyForException([
                ORMInvalidArgumentException::class, \InvalidArgumentException::class,
            ], new LiveStrategy(100))
            // May happen that a Job is detached to keep the memory consumption low but then it is required to flush the
            // current Job.
            // Here we refresh the Job to manage again all the required Jobs.
            // This is a required trade-off between the memory consumption and the queries to the database: we chose to
            // sacrifice queries to the databse in favor of a minor memory consumption.
            ->setMiddleHandlerForException([
                ORMInvalidArgumentException::class, \InvalidArgumentException::class,
            ], static function (\Exception $e) use ($job, $status, $info, $daemon, $ioWriter): void {
                if (
                    ! $e instanceof ORMInvalidArgumentException
                    && $e instanceof \InvalidArgumentException
                    && ! str_contains($e->getMessage(), 'Entity has to be managed or scheduled for removal for single computation')
                ) {
                    throw $e;
                }

                if ($ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                    $ioWriter->warningLineNoBg(sprintf('Refreshing Job #%s@%s because for Doctrine it is a new found entity ("%s").', $job->getId(), $job->getQueue(), $e->getMessage()));
                }

                self::$entityManager->refresh($job);

                self::updateJob($job, $status, $info, $daemon);
            })
            ->setFinalHandlerForException([
                ORMInvalidArgumentException::class, \InvalidArgumentException::class,
            ], static function (\Exception $e) use ($job, $oldStatus, $ioWriter): void {
                self::$ioWriter->error(sprintf('Error trying to flush Job #%s (%s => %s).', $job->getId(), $oldStatus, $job->getStatus()));
                if ($ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                    Profiler::printUnitOfWork();
                }

                throw $e;
            });

        $tryAgainBuilder->initializeRetryStrategy()
            ->try(static function () use ($job): void {
                /*
                 * Flush now to be sure editings aren't cleared during optimizations.
                 *
                 * We flush the single Jobs to don't affect the others that may be still processing.
                 */
                if ($job->isTypeRetried()) {
                    // Flush the new retrying Job
                    self::$entityManager->flush();
                }

                // Flush the original Job
                self::$entityManager->flush();
            });
    }

    private static function updateJob(Job $job, string $status, array $info = [], ?Daemon $daemon = null): void
    {
        $reflectedClass = self::createReflectedJob($job);

        // First of all set the current status
        $reflectedProperty = $reflectedClass->getProperty('status');
        $reflectedProperty->setValue($job, $status);

        // If the Job is cancelled, set the reason
        if (Job::STATUS_CANCELLED === $status) {
            $reflectedProperty = $reflectedClass->getProperty('cancellationReason');
            $reflectedProperty->setValue($job, $info[self::DEBUG]['cancellation_reason']);
        }

        // Then set the processing Daemon
        if ($daemon instanceof Daemon) {
            $reflectedProperty = $reflectedClass->getProperty('processedBy');
            $reflectedProperty->setValue($job, $daemon);

            self::$entityManager->persist($daemon);
        }

        // Now set the other info
        foreach ($info as $property => $value) {
            $reflectedProperty = match ($property) {
                'cancelled_by'        => $reflectedClass->getProperty('cancelledBy'),
                'closed_at'           => $reflectedClass->getProperty('closedAt'),
                self::DEBUG           => $reflectedClass->getProperty(self::DEBUG),
                'output'              => $reflectedClass->getProperty('output'),
                'exit_code'           => $reflectedClass->getProperty('exitCode'),
                'started_at'          => $reflectedClass->getProperty('startedAt'),
                'cancellation_reason' => $reflectedClass->getProperty('cancellationReason'),
                default               => throw new \RuntimeException(sprintf('The property %s is not managed. Manage it or verify its spelling is correct.', $property)),
            };
            $reflectedProperty->setValue($job, $value);
        }
    }

    private function updateChildDependencies(Job $retryJob): void
    {
        $retryOf = $retryJob->getRetryOf();

        if ( ! $retryOf instanceof Job) {
            throw new \RuntimeException('The retry of Job is not set.');
        }

        // Set the retried Job as parent dependency of the child dependencies of this retrying Job
        foreach ($retryOf->getChildDependencies() as $childDependency) {
            // Add child dependencies of the retried Job
            $retryJob->addChildDependency($childDependency);
        }
    }
}
