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

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Entity;

use BadMethodCallException;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use LogicException;
use RuntimeException;
use Safe\Exceptions\ArrayException;
use Safe\Exceptions\StringsException;
use SerendipityHQ\Component\ThenWhen\Strategy\LiveStrategy;
use SerendipityHQ\Component\ThenWhen\Strategy\NeverRetryStrategy;
use SerendipityHQ\Component\ThenWhen\Strategy\StrategyInterface;

/**
 * Basic properties and methods o a Job.
 *
 * @ORM\Entity(repositoryClass="SerendipityHQ\Bundle\CommandsQueuesBundle\Repository\JobRepository")
 * @ORM\Table(name="queues_scheduled_jobs")
 */
class Job
{
    /**
     * This is initial state of each newly scheduled job.
     *
     * They get this state when are scheduled for the first time in the database using
     *
     *     // First: we create a Job to push to the queue
     *     $scheduledJob = new Job('queue:test');
     *     $this->get('queues')->schedule($scheduledJob);
     *
     * @var string
     */
    public const STATUS_NEW = 'new';

    /**
     * Once the Job is get from the database it is processed.
     *
     * So, a Process for the Job is created and started.
     *
     * There is a variable delay between the calling of $process->start() and the real start of the process.
     *
     * The Jobs of which the Process->start() method were called but are not already running are put in this "pending"
     * state.
     *
     * Think at this in this way: they were commanded to start but they are not actually started, so they are "pending".
     *
     * This situation may happen on very busy workers.
     *
     * @var string
     */
    public const STATUS_PENDING = 'pending';

    /**
     * If the Job fails for some reasons and can be retried, its status is RETRIED.
     *
     * @var string
     */
    public const STATUS_RETRIED = 'retried';

    /**
     * The job is currently running.
     *
     * Are in this state the Jobs for which a Process is actually started and running.
     *
     * For very fast commands, may happen that they never pass through the state of Running as they are started, a
     * process with its own PID is created, executed and finished. This cycle may be faster than the cycles that checks
     * the current state of the Jobs.
     *
     * So they are started and when the check to see if they are still running is performed they are also already
     * finished. In this case they will skip the "running" state and get directly one of STATUS_FAILED or
     * STATUS_FINISHED.
     *
     * @var string
     */
    public const STATUS_RUNNING = 'running';

    /** The job was processed and finished with success.
     *
     * @var string
     */
    public const STATUS_SUCCEEDED = 'succeeded';

    /** A failed Job that were retried and the retry Job were finished
     *
     * @var string */
    public const STATUS_RETRY_SUCCEEDED = 'retry_succeeded';

    /** The $process->start() method thrown an exception.
     *
     * @var string */
    public const STATUS_ABORTED = 'aborted';

    /** The job failed for some reasons.
     *
     * @var string */
    public const STATUS_FAILED = 'failed';

    /** A failed Job that were retried and the retry Job failed, too
     *
     * @var string */
    public const STATUS_RETRY_FAILED = 'retry_failed';

    /** The parent job (on which this one depends) failed.
     *
     * @var string */
    public const STATUS_CANCELLED = 'cancelled';

    /** Are of this type the Jobs that mark as cancelled child Jobs of a failed one.
     *
     * @var string */
    public const TYPE_CANCELLING = 'cancelling';

    /** Are of this type all Jobs created by the developer or by other Jobs.
     *
     * @var string */
    public const TYPE_JOB = 'job';

    /** Are of this type Jobs created to retry failed ones.
     *
     * @var string */
    public const TYPE_RETRY = 'retry';

    /**
     * @var int The ID of the Job
     *
     * @ORM\Column(name="id", type="integer", nullable=false)
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     */
    private $id;

    /**
     * @var string
     *
     * @ORM\Column(name="command", type="string", length=255, nullable=false)
     */
    private $command;

