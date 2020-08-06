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

use DateTime;
use Doctrine\Common\Persistence\Proxy;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\ORMInvalidArgumentException;
use Exception;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use RuntimeException;
use Safe\Exceptions\ArrayException;
use Safe\Exceptions\StringsException;
use function Safe\sprintf;
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
    /** @var EntityManager $entityManager */
    private static $entityManager;

    /** @var SerendipityHQStyle $ioWriter */
    private static $ioWriter;
    /**
     * @var string
     */
    private const DEBUG = 'debug';

    /**
     * @param EntityManagerInterface $entityManager
     */
    public function __construct(EntityManagerInterface $entityManager)
    {
        // This is to make static analysis pass
        if ( ! $entityManager instanceof EntityManager) {
            throw new RuntimeException('You need to pass an EntityManager instance.');
        }

        self::$entityManager = $entityManager;
    }

    /**
     * @param SerendipityHQStyle $ioWriter
     */
    public function setIoWriter(SerendipityHQStyle $ioWriter): void
    {
        self::$ioWriter = $ioWriter;
    }

    /**
     * @param Job $job
     *
     * @throws ReflectionException
     *
     * @return ReflectionClass
     */
    public static function createReflectedJob(Job $job): ReflectionClass
    {
        $reflectedClass = new ReflectionClass($job);

        // If the $job is a Doctrine proxy...
        if ($job instanceof Proxy) {
            // ... This gets the real object, the one that the Proxy extends
            $reflectedClass = $reflectedClass->getParentClass();

            if ( ! $reflectedClass instanceof ReflectionClass) {
                throw new RuntimeException("Impossible to get the reflected Job class from the Doctrine's proxy.");
            }
        }

        return $reflectedClass;
    }

    /**
     * @param Job    $job
     * @param array  $info
     * @param Daemon $daemon
     *
     * @throws Exception
     */
    public function markJobAsAborted(Job $job, array $info, Daemon $daemon): void
    {
        $this->markJobAsClosed($job, Job::STATUS_ABORTED, $info, $daemon);
    }

    /**
     * @param Job   $job
     * @param array $info
     *
     * @throws Exception
     */
    public function markJobAsCancelled(Job $job, array $info): void
    {
        $this->markJobAsClosed($job, Job::STATUS_CANCELLED, $info);
    }

    /**
     * @param Job   $job
     * @param array $info
     *
     * @throws Exception
     */
    public function markJobAsFailed(Job $job, array $info): void
    {
        // If this Job is a retry of another one, mark also the retried as finished
        if ($job->isTypeRetrying()) {
            $retryOf = $job->getRetryOf();

            if ( ! $retryOf instanceof Job) {
                throw new RuntimeException('The job of which this is a retry is not set.');
            }

            $this->markParentsAsRetryFailed($retryOf);
        }

        $this->markJobAsClosed($job, Job::STATUS_FAILED, $info);
    }

    /**
     * @param Job   $job
     * @param array $info
     *
     * @throws Exception
     */
    public function markJobAsFinished(Job $job, array $info): void
    {
        // If this Job is a retry of another one, mark also the retried as finished
        if ($job->isTypeRetrying()) {
            $retryOf = $job->getRetryOf();

            if ( ! $retryOf instanceof Job) {
                throw new RuntimeException('The job of which this is a retry is not set.');
            }

            $this->markParentsAsRetrySucceeded($retryOf);
        }

        $this->markJobAsClosed($job, Job::STATUS_SUCCEEDED, $info);
    }

    /**
     * @param Job    $job
     * @param array  $info
     * @param Daemon $daemon
     *
     * @throws Exception
     */
    public function markJobAsPending(Job $job, array $info, Daemon $daemon): void
    {
        $this->markJob($job, Job::STATUS_PENDING, $info, $daemon);
    }

    /**
     * @param Job   $failedJob
     * @param array $info
     *
     * @throws ArrayException
     * @throws ORMException
     * @throws StringsException
     *
     * @return Job the created retry Job
     */
    public function markFailedJobAsRetried(Job $failedJob, array $info): Job
    {
        // Create a new retry Job
        $retryingJob = $failedJob->createRetryForFailed();

        return $this->markJobAsRetried($failedJob, $retryingJob, $info);
    }

    /**
     * @param Job   $staleJob
     * @param array $info
     *
     * @throws ORMException
     * @throws StringsException
     * @throws ArrayException
     *
     * @return Job the created retry Job
     */
    public function markStaleJobAsRetried(Job $staleJob, array $info): Job
    {
        // Create a new retry Job
        $retryingJob = $staleJob->createRetryForStale();

        return $this->markJobAsRetried($staleJob, $retryingJob, $info);
    }

    /**
     * @param Job $job
     *
     * @throws Exception
     */
    public function markJobAsRunning(Job $job): void
    {
        $this->markJob($job, Job::STATUS_RUNNING);
    }

    /**
     * @param Job         $job
     * @param string      $status
     * @param array       $info
     * @param Daemon|null $daemon
     *
     * @throws Exception
     */
    private function markJobAsClosed(Job $job, string $status, array $info, Daemon $daemon = null): void
    {
        $info['closed_at'] = new DateTime();
        $this->markJob($job, $status, $info, $daemon);
    }

    /**
     * @param Job   $retriedJob
     * @param Job   $retryingJob
     * @param array $info
     *
     * @throws ORMException
     * @throws StringsException
     * @throws Exception
     *
     * @return Job
     */
    private function markJobAsRetried(Job $retriedJob, Job $retryingJob, array $info): Job
    {
        $this->updateChildDependencies($retryingJob);

        self::$entityManager->persist($retryingJob);

        $this->markJobAsClosed($retriedJob, Job::STATUS_RETRIED, $info);

        return $retryingJob;
    }

    /**
     * @param Job $retriedJob
     *
     * @throws Exception
     */
    private function markParentsAsRetryFailed(Job $retriedJob): void
    {
        if ($retriedJob->isTypeRetrying()) {
            $retryOf = $retriedJob->getRetryOf();

            if ( ! $retryOf instanceof Job) {
                throw new RuntimeException('The job of which this is a retry is not set.');
            }

            $this->markParentsAsRetryFailed($retryOf);
        }

        $this->markJob($retriedJob, Job::STATUS_RETRY_FAILED);
    }

    /**
     * @param Job $retriedJob
     *
     * @throws Exception
     */
    private function markParentsAsRetrySucceeded(Job $retriedJob): void
    {
        if ($retriedJob->isTypeRetrying()) {
            $retryOf = $retriedJob->getRetryOf();

            if ( ! $retryOf instanceof Job) {
                throw new RuntimeException('The job of which this is a retry is not set.');
            }

            $this->markParentsAsRetrySucceeded($retryOf);
        }

        $this->markJob($retriedJob, Job::STATUS_RETRY_SUCCEEDED);
    }

    /**
     * @param Job         $job
     * @param string      $status
     * @param array       $info
     * @param Daemon|null $daemon
     *
     * @throws Exception
     */
    private function markJob(Job $job, string $status, array $info = [], Daemon $daemon = null): void
    {
        $oldStatus = $job->getStatus();
        self::updateJob($job, $status, $info, $daemon);

        $ioWriter        = self::$ioWriter;
        $tryAgainBuilder = ThenWhen::createRetryStrategyBuilder();
        $tryAgainBuilder
            ->setStrategyForException([
                ORMInvalidArgumentException::class, InvalidArgumentException::class,
            ], new LiveStrategy(100))
            // May happen that a Job is detached to keep the memory consumption low but then it is required to flush the
            // current Job.
            // Here we refresh the Job to manage again all the required Jobs.
            // This is a required trade-off between the memory consumption and the queries to the database: we chose to
            // sacrifice queries to the databse in favor of a minor memory consumption.
            ->setMiddleHandlerForException([
                ORMInvalidArgumentException::class, InvalidArgumentException::class,
            ], static function (Exception $e) use ($job, $status, $info, $daemon, $ioWriter): void {
                if (
                    ! $e instanceof ORMInvalidArgumentException
                    && $e instanceof InvalidArgumentException
                    && false === \strpos($e->getMessage(), 'Entity has to be managed or scheduled for removal for single computation')
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
                ORMInvalidArgumentException::class, InvalidArgumentException::class,
            ], static function (Exception $e) use ($job, $oldStatus, $ioWriter): void {
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
                    self::$entityManager->flush($job->getRetriedBy());
                }

                // Flush the original Job
                self::$entityManager->flush($job);
            });
    }

    /**
     * @param Job         $job
     * @param string      $status
     * @param array       $info
     * @param Daemon|null $daemon
     *
     * @throws StringsException
     * @throws ReflectionException
     * @throws ORMException
     */
    private static function updateJob(Job $job, string $status, array $info = [], Daemon $daemon = null): void
    {
        $reflectedClass = self::createReflectedJob($job);

        // First of all set the current status
        $reflectedProperty = $reflectedClass->getProperty('status');
        $reflectedProperty->setAccessible(true);
        $reflectedProperty->setValue($job, $status);

        // If the Job is cancelled, set the reason
        if (Job::STATUS_CANCELLED === $status) {
            $reflectedProperty = $reflectedClass->getProperty('cancellationReason');
            $reflectedProperty->setAccessible(true);
            $reflectedProperty->setValue($job, $info[self::DEBUG]['cancellation_reason']);
        }

        // Then set the processing Daemon
        if (null !== $daemon) {
            $reflectedProperty = $reflectedClass->getProperty('processedBy');
            $reflectedProperty->setAccessible(true);
            $reflectedProperty->setValue($job, $daemon);

            self::$entityManager->persist($daemon);
        }

        // Now set the other info
        foreach ($info as $property => $value) {
            switch ($property) {
                case 'cancelled_by':
                    $reflectedProperty = $reflectedClass->getProperty('cancelledBy');
                    break;
                case 'closed_at':
                    $reflectedProperty = $reflectedClass->getProperty('closedAt');
                    break;
                case self::DEBUG:
                    $reflectedProperty = $reflectedClass->getProperty(self::DEBUG);
                    break;
                case 'output':
                    $reflectedProperty = $reflectedClass->getProperty('output');
                    break;
                case 'exit_code':
                    $reflectedProperty = $reflectedClass->getProperty('exitCode');
                    break;
                case 'started_at':
                    $reflectedProperty = $reflectedClass->getProperty('startedAt');
                    break;
                case 'cancellation_reason':
                    $reflectedProperty = $reflectedClass->getProperty('cancellationReason');
                    break;
                default:
                    throw new RuntimeException(sprintf('The property %s is not managed. Manage it or verify its spelling is correct.', $property));
            }

            // Set the property as accessible
            $reflectedProperty->setAccessible(true);
            $reflectedProperty->setValue($job, $value);
        }
    }

    /**
     * @param Job $retryJob
     *
     * @throws StringsException
     */
    private function updateChildDependencies(Job $retryJob): void
    {
        $retryOf = $retryJob->getRetryOf();

        if ( ! $retryOf instanceof Job) {
            throw new RuntimeException('The retry of Job is not set.');
        }

        // Set the retried Job as parent dependency of the child dependencies of this retrying Job
        foreach ($retryOf->getChildDependencies() as $childDependency) {
            // Add child dependencies of the retried Job
            $retryJob->addChildDependency($childDependency);
        }
    }
}
