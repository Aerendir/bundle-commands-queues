<?php

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Util;

use Doctrine\Common\Persistence\Proxy;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMInvalidArgumentException;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Daemon;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job;
use SerendipityHQ\Bundle\ConsoleStyles\Console\Style\SerendipityHQStyle;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Changes the status of Jobs during their execution attaching to them execution info.
 */
class JobsMarker
{
    /** @var EntityManager $entityManager */
    private static $entityManager;

    /** @var  SerendipityHQStyle $ioWriter */
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
     * Handles the detach of a Job.
     * 
     * IMplements a custom logic to detach Jobs linked to the detaching one.
     * 
     * @param Job $job
     * @param string $where
     *
     * @return bool
     */
    public static function detach(Job $job, string $where = null)
    {
        $detached = self::doDetach($job, $where);

        if (false !== $detached && self::$ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
            $message = sprintf('Job <success-nobg>#%s</success-nobg> detached', $job->getId());

            if (null !== $where) {
                $message = sprintf('[%s] %s', $where, $message);
            }

            if (0 < count($detached)) {
                $message = sprintf('%s <comment-nobg>(Cascaded: %s)</comment-nobg>', $message, implode(', ', $detached));
            }

            self::$ioWriter->infoLineNoBg($message . '.');
        }

        return false !== $detached ? true : false;
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

        self::$entityManager->persist($retryingJob);

        $this->markJobAsClosed($retriedJob, Job::STATUS_RETRIED, $info);

        self::detach($retryingJob, 'JobsMarker::markJobAsRetried');

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

        $this->markJob($retriedJob, Job::STATUS_RETRY_SUCCEEDED);
    }

    /**
     * @param Job $job
     * @param string $status
     * @param array $info
     * @param Daemon|null $daemon
     *
     * @throws \Exception
     */
    private function markJob(Job $job, string $status, array $info = [], Daemon $daemon = null)
    {
        $oldStatus = $job->getStatus();
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

        // Persist the entity again (just to be sure it is managed)
        self::$entityManager->persist($job);

        try {
            /*
             * Flush now to be sure editings aren't cleared during optimizations.
             *
             * We flush the single Jobs to don't affect the others that may be still processing.
             */
            if ($job->isRetried()) {
                // Flush the new retrying Job
                self::$entityManager->flush($job->getRetriedBy());
            }

            // Flush the original Job
            self::$entityManager->flush($job);
        } catch (ORMInvalidArgumentException $e) {
            self::$ioWriter->error(sprintf('Error trying to flush Job #%s (%s => %s).', $job->getId(), $oldStatus, $job->getStatus()));
            Profiler::printUnitOfWork();
            throw $e;
        }

        // If this is a cancelling Job, refresh the Job for which childs were cancelled
        if ($job->isCancelling()) {
            $processedJob = self::$entityManager->getRepository('SHQCommandsQueuesBundle:Job')->findOneById($job->getProcessedJobId());
            self::$entityManager->refresh($processedJob);
            self::detach($processedJob, 'JobsMarker::markJob (Cancelling Job)');
        }

        if ($job->isFinished()) {
            self::detach($job, 'JobsMarker::markJob (finished Job)');
        }
    }

    /**
     * @param Job $job
     * @param string $where
     * @param Job $linkedBy
     * @param array $detached The already detached entities. This not works ever, but is required to avoid "Fatal error:
     * Maximum function nesting level ... reached, aborting!".
     *
     * If a Job is skipped here, it will then be detached during the optimization.
     *
     * @return array|bool
     */
    private static function doDetach(Job $job, string $where = null, Job $linkedBy = null, array &$detached = [])
    {
        $linkedByMessage = null !== $linkedBy ? sprintf('(linked by #%s)', $linkedBy->getId()) : '';
        // Detach the object only if it isn't already detached or if it can be
        if (key_exists($job->getId(), $detached)) {
            if (self::$ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                $message = sprintf('Skipping detaching Job <success-nobg>#%s</success-nobg> %s as it <success-nobg>is already detached</success-nobg>.', $job->getId(), $linkedByMessage);

                if (null !== $where) {
                    $message = sprintf('[%s] %s', $where, $message);
                }

                self::$ioWriter->infoLineNoBg($message);
            }
            return false;
        }

        if (false === $job->canBeDetached()) {
            if (self::$ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                $message = sprintf(
                    'Skipping detaching Job <success-nobg>#%s</success-nobg> %s because <success-nobg>%s</success-nobg>.',
                    $job->getId(), $linkedByMessage, $job->getCannotBeDetachedBecause()
                );

                if (null !== $where) {
                    $message = sprintf('[%s] %s', $where, $message);
                }

                self::$ioWriter->infoLineNoBg($message);
            }
            return false;
        }

        // Add the current Job to the already detached
        self::$entityManager->detach($job);
        if (self::$ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
            $message = sprintf('Job <info-nobg>#%s</info-nobg> %s detached.', $job->getId(), $linkedByMessage);

            if (null !== $where) {
                $message = sprintf('[%s] %s', $where, $message);
            }

            self::$ioWriter->successLineNoBg($message);
        }
        $detached[$job->getId()] = '#'. $job->getId();

        // Detach retryOf
        if (null !== $job->getRetryOf() && false !== self::doDetach($job->getRetryOf(), $where, $job, $detached)) {
            $detached[$job->getRetryOf()->getId()] = '#' . $job->getRetryOf()->getId();
        }

        // Detach retryiedBy
        if (null !== $job->getRetriedBy() && false !== self::doDetach($job->getRetriedBy(), $where, $job, $detached)) {
            $detached[$job->getRetriedBy()->getId()] = '#' . $job->getRetriedBy()->getId();
        }

        // Detach firstRetriedJob
        if (null !== $job->getFirstRetriedJob() && false !== self::doDetach($job->getFirstRetriedJob(), $where, $job, $detached)) {
            $detached[$job->getFirstRetriedJob()->getId()] = '#' . $job->getFirstRetriedJob()->getId();
        }

        /** @var Job $childDependency Detach childDeps **/
        foreach ($job->getChildDependencies() as $childDependency) {
            $job->getChildDependencies()->removeElement($childDependency);
            if (false !== self::doDetach($childDependency, $where, $job, $detached)) {
                $detached[$childDependency->getId()] = '#' . $childDependency->getId();
            }
        }

        /** @var Job $parentDependency Detach childDeps **/
        foreach ($job->getParentDependencies() as $parentDependency) {
            $job->getParentDependencies()->removeElement($parentDependency);
            if (false !== self::doDetach($parentDependency, $where, $job, $detached)) {
                $detached[$parentDependency->getId()] = '#' . $parentDependency->getId();
            }
        }

        /** @var Job $retryingJob Detach childDeps **/
        foreach ($job->getRetryingJobs() as $retryingJob) {
            $job->getRetryingJobs()->removeElement($retryingJob);
            if (false !== self::doDetach($retryingJob, $where, $job, $detached)) {
                $detached[$retryingJob->getId()] = '#' . $retryingJob->getId();
            }
        }

        return $detached;
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