    /**
     * @var array
     *
     * @ORM\Column(name="arguments", type="array", nullable=false)
     */
    private $arguments;

    /**
     * @var bool
     *
     * @ORM\Column(name="self_aware", type="boolean", nullable=false)
     */
    private $awareOfJob = false;

    /**
     * @var DateTime|null
     *
     * @ORM\Column(name="execute_after_time", type="datetime", nullable=true)
     */
    private $executeAfterTime;

    /**
     * @var DateTime
     *
     * @ORM\Column(name="created_at", type="datetime", nullable=false)
     */
    private $createdAt;

    /**
     * @var DateTime|null
     *
     * @ORM\Column(name="started_at", type="datetime", nullable=true)
     */
    private $startedAt;

    /**
     * @var DateTime|null when the Job is marked as Finished, Failed or Terminated
     *
     * @ORM\Column(name="closed_at", type="datetime", nullable=true)
     */
    private $closedAt;

    /**
     * @var array|null The error produced by the job (usually an exception)
     *
     * @ORM\Column(name="debug", type="array", nullable=true)
     */
    private $debug = [];

    /**
     * @var int
     *
     * @ORM\Column(name="priority", type="smallint", nullable=false)
     */
    private $priority;

    /**
     * @var Daemon the Daemon that processed the Job
     *
     * @ ORM\Column(name="processed_by_damon", type="integer", nullable=true)
     * @ ORM\ManyToOne(targetEntity="SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Daemon", inversedBy="processedJobs")
     * @ ORM\JoinColumn(name="id", referencedColumnName="id")
     */
    private $processedBy;

    /**
     * @var string
     *
     * @ORM\Column(name="queue", type="string", length=255, nullable=false)
     */
    private $queue;

    /**
     * @var string
     *
     * @ORM\Column(name="status", type="string", length=255, nullable=false)
     */
    private $status;

    /**
     * @var string If the status is self::STATUS_CANCELLED this property tells the why
     *
     * @ORM\Column(name="cancellation_reason", type="string", length=255, nullable=true)
     */
    private $cancellationReason;

    /**
     * @var Job|null
     *
     * @ORM\ManyToOne(targetEntity="SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job", inversedBy="cancelledJobs")
     * @ORM\JoinColumn(name="cancelled_by", referencedColumnName="id")
     */
    private $cancelledBy;

    /**
     * @var Collection
     *
     * @ORM\OneToMany(targetEntity="SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job", mappedBy="cancelledBy")
     */
    private $cancelledJobs;

    /**
     * @var string|null The output produced by the job
     *
     * @ORM\Column(name="output", type="text", nullable=true)
     */
    private $output;

    /**
     * @var int|null The code with which the process exited
     *
     * @ORM\Column(name="exit_code", type="integer", nullable=true)
     */
    private $exitCode;

    /**
     * @var Collection
     *
     * @ORM\ManyToMany(targetEntity="SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job", inversedBy="parentDependencies")
     * @ORM\JoinTable(name="queues_jobs_dependencies",
     *     joinColumns={@ORM\JoinColumn(name="parent_job", referencedColumnName="id")},
     *     inverseJoinColumns={@ORM\JoinColumn(name="child_job", referencedColumnName="id")}
     *     )
     */
    private $childDependencies;

    /**
     * @var Collection
     *
     * @ORM\ManyToMany(targetEntity="SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job", mappedBy="childDependencies")
     */
    private $parentDependencies;

    /**
     * @var StrategyInterface
     *
     * @ORM\Column(name="retry_strategy", type="array", nullable=false)
     */
    private $retryStrategy;

    /**
     * @var Job|null If this Job is a retry of another job, here there is the Job of which this is the retry
     *
     * @ORM\OneToOne(targetEntity="SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job", inversedBy="retriedBy")
     * @ORM\JoinColumn(name="retry_of", referencedColumnName="id")
     */
    private $retryOf;

