<?php

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Util;

use Doctrine\Common\Persistence\Proxy;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMInvalidArgumentException;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Daemon;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job;
use SerendipityHQ\Bundle\ConsoleStyles\Console\Style\SerendipityHQStyle;
use SerendipityHQ\Component\ThenWhen\Strategy\LiveStrategy;
use SerendipityHQ\Component\ThenWhen\ThenWhen;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Changes the status of Jobs during their execution attaching to them execution info.
 */
class JobsMarker
{
    /** @var EntityManager $entityManager */
    private static $entityManager;

    /** @var SerendipityHQStyle $ioWriter */
    private static $ioWriter;

    /**
     * @param EntityManager $entityManager
     */
    public function __construct(EntityManager $entityManager)
    {
        self::$entityManager = $entityManager;
    }

    /**
     * @param SerendipityHQStyle $ioWriter
     */
    public function setIoWriter(SerendipityHQStyle $ioWriter)
    {
        self::$ioWriter = $ioWriter;
    }

    /**
     * @param Job $job
     *
     * @return \ReflectionClass
     */
    public static function createReflectedJob(Job $job)
    {
        $reflectedClass = new \ReflectionClass($job);

        // If the $job is a Doctrine proxy...
        if ($job instanceof Proxy) {
            // ... This gets the real object, the one that the Proxy extends
            $reflectedClass = $reflectedClass->getParentClass();
        }

        return $reflectedClass;
    }

    /**
     * @param Job    $job
     * @param array  $info
     * @param Daemon $daemon
     */
    public function markJobAsAborted(Job $job, array $info, Daemon $daemon)
    {
        $this->markJobAsClosed($job, Job::STATUS_ABORTED, $info, $daemon);
    }

    /**
     * @param Job   $job
     * @param array $info
     */
    public function markJobAsCancelled(Job $job, array $info)
    {
        $this->markJobAsClosed($job, Job::STATUS_CANCELLED, $info);
    }

    /**
     * @param Job   $job
     * @param array $info
     */
    public function markJobAsFailed(Job $job, array $info)
    {
        // If this Job is a retry of another one, mark also the retried as finished
        if ($job->isTypeRetrying()) {
            $this->markParentsAsRetryFailed($job->getRetryOf());
        }

        $this->markJobAsClosed($job, Job::STATUS_FAILED, $info);
    }

    /**
     * @param Job   $job
     * @param array $info
     */
    public function markJobAsFinished(Job $job, array $info)
    {
        // If this Job is a retry of another one, mark also the retried as finished
        if ($job->isTypeRetrying()) {
            $this->markParentsAsRetrySucceeded($job->getRetryOf());
        }

        $this->markJobAsClosed($job, Job::STATUS_SUCCEEDED, $info);
    }

    /**
     * @param Job    $job
     * @param array  $info
     * @param Daemon $daemon
     */
    public function markJobAsPending(Job $job, array $info, Daemon $daemon)
    {
        $this->markJob($job, Job::STATUS_PENDING, $info, $daemon);
    }

    /**
     * @param Job   $failedJob
     * @param array $info
     *
     * @return Job The created retry Job.
     */
    public function markFailedJobAsRetried(Job $failedJob, array $info)
    {
        // Create a new retry Job
        $retryingJob = $failedJob->createRetryForFailed();

        return $this->markJobAsRetried($failedJob, $retryingJob, $info);
    }

    /**
     * @param Job   $staleJob
     * @param array $info
     *
     * @return Job The created retry Job.
     */
    public function markStaleJobAsRetried(Job $staleJob, array $info)
    {
        // Create a new retry Job
        $retryingJob = $staleJob->createRetryForStale();

        return $this->markJobAsRetried($staleJob, $retryingJob, $info);
    }

    /**
     * @param Job $job
     */
    public function markJobAsRunning(Job $job)
    {
        $this->markJob($job, Job::STATUS_RUNNING);
    }

    /**
     * @param Job         $job
     * @param string      $status
     * @param array       $info
     * @param Daemon|null $daemon
     */
    private function markJobAsClosed(Job $job, string $status, array $info, Daemon $daemon = null)
    {
        $info['closed_at'] = new \DateTime();
        $this->markJob($job, $status, $info, $daemon);
    }

