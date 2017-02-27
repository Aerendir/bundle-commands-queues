<?php

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Util;

use Doctrine\Common\Persistence\Proxy;
use Doctrine\ORM\EntityManager;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Daemon;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job;

/**
 * Changes the status of Jobs during their execution attaching to them execution info.
 */
class JobsMarker
{
    /** @var EntityManager $entityManager */
    private $entityManager;

    /**
     * @param EntityManager $entityManager
     */
    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
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
        if ($job->isRetry()) {
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
        if ($job->isRetry()) {
            $this->markParentsAsRetryFinished($job->getRetryOf());
        }

        $this->markJobAsClosed($job, Job::STATUS_FINISHED, $info);
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
     * @param Job $failedJob
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
     * @param Job $staleJob
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
     * @param Job $retriedJob
     * @param Job $retryingJob
     * @param array $info
     * @return Job
     */
    private function markJobAsRetried(Job $retriedJob, Job $retryingJob, array $info)
    {
        $this->updateChildDependencies($retryingJob);

        $this->entityManager->persist($retryingJob);

        $this->markJobAsClosed($retriedJob, Job::STATUS_RETRIED, $info);

        $this->entityManager->detach($retryingJob);

        return $retryingJob ;
    }

    /**
     * @param Job $retriedJob
     */
    private function markParentsAsRetryFailed(Job $retriedJob)
    {
        if ($retriedJob->isRetry()) {
            $this->markParentsAsRetryFailed($retriedJob->getRetryOf());
        }

        $this->markJob($retriedJob, Job::STATUS_RETRY_FAILED);
    }

    /**
     * @param Job $retriedJob
     */
    private function markParentsAsRetryFinished(Job $retriedJob)
    {
        if ($retriedJob->isRetry()) {
            $this->markParentsAsRetryFinished($retriedJob->getRetryOf());
        }

        $this->markJob($retriedJob, Job::STATUS_RETRY_FINISHED);
    }

    /**
     * @param Job $job
     * @param string $status
     * @param array $info
     * @param Daemon|null $daemon
     * @throws \Exception
     */
    private function markJob(Job $job, string $status, array $info = [], Daemon $daemon = null)
    {
        $reflectedClass = new \ReflectionClass($job);

        // If the $job is a Doctrine proxy...
        if ($job instanceof Proxy)
            // ... This gets the real object, the one that the Proxy extends
            $reflectedClass = $reflectedClass->getParentClass();

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

            $this->entityManager->persist($daemon);
        }

        // Now set the other info
        foreach ($info as $property => $value) {
            switch ($property) {
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

        // Persist the entity again (just to be sure it is managed)
        $this->entityManager->persist($job);

        /*
         * Flush now to be sure editings aren't cleared during optimizations.
         *
         * We flush the single Jobs to don't affect the others that may be still processing.
         */
        if ($job->isRetried()) {
            // Flush the new retrying Job
            $this->entityManager->flush($job->getRetriedBy());
        }

        // Flush the original Job
        $this->entityManager->flush($job);

        if ($job->isClosed() && false === $job->hasChildDependencies() && false === $job->hasRunningRetryingJobs()) {
            $this->entityManager->detach($job);
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