    /**
     * @var Job|null
     *
     * @ORM\OneToOne(targetEntity="SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job", mappedBy="retryOf")
     */
    private $retriedBy;

    /**
     * @var Job|null If this Job is a retry of another retried job, here there is the first retried Job
     *
     * @ORM\ManyToOne(targetEntity="SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job", inversedBy="retryingJobs")
     * @ORM\JoinColumn(name="first_retried_job", referencedColumnName="id", nullable=true)
     */
    private $firstRetriedJob;

    /**
     * @var Collection The Jobs used to retry this one
     *
     * @ORM\OneToMany(targetEntity="SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job", mappedBy="firstRetriedJob")
     */
    private $retryingJobs;

    /** @var string $cannotBeDetachedBecause This is not persisted. It is used to give the reason why the Job cannot be detached. */
    private $cannotBeDetachedBecause;

    /** @var string $cannotBeRemovedBecause This is not persisted. It is used to give the reason why the Job cannot be removed. */
    private $cannotBeRemovedBecause;

    /** @var string $cannotRunBecause This is not persisted. It is used to give the reason why the Job cannot run. */
    private $cannotRunBecause;

    /**
     * @param string       $command
     * @param array|string $arguments
     * @param string       $queue
     *
     * @throws ArrayException
     */
    public function __construct(string $command, $arguments = [], string $queue = Daemon::DEFAULT_QUEUE_NAME)
    {
        $this->command            = $command;
        $this->arguments          = self::prepareArguments($arguments);
        $this->priority           = 1;
        $this->queue              = $queue;
        $this->status             = self::STATUS_NEW;
        $this->createdAt          = new DateTime();
        $this->cancelledJobs      = new ArrayCollection();
        $this->childDependencies  = new ArrayCollection();
        $this->parentDependencies = new ArrayCollection();
        $this->retryStrategy      = new NeverRetryStrategy();
        $this->retryingJobs       = new ArrayCollection();
    }

    /**
     * Ensures the $arguments is only a string or an array.
     * If a string is passed, it is transformed into an array.
     * Then it reorder the arguments to get a unique signature to facilitate checks on existent Jobs.
     *
     * @param array|string $arguments
     *
     * @throws ArrayException
     *
     * @return array
     */
    public static function prepareArguments($arguments = []): array
    {
        // If is a String...
        if (is_string($arguments)) {
            // Transform into an array
            $arguments = explode(' ', $arguments);

            // And remove leading and trailing spaces
            $arguments = array_map('trim', $arguments);
        }

        // Order arguments
        \Safe\asort($arguments);

        return $arguments;
    }

    /**
     * @param string $argument
     *
     * @return Job
     */
    public function addArgument(string $argument): Job
    {
        $this->arguments[] = $argument;

        return $this;
    }

    /**
     * @param Job $job
     *
     * @throws StringsException
     *
     * @return Job
     */
    public function addChildDependency(Job $job): Job
    {
        if ($this === $job) {
            throw new LogicException(
                'You cannot add as dependency the object itself.'
                . ' Check your addParentDependency() and addChildDependency() method.'
            );
        }

        if ($this->parentDependencies->contains($job)) {
            throw new LogicException(
                'You cannot add a child dependecy that is already a parent dependency.'
                . ' This will create an unresolvable circular reference.'
            );
        }

        if (false === $this->childDependencies->contains($job)) {
            $this->childDependencies->add($job);
            $job->addParentDependency($this);
        }

        return $this;
    }