    /**
     * @param Job   $retriedJob
     * @param Job   $retryingJob
     * @param array $info
     *
     * @return Job
     */
    private function markJobAsRetried(Job $retriedJob, Job $retryingJob, array $info)
    {
        $this->updateChildDependencies($retryingJob);

        self::$entityManager->persist($retryingJob);

        $this->markJobAsClosed($retriedJob, Job::STATUS_RETRIED, $info);

        return $retryingJob;
    }

    /**
     * @param Job $retriedJob
     */
    private function markParentsAsRetryFailed(Job $retriedJob)
    {
        if ($retriedJob->isTypeRetrying()) {
            $this->markParentsAsRetryFailed($retriedJob->getRetryOf());
        }

        $this->markJob($retriedJob, Job::STATUS_RETRY_FAILED);
    }

    /**
     * @param Job $retriedJob
     */
    private function markParentsAsRetrySucceeded(Job $retriedJob)
    {
        if ($retriedJob->isTypeRetrying()) {
            $this->markParentsAsRetrySucceeded($retriedJob->getRetryOf());
        }

        $this->markJob($retriedJob, Job::STATUS_RETRY_SUCCEEDED);
    }

    /**
     * @param Job         $job
     * @param string      $status
     * @param array       $info
     * @param Daemon|null $daemon
     *
     * @throws \Exception
     */
    private function markJob(Job $job, string $status, array $info = [], Daemon $daemon = null)
    {
        $oldStatus = $job->getStatus();
        $this->updateJob($job, $status, $info, $daemon);

        $ioWriter = self::$ioWriter;
        $tryAgainBuilder = ThenWhen::createRetryStrategyBuilder();
        $tryAgainBuilder
            ->setStrategyForException([
                ORMInvalidArgumentException::class, \InvalidArgumentException::class,
            ], new LiveStrategy(100))
            // May happen that a Job is detached to keep the memory consumption low but then it is required to flush the
            // current Job.
            // Here we refresh the Job to manage again all the required Jobs.
            // This is a required trade-off between the memory consumption and the queries to the database: we chose to
            // the sacrifice queries to the databse in favor of a minor memory consumption.
            ->setMiddleHandlerForException([
                ORMInvalidArgumentException::class, \InvalidArgumentException::class,
            ], function (\Exception $e) use ($job, $status, $info, $daemon, $ioWriter) {
                if (
                    !$e instanceof ORMInvalidArgumentException
                    && $e instanceof \InvalidArgumentException
                    && false === strpos($e->getMessage(), 'Entity has to be managed or scheduled for removal for single computation')
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
            ], function (\Exception $e) use ($job, $oldStatus, $ioWriter) {
                self::$ioWriter->error(sprintf('Error trying to flush Job #%s (%s => %s).', $job->getId(), $oldStatus, $job->getStatus()));
                if ($ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                    Profiler::printUnitOfWork();
                }
                throw $e;
            });

        $tryAgainBuilder->initializeRetryStrategy()
            ->try(function () use ($job) {
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
     */
    private function updateJob(Job $job, string $status, array $info = [], Daemon $daemon = null)
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
            $reflectedProperty->setValue($job, $info['debug']['cancellation_reason']);
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
                case 'debug':
                    $reflectedProperty = $reflectedClass->getProperty('debug');
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
                    throw new \RuntimeException(sprintf(
                        'The property %s is not managed. Manage it or verify its spelling is correct.',
                        $property
                    ));
            }

            // Set the property as accessible
            $reflectedProperty->setAccessible(true);
            $reflectedProperty->setValue($job, $value);
        }
    }

    /**
     * @param Job $retryJob
     */
    private function updateChildDependencies(Job $retryJob)
    {
        /** @var Job $childDependency Set the retried Job as parent dependency of the child dependencies of this retrying Job */
        foreach ($retryJob->getRetryOf()->getChildDependencies() as $childDependency) {
            // Add child dependencies of the retried Job
            $retryJob->addChildDependency($childDependency);
        }
    }
}