    /**
     * @param Job $job
     *
     * @throws StringsException
     *
     * @return Job
     */
    public function addParentDependency(Job $job): Job
    {
        if ($this === $job) {
            throw new LogicException(
                'You cannot add as dependency the object itself.'
                . ' Check your addParentDependency() and addChildDependency() method.'
            );
        }

        // This Job is already started...
        $status = $this->getStatus();
        if (self::STATUS_PENDING === $status || self::STATUS_RUNNING === $status) {
            throw new LogicException(
                \Safe\sprintf(
                    'The Job %s has already started. You cannot add the parent dependency %s.',
                    $this->getId(), $job->getId()
                )
            );
        }

        if (true === $this->childDependencies->contains($job)) {
            throw new LogicException(\Safe\sprintf(
                'You cannot add a parent dependecy (%s) that is already a child dependency.'
                . ' This will create an unresolvable circular reference.',
                $job->getId()
            ));
        }

        if (false === $this->parentDependencies->contains($job)) {
            $this->parentDependencies->add($job);
            $job->addChildDependency($this);
        }

        return $this;
    }

    /**
     * @throws StringsException
     * @throws ArrayException
     *
     * @return Job
     */
    public function createCancelChildsJob(): Job
    {
        // If the Job as child Jobs, create a process to mark them as cancelled
        return (new Job('queues:internal:mark-as-cancelled', [\Safe\sprintf('--id=%s', $this->getId())]))
            ->setQueue($this->getQueue())
            // This Job has to be successful!
            ->setRetryStrategy(new LiveStrategy(100000))
            ->setPriority(-1)
            ->setQueue($this->getQueue());
    }

    /**
     * @throws ArrayException
     * @throws LogicException
     *
     * @return Job
     */
    public function createRetryForFailed(): Job
    {
        $retryOn = $this->getRetryStrategy()->retryOn();

        if (false === $retryOn) {
            throw new LogicException("The set retry strategy doesn't allow for a retry.");
        }

        // Create a new Job that will retry the original one
        return (new Job($this->getCommand(), $this->getArguments()))
            // First get the retry date
            ->setExecuteAfterTime($retryOn)
            // Then we can increment the current number of attempts setting also the RetryStrategy
            ->setRetryStrategy($this->getRetryStrategy()->newAttempt())
            ->setPriority(-1)
            ->setQueue($this->getQueue())
            ->setRetryOf($this)
            ->setFirstRetriedJob($this->getFirstRetriedJob() ?? $this);
    }

    /**
     * @throws ArrayException
     *
     * @return Job
     */
    public function createRetryForStale(): Job
    {
        // Create a new Job that will retry the original one
        $retryJob = (new Job($this->getCommand(), $this->getArguments()))
            // Then we can increment the current number of attempts setting also the RetryStrategy
            ->setRetryStrategy($this->getRetryStrategy())
            ->setPriority($this->getPriority())
            ->setQueue($this->getQueue())
            ->setRetryOf($this)
            ->setFirstRetriedJob($this->getFirstRetriedJob() ?? $this);

        // If the retried Job had an execute after time...
        if (null !== $this->getExecuteAfterTime()) {
            // ... set it in the retrying Job
            $retryJob->setExecuteAfterTime($this->getExecuteAfterTime());
        }

        return $retryJob;
    }

    /**
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getCommand(): string
    {
        return $this->command;
    }

    /**
     * @return array
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    /**
     * @return DateTime
     */
    public function getCreatedAt(): DateTime
    {
        return $this->createdAt;
    }

    /**
     * @return DateTime|null
     */
    public function getStartedAt(): ? DateTime
    {
        return $this->startedAt;
    }

    /**
     * @return DateTime|null
     */
    public function getClosedAt(): ? DateTime
    {
        return $this->closedAt;
    }

    /**
     * @return array|null Null if the process finished with success
     */
    public function getDebug(): ? array
    {
        return $this->debug;
    }

    /**
     * @return DateTime|null
     */
    public function getExecuteAfterTime(): ? DateTime
    {
        return $this->executeAfterTime;
    }

    /**
     * @return int
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * @return Daemon
     */
    public function getProcessedBy(): Daemon
    {
        return $this->processedBy;
    }

    /**
     * @return string
     */
    public function getQueue(): string
    {
        return $this->queue;
    }

    /**
     * @return string
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * @return string
     */
    public function getCancellationReason(): string
    {
        return $this->cancellationReason;
    }

    /**
     * @return Job|null
     */
    public function getCancelledBy(): ? Job
    {
        return $this->cancelledBy;
    }

    /**
     * @return Collection
     */
    public function getCancelledJobs(): Collection
    {
        return $this->cancelledJobs;
    }

    /**
     * @return string|null Null if no output were produced by the process
     */
    public function getOutput(): ? string
    {
        return $this->output;
    }

    /**
     * @return int|null Null if the process was not already started
     */
    public function getExitCode(): ? int
    {
        return $this->exitCode;
    }

    /**
     * @return Collection
     */
    public function getChildDependencies(): Collection
    {
        return $this->childDependencies;
    }

    /**
     * @return Collection
     */
    public function getParentDependencies(): Collection
    {
        return $this->parentDependencies;
    }

    /**
     * @return Collection
     */
    public function getRetryingJobs(): Collection
    {
        return $this->retryingJobs;
    }

    /**
     * @return StrategyInterface
     */
    public function getRetryStrategy(): StrategyInterface
    {
        return $this->retryStrategy;
    }

    /**
     * @return Job|null
     */
    public function getRetryOf(): ? Job
    {
        return $this->retryOf;
    }

    /**
     * @return Job|null
     */
    public function getRetriedBy(): ?Job
    {
        return $this->retriedBy;
    }

    /**
     * @return Job|null
     */
    public function getFirstRetriedJob(): ?Job
    {
        return $this->firstRetriedJob;
    }

    /**
     * Returns the type of Job.
     *
     * @return string
     */
    public function getType(): string
    {
        if ($this->isTypeCancelling()) {
            return self::TYPE_CANCELLING;
        }

        if ($this->isTypeRetrying()) {
            return self::TYPE_RETRY;
        }

        return self::TYPE_JOB;
    }

    /**
     * Returns the ID of the Job for which this one were created.
     *
     * For example, if this is a cancelling Job, it will cancel childs of the given Job: this method will return the ID
     * of this given Job.
     *
     * @throws StringsException
     * @throws RuntimeException
     *
     * @return int
     */
    public function getProcessedJobId(): int
    {
        if (false === $this->isTypeInternal()) {
            throw new BadMethodCallException(
                \Safe\sprintf(
                    'This Job #%s is not internal, so you cannot call the method self::getProcessedJobId().',
                    $this->getId()
                )
            );
        }

        foreach ($this->getArguments() as $argument) {
            if (false !== strpos($argument, '--id=')) {
                return (int) str_replace('--id=', '', $argument);
            }
        }

        throw new RuntimeException('Impossible to find the ID. This should never happen: investigate further.');
        // This should be never reached
        // This was "return false;". Modified with the exception
    }

    /**
     * @return string
     */
    public function getCannotBeDetachedBecause(): string
    {
        return $this->cannotBeDetachedBecause;
    }

    /**
     * @return string
     */
    public function getCannotBeRemovedBecause(): string
    {
        return $this->cannotBeRemovedBecause;
    }

    /**
     * @return string
     */
    public function getCannotRunBecause(): string
    {
        return $this->cannotRunBecause;
    }

    /**
     * Checks if a Job can or cannot be detached.
     *
     * @throws StringsException
     *
     * @return bool
     */
    public function canBeDetached(): bool
    {
        // PENDING or RUNNING
        if ($this->isStatusWorking()) {
            // It has to be flushed at the end
            $this->cannotBeDetachedBecause = 'is currently working (' . $this->getStatus() . ')';

            return false;
        }

        // Is being retried
        $retriedBy = $this->getRetriedBy();
        if ($this->isStatusRetried()) {
            if (null === $retriedBy) {
                throw new RuntimeException('This is a retried Job but the retrying Job is not set and this is not possible.');
            }

            // It has to be flushed at the end
            $this->cannotBeDetachedBecause = \Safe\sprintf('is being retried by Job #%s (%s)', $retriedBy->getId(), $retriedBy->getStatus());

            return false;
        }

        /** @var Job $parentJob Now check the parent Jobs * */
        foreach ($this->getParentDependencies() as $parentJob) {
            switch ($parentJob->getStatus()) {
                // Waiting dependencies
                case self::STATUS_NEW:
                    $this->cannotBeDetachedBecause = \Safe\sprintf(
                        'has parent Job #%s@%s that has to be processed (%s)',
                        $parentJob->getId(), $parentJob->getQueue(), $parentJob->getStatus()
                    );

                    return false;
                    break;
                case self::STATUS_RETRIED:
                    $this->cannotBeDetachedBecause = \Safe\sprintf(
                        'has parent Job #%s@%s that were retried (%s)',
                        $parentJob->getId(), $parentJob->getQueue(), $parentJob->getStatus()
                    );

                    return false;
                    break;

                // Working dependencies
                case self::STATUS_PENDING:
                    $this->cannotBeDetachedBecause = \Safe\sprintf(
                        'has parent Job #%s@%s that is being processed (%s)',
                        $parentJob->getId(), $parentJob->getQueue(), $parentJob->getStatus()
                    );

                    return false;
                case self::STATUS_RUNNING:
                    $this->cannotBeDetachedBecause = \Safe\sprintf(
                        'has parent Job #%s@%s that is running (%s)',
                        $parentJob->getId(), $parentJob->getQueue(), $parentJob->getStatus()
                    );

                    return false;
            }
        }

        // OK: can be detached
        return true;
    }

    /**
     * @throws StringsException
     *
     * @return bool
     */
    public function canBeRemoved(): bool
    {
        // Until this Job has retrying Jobs not still deleted, it cannot be deleted, too
        if ($this->getRetryingJobs()->count() > 0) {
            $this->cannotBeRemovedBecause = 'has retrying Jobs not still deleted';

            return false;
        }

        // Until this Job is referenced by a cancelling Job, it cannot be removed
        if ($this->getCancelledBy() instanceof self) {
            $this->cannotBeRemovedBecause = \Safe\sprintf(
                'is referenced by cancelling Job <success-nobg>%s</success-nobg>',
                $this->getCancelledBy()->getId()
            );

            return false;
        }

        return true;
    }

    /**
     * @throws StringsException
     *
     * @return bool
     */
    public function canRun(): bool
    {
        if ($this->isStatusFinished()) {
            $this->cannotRunBecause = 'is already finished';

            return false;
        }

        /** @var Job $parentJob Now check the parent Jobs * */
        foreach ($this->getParentDependencies() as $parentJob) {
            switch ($parentJob->getStatus()) {
                // Waiting dependencies
                case self::STATUS_NEW:
                    $this->cannotRunBecause = \Safe\sprintf(
                        'has parent Job #%s@%s that has to be processed (%s)',
                        $parentJob->getId(), $parentJob->getQueue(), $parentJob->getStatus()
                    );

                    return false;
                    break;
                case self::STATUS_RETRIED:
                    $this->cannotRunBecause = \Safe\sprintf(
                        'has parent Job #%s@%s that were retried (%s)',
                        $parentJob->getId(), $parentJob->getQueue(), $parentJob->getStatus()
                    );

                    return false;
                    break;

                // Working dependencies
                case self::STATUS_PENDING:
                    $this->cannotRunBecause = \Safe\sprintf(
                        'has parent Job #%s@%s that is being processed (%s)',
                        $parentJob->getId(), $parentJob->getQueue(), $parentJob->getStatus()
                    );

                    return false;
                case self::STATUS_RUNNING:
                    $this->cannotRunBecause = \Safe\sprintf(
                        'has parent Job #%s@%s that is running (%s)',
                        $parentJob->getId(), $parentJob->getQueue(), $parentJob->getStatus()
                    );

                    return false;
            }
        }

        return true;
    }

    /**
     * @return bool
     */
    public function hasChildDependencies(): bool
    {
        return $this->getChildDependencies()->count() > 0;
    }

    /**
     * @return bool
     */
    public function hasParentDependencies(): bool
    {
        return $this->getParentDependencies()->count() > 0;
    }

    /**
     * @return bool
     */
    public function isAwareOfJob(): bool
    {
        return $this->awareOfJob;
    }

    /**
     * @return bool
     */
    public function isStatusAborted(): bool
    {
        return self::STATUS_ABORTED === $this->getStatus();
    }

    /**
     * @return bool
     */
    public function isStatusCancelled(): bool
    {
        return self::STATUS_CANCELLED === $this->getStatus();
    }

    /**
     * @return bool
     */
    public function isStatusNew(): bool
    {
        return self::STATUS_NEW === $this->getStatus();
    }

    /**
     * @return bool
     */
    public function isStatusPending(): bool
    {
        return self::STATUS_PENDING === $this->getStatus();
    }

    /**
     * @return bool
     */
    public function isStatusRetried(): bool
    {
        return self::STATUS_RETRIED === $this->getStatus();
    }

    /**
     * @return bool
     */
    public function isStatusRunning(): bool
    {
        return self::STATUS_RUNNING === $this->getStatus();
    }

    /**
     * @return bool
     */
    public function isStatusFailed(): bool
    {
        switch ($this->getStatus()) {
            case self::STATUS_CANCELLED:
            case self::STATUS_FAILED:
            case self::STATUS_RETRY_FAILED:
            case self::STATUS_ABORTED:
                return true;
            default:
                return false;
        }
    }

    /**
     * @return bool
     */
    public function isStatusFinished(): bool
    {
        switch ($this->getStatus()) {
            case self::STATUS_ABORTED:
            case self::STATUS_CANCELLED:
            case self::STATUS_FAILED:
            case self::STATUS_RETRIED:
            case self::STATUS_RETRY_FAILED:
            case self::STATUS_RETRY_SUCCEEDED:
            case self::STATUS_SUCCEEDED:
                return true;
            default:
                return false;
        }
    }

    /**
     * @return bool
     */
    public function isStatusSuccessful(): bool
    {
        switch ($this->getStatus()) {
            case self::STATUS_SUCCEEDED:
            case self::STATUS_RETRY_SUCCEEDED:
                return true;
            default:
                return false;
        }
    }

    /**
     * @return bool
     */
    public function isStatusWaiting(): bool
    {
        switch ($this->getStatus()) {
            case self::STATUS_NEW:
            case self::STATUS_RETRIED:
                return true;
            default:
                return false;
        }
    }

    /**
     * @return bool
     */
    public function isStatusWorking(): bool
    {
        switch ($this->getStatus()) {
            case self::STATUS_PENDING:
            case self::STATUS_RUNNING:
                return true;
            default:
                return false;
        }
    }

    /**
     * @return bool
     */
    public function isTypeCancelling(): bool
    {
        return false !== strpos($this->getCommand(), 'mark-as-cancelled');
    }

    /**
     * Is internal a Job used to manage internal tasks as cancelling childs of failed Jobs.
     *
     * @return bool
     */
    public function isTypeInternal(): bool
    {
        switch ($this->getType()) {
            case self::TYPE_CANCELLING:
                return true;
                break;

            case self::TYPE_JOB:
            default:
                return false;
        }
    }

    /**
     * If this Job is a retry of another job this will return true.
     *
     * @return bool
     */
    public function isTypeRetrying(): bool
    {
        return null !== $this->getRetryOf();
    }

    /**
     * If this Job has a retried job.
     *
     * @return bool
     */
    public function isTypeRetried(): bool
    {
        return null !== $this->getRetriedBy();
    }

    /**
     * @param bool $awareOfJobId
     *
     * @return Job
     */
    public function makeAwareOfJob(bool $awareOfJobId = true): Job
    {
        $this->awareOfJob = $awareOfJobId;

        return $this;
    }

    /**
     * @param Job $job
     *
     * @return Job
     */
    public function removeChildDependency(Job $job): Job
    {
        if ($this->childDependencies->contains($job)) {
            $this->childDependencies->removeElement($job);
            $job->removeParentDependency($this);
        }

        return $this;
    }

    /**
     * @param Job $job
     *
     * @return Job
     */
    public function removeParentDependency(Job $job): Job
    {
        if ($this->parentDependencies->contains($job)) {
            $this->parentDependencies->removeElement($job);
            $job->removeChildDependency($this);
        }

        return $this;
    }

    /**
     * @return Job
     */
    public function removeCancelledBy(): Job
    {
        $cancelledBy = $this->getCancelledBy();

        if ($cancelledBy instanceof self) {
            $this->cancelledBy = null;
            $cancelledBy->removeCancelledJob($this);
        }

        return $this;
    }

    /**
     * @param Job $job
     *
     * @return Job
     */
    public function removeCancelledJob(Job $job): Job
    {
        if ($this->cancelledJobs->contains($job)) {
            $this->cancelledJobs->removeElement($job);
            $job->removeCancelledBy();
        }

        return $this;
    }

    /**
     * @return Job
     */
    public function removeRetriedBy(): Job
    {
        $retriedBy = $this->getRetriedBy();

        if ($retriedBy instanceof self) {
            $this->retriedBy = null;
            $retriedBy->removeRetryOf();
        }

        return $this;
    }

    /**
     * @return Job
     */
    public function removeRetryOf(): Job
    {
        $retryOf = $this->getRetryOf();

        if ($retryOf instanceof self) {
            $this->retryOf = null;
            $retryOf->removeRetriedBy();
        }

        return $this;
    }

    /**
     * @return Job
     */
    public function removeFirstRetriedJob(): Job
    {
        $this->firstRetriedJob = null;

        return $this;
    }

    /**
     * @param DateTime $executeAfter
     *
     * @return Job
     */
    public function setExecuteAfterTime(DateTime $executeAfter): Job
    {
        $this->executeAfterTime = $executeAfter;

        return $this;
    }

    /**
     * @param int $priority
     *
     * @return Job
     */
    public function setPriority(int $priority): Job
    {
        $this->priority = $priority;

        return $this;
    }

    /**
     * @param string $queue
     *
     * @return Job
     */
    public function setQueue(string $queue): Job
    {
        $this->queue = $queue;

        return $this;
    }

    /**
     * @param StrategyInterface $retryStrategy
     *
     * @return Job
     */
    public function setRetryStrategy(StrategyInterface $retryStrategy): Job
    {
        $this->retryStrategy = $retryStrategy;

        return $this;
    }

    /**
     * Sets this Job as a retry of another Job.
     *
     * Maintain this method private! It is used through reflection by Job#createRetryJob().
     *
     * @param Job $retriedJob
     *
     * @return Job
     */
    public function setRetryOf(Job $retriedJob): Job
    {
        // This is a retry Job for another job
        $this->retryOf = $retriedJob;
        $retriedJob->setRetriedBy($this);

        return $this;
    }

    /**
     * @param Job $retryingJob
     *
     * @return Job
     */
    protected function setRetriedBy(Job $retryingJob): Job
    {
        $this->retriedBy = $retryingJob;

        return $this;
    }

    /**
     * @param Job $firstRetriedJob
     *
     * @return Job
     */
    protected function setFirstRetriedJob(Job $firstRetriedJob): Job
    {
        $this->firstRetriedJob = $firstRetriedJob;

        return $this;
    }

    /**
     * This is useful in case of exceptions thrown during flushing.
     *
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->getId();
    }
}
