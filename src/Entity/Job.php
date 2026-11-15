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

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Command\InternalMarkAsCancelledCommand;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Repository\JobRepository;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Util\InputParser;
use SerendipityHQ\Component\ThenWhen\Strategy\LiveStrategy;
use SerendipityHQ\Component\ThenWhen\Strategy\NeverRetryStrategy;
use SerendipityHQ\Component\ThenWhen\Strategy\StrategyInterface;

/**
 * Basic properties and methods o a Job.
 */
#[ORM\Table(name: 'queues_scheduled_jobs')]
#[ORM\Entity(repositoryClass: JobRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Job implements \Stringable
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

    /**
     * The job was processed and finished with success.
     *
     * @var string
     */
    public const STATUS_SUCCEEDED = 'succeeded';

    /**
     * A failed Job that were retried and the retry Job were finished.
     *
     * @var string */
    public const STATUS_RETRY_SUCCEEDED = 'retry_succeeded';

    /**
     * The $process->start() method thrown an exception.
     *
     * @var string */
    public const STATUS_ABORTED = 'aborted';

    /**
     * The job failed for some reasons.
     *
     * @var string */
    public const STATUS_FAILED = 'failed';

    /**
     * A failed Job that were retried and the retry Job failed, too.
     *
     * @var string */
    public const STATUS_RETRY_FAILED = 'retry_failed';

    /**
     * The parent job (on which this one depends) failed.
     *
     * @var string */
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Are of this type the Jobs that mark as cancelled child Jobs of a failed one.
     *
     * @var string */
    public const TYPE_CANCELLING = 'cancelling';

    /**
     * Are of this type all Jobs created by the developer or by other Jobs.
     *
     * @var string */
    public const TYPE_JOB = 'job';

    /**
     * Are of this type Jobs created to retry failed ones.
     *
     * @var string */
    public const TYPE_RETRY = 'retry';

    /** @var string */
    private const OPTIONS = 'options';

    /** @var string */
    private const SHORTCUTS = 'shortcuts';

    /** @var int The ID of the Job */
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private $id;

    /** @var array|null */
    #[ORM\Column(name: 'input', type: 'array', nullable: true)]
    private $input;

    /** @var bool */
    #[ORM\Column(name: 'self_aware', type: 'boolean')]
    private $awareOfJob = false;

    /** @var \DateTime|null */
    #[ORM\Column(name: 'execute_after_time', type: 'datetime', nullable: true)]
    private $executeAfterTime;

    #[ORM\Column(name: 'created_at', type: 'datetime')]
    private \DateTime $createdAt;

    /** @var \DateTimeInterface|null */
    #[ORM\Column(name: 'started_at', type: 'datetime', nullable: true)]
    private $startedAt;

    /** @var \DateTimeInterface|null when the Job is marked as Finished, Failed or Terminated */
    #[ORM\Column(name: 'closed_at', type: 'datetime', nullable: true)]
    private $closedAt;

    /** @var array|null The error produced by the job (usually an exception) */
    #[ORM\Column(name: 'debug', type: 'array', nullable: true)]
    private $debug = [];

    #[ORM\Column(name: 'priority', type: 'smallint')]
    private int $priority;

    /**
     * @var Daemon the Daemon that processed the Job
     *
     * @ ORM\Column(name="processed_by_damon", type="integer", nullable=true)
     *
     * @ ORM\ManyToOne(targetEntity="SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Daemon", inversedBy="processedJobs")
     *
     * @ ORM\JoinColumn(name="id", referencedColumnName="id")
     */
    private $processedBy;

    #[ORM\Column(name: 'status', type: 'string', length: 255)]
    private string $status;

    /** @var string If the status is self::STATUS_CANCELLED this property tells the why */
    #[ORM\Column(name: 'cancellation_reason', type: 'string', length: 255, nullable: true)]
    private $cancellationReason;

    /** @var Job|null */
    #[ORM\JoinColumn(name: 'cancelled_by')]
    #[ORM\ManyToOne(targetEntity: Job::class, inversedBy: 'cancelledJobs')]
    private $cancelledBy;

    /** @var Collection */
    #[ORM\OneToMany(targetEntity: Job::class, mappedBy: 'cancelledBy')]
    private $cancelledJobs;

    /** @var string|null The output produced by the job */
    #[ORM\Column(name: 'output', type: 'text', nullable: true)]
    private $output;

    /** @var int|null The code with which the process exited */
    #[ORM\Column(name: 'exit_code', type: 'integer', nullable: true)]
    private $exitCode;

    /** @var Collection */
    #[ORM\JoinTable(name: 'queues_jobs_dependencies')]
    #[ORM\JoinColumn(name: 'parent_job', referencedColumnName: 'id')]
    #[ORM\InverseJoinColumn(name: 'child_job', referencedColumnName: 'id')]
    #[ORM\ManyToMany(targetEntity: Job::class, inversedBy: 'parentDependencies')]
    private $childDependencies;

    /** @var Collection */
    #[ORM\ManyToMany(targetEntity: Job::class, mappedBy: 'childDependencies')]
    private $parentDependencies;

    /** @var StrategyInterface */
    #[ORM\Column(name: 'retry_strategy', type: 'array')]
    private $retryStrategy;

    /** @var Job|null If this Job is a retry of another job, here there is the Job of which this is the retry */
    #[ORM\JoinColumn(name: 'retry_of')]
    #[ORM\OneToOne(targetEntity: Job::class, inversedBy: 'retriedBy')]
    private $retryOf;

    /** @var Job|null */
    #[ORM\OneToOne(targetEntity: Job::class, mappedBy: 'retryOf')]
    private $retriedBy;

    /** @var Job|null If this Job is a retry of another retried job, here there is the first retried Job */
    #[ORM\JoinColumn(name: 'first_retried_job')]
    #[ORM\ManyToOne(targetEntity: Job::class, inversedBy: 'retryingJobs')]
    private $firstRetriedJob;

    /** @var Collection The Jobs used to retry this one */
    #[ORM\OneToMany(targetEntity: Job::class, mappedBy: 'firstRetriedJob')]
    private $retryingJobs;

    /** @var string $cannotBeDetachedBecause This is not persisted. It is used to give the reason why the Job cannot be detached. */
    private ?string $cannotBeDetachedBecause = null;

    /** @var string $cannotBeRemovedBecause This is not persisted. It is used to give the reason why the Job cannot be removed. */
    private ?string $cannotBeRemovedBecause = null;

    /** @var string $cannotRunBecause This is not persisted. It is used to give the reason why the Job cannot run. */
    private ?string $cannotRunBecause = null;

    /**
     * @param array|string $input
     */
    public function __construct(#[ORM\Column(name: 'command', type: 'string', length: 255)]
        private string $command, $input = [], #[ORM\Column(name: 'queue', type: 'string', length: 255)]
        private string $queue = Daemon::DEFAULT_QUEUE_NAME)
    {
        $this->input              = InputParser::parseInput($input, false);
        $this->priority           = 1;
        $this->status             = self::STATUS_NEW;
        $this->createdAt          = new \DateTime();
        $this->cancelledJobs      = new ArrayCollection();
        $this->childDependencies  = new ArrayCollection();
        $this->parentDependencies = new ArrayCollection();
        $this->retryStrategy      = new NeverRetryStrategy();
        $this->retryingJobs       = new ArrayCollection();

        unset($this->input['command']);
    }

    /**
     * This is useful in case of exceptions thrown during flushing.
     */
    public function __toString(): string
    {
        return (string) $this->getId();
    }

    /**
     * This method accepts a string like "argument".
     */
    public function addArgument(string $value): self
    {
        if (false === InputParser::isArgument($value)) {
            throw new \InvalidArgumentException("Job::addArgument() doesn't accept options nor shortcuts.");
        }

        $this->input['arguments'][] = $value;

        return $this;
    }

    /**
     * This method accepts a string like "--option".
     */
    public function addOption(string $option, ?string $value = null): self
    {
        if (false === InputParser::isOption($option)) {
            throw new \InvalidArgumentException('Job::addAOption() accepts only strings that start with "--".');
        }

        if (false === \is_array($this->input[self::OPTIONS])) {
            $this->input[self::OPTIONS] = [];
        }

        if (\array_key_exists($option, $this->input[self::OPTIONS])) {
            throw new \InvalidArgumentException(sprintf('The option "%s" was already added to the command.', $option));
        }

        $this->input[self::OPTIONS][$option] = $value;

        return $this;
    }

    public function addShortcut(string $option, ?string $value = null): self
    {
        if (false === InputParser::isShortcut($option)) {
            throw new \InvalidArgumentException('Job::addShortcut() accepts only strings that start with "-".');
        }

        if (false === \is_array($this->input[self::SHORTCUTS])) {
            $this->input[self::SHORTCUTS] = [];
        }

        if (\array_key_exists($option, $this->input[self::SHORTCUTS])) {
            throw new \InvalidArgumentException(sprintf('The shortcut "%s" was already added to the command.', $option));
        }

        $this->input[self::SHORTCUTS][$option] = $value;

        return $this;
    }

    public function addChildDependency(Job $job): self
    {
        if ($this === $job) {
            throw new \LogicException('You cannot add as dependency the object itself. Check your addParentDependency() and addChildDependency() method.');
        }

        if ($this->parentDependencies->contains($job)) {
            throw new \LogicException('You cannot add a child dependecy that is already a parent dependency. This will create an unresolvable circular reference.');
        }

        if (false === $this->childDependencies->contains($job)) {
            $this->childDependencies->add($job);
            $job->addParentDependency($this);
        }

        return $this;
    }

    public function addParentDependency(Job $job): self
    {
        if ($this === $job) {
            throw new \LogicException('You cannot add as dependency the object itself. Check your addParentDependency() and addChildDependency() method.');
        }

        // This Job is already started...
        $status = $this->getStatus();
        if (self::STATUS_PENDING === $status || self::STATUS_RUNNING === $status) {
            throw new \LogicException(sprintf('The Job %s has already started. You cannot add the parent dependency %s.', $this->getId(), $job->getId()));
        }

        if (true === $this->childDependencies->contains($job)) {
            throw new \LogicException(sprintf('You cannot add a parent dependecy (%s) that is already a child dependency. This will create an unresolvable circular reference.', $job->getId()));
        }

        if (false === $this->parentDependencies->contains($job)) {
            $this->parentDependencies->add($job);
            $job->addChildDependency($this);
        }

        return $this;
    }

    public function createCancelChildsJob(): Job
    {
        // If the Job as child Jobs, create a process to mark them as cancelled
        return (new Job(InternalMarkAsCancelledCommand::NAME))
            ->addOption('--id', (string) $this->getId())
            ->setQueue($this->getQueue())
            // This Job has to be successful!
            ->setRetryStrategy(new LiveStrategy(100000))
            ->setPriority(-1)
            ->setQueue($this->getQueue());
    }

    public function createRetryForFailed(): Job
    {
        $retryOn = $this->getRetryStrategy()->retryOn();

        if (false === $retryOn) {
            throw new \LogicException("The set retry strategy doesn't allow for a retry.");
        }

        // Create a new Job that will retry the original one

        return (new Job($this->getCommand(), $this->getInput() ?? []))
            // First get the retry date
            ->setExecuteAfterTime($retryOn)
            // Then we can increment the current number of attempts setting also the RetryStrategy
            ->setRetryStrategy($this->getRetryStrategy()->newAttempt())
            ->setPriority(-1)
            ->setQueue($this->getQueue())
            ->setRetryOf($this)
            ->setFirstRetriedJob($this->getFirstRetriedJob() ?? $this);
    }

    public function createRetryForStale(): Job
    {
        // Create a new Job that will retry the original one
        $retryJob = (new Job($this->getCommand(), $this->getInput() ?? []))
            // Then we can increment the current number of attempts setting also the RetryStrategy
            ->setRetryStrategy($this->getRetryStrategy())
            ->setPriority($this->getPriority())
            ->setQueue($this->getQueue())
            ->setRetryOf($this)
            ->setFirstRetriedJob($this->getFirstRetriedJob() ?? $this);

        // If the retried Job had an execute after time...
        if ($this->getExecuteAfterTime() instanceof \DateTimeInterface) {
            // ... set it in the retrying Job
            $retryJob->setExecuteAfterTime($this->getExecuteAfterTime());
        }

        return $retryJob;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getCommand(): string
    {
        return $this->command;
    }

    public function getInput(): ?array
    {
        return $this->input;
    }

    /**
     * @return \DateTime|\DateTimeImmutable
     */
    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    /**
     * @return \DateTime|\DateTimeImmutable|null
     */
    public function getStartedAt(): ?\DateTimeInterface
    {
        return $this->startedAt;
    }

    /**
     * @return \DateTime|\DateTimeImmutable|null
     */
    public function getClosedAt(): ?\DateTimeInterface
    {
        return $this->closedAt;
    }

    /**
     * @return array|null Null if the process finished with success
     */
    public function getDebug(): ?array
    {
        return $this->debug;
    }

    /**
     * @return \DateTime|\DateTimeImmutable|null
     */
    public function getExecuteAfterTime(): ?\DateTimeInterface
    {
        return $this->executeAfterTime;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function getProcessedBy(): Daemon
    {
        return $this->processedBy;
    }

    public function getQueue(): string
    {
        return $this->queue;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCancellationReason(): string
    {
        return $this->cancellationReason;
    }

    public function getCancelledBy(): ?Job
    {
        return $this->cancelledBy;
    }

    public function getCancelledJobs(): Collection
    {
        return $this->cancelledJobs;
    }

    /**
     * @return string|null Null if no output were produced by the process
     */
    public function getOutput(): ?string
    {
        return $this->output;
    }

    /**
     * @return int|null Null if the process was not already started
     */
    public function getExitCode(): ?int
    {
        return $this->exitCode;
    }

    public function getChildDependencies(): Collection
    {
        return $this->childDependencies;
    }

    public function getParentDependencies(): Collection
    {
        return $this->parentDependencies;
    }

    public function getRetryingJobs(): Collection
    {
        return $this->retryingJobs;
    }

    public function getRetryStrategy(): StrategyInterface
    {
        return $this->retryStrategy;
    }

    public function getRetryOf(): ?Job
    {
        return $this->retryOf;
    }

    public function getRetriedBy(): ?Job
    {
        return $this->retriedBy;
    }

    public function getFirstRetriedJob(): ?Job
    {
        return $this->firstRetriedJob;
    }

    /**
     * Returns the type of Job.
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

    public function getCannotBeDetachedBecause(): string
    {
        return $this->cannotBeDetachedBecause;
    }

    public function getCannotBeRemovedBecause(): string
    {
        return $this->cannotBeRemovedBecause;
    }

    public function getCannotRunBecause(): string
    {
        return $this->cannotRunBecause;
    }

    /**
     * Checks if a Job can or cannot be detached.
     */
    public function canBeDetached(): bool
    {
        // PENDING or RUNNING
        if ($this->isStatusWorking()) {
            // It has to be flushed at the end
            $this->cannotBeDetachedBecause = 'is currently working (' . $this->getStatus() . ')';

            return false;
        }

        // Is being retried.
        $retriedBy = $this->getRetriedBy();
        if ($this->isStatusRetried()) {
            if ( ! $retriedBy instanceof Job) {
                throw new \RuntimeException('This is a retried Job but the retrying Job is not set and this is not possible.');
            }

            // It has to be flushed at the end
            $this->cannotBeDetachedBecause = sprintf('is being retried by Job #%s (%s)', $retriedBy->getId(), $retriedBy->getStatus());

            return false;
        }

        /** @var Job $parentJob Now check the parent Jobs * */
        foreach ($this->getParentDependencies() as $parentJob) {
            switch ($parentJob->getStatus()) {
                // Waiting dependencies
                case self::STATUS_NEW:
                    $this->cannotBeDetachedBecause = sprintf(
                        'has parent Job #%s@%s that has to be processed (%s)',
                        $parentJob->getId(), $parentJob->getQueue(), $parentJob->getStatus()
                    );

                    return false;

                    break;
                case self::STATUS_RETRIED:
                    $this->cannotBeDetachedBecause = sprintf(
                        'has parent Job #%s@%s that were retried (%s)',
                        $parentJob->getId(), $parentJob->getQueue(), $parentJob->getStatus()
                    );

                    return false;

                    break;

                    // Working dependencies
                case self::STATUS_PENDING:
                    $this->cannotBeDetachedBecause = sprintf(
                        'has parent Job #%s@%s that is being processed (%s)',
                        $parentJob->getId(), $parentJob->getQueue(), $parentJob->getStatus()
                    );

                    return false;
                case self::STATUS_RUNNING:
                    $this->cannotBeDetachedBecause = sprintf(
                        'has parent Job #%s@%s that is running (%s)',
                        $parentJob->getId(), $parentJob->getQueue(), $parentJob->getStatus()
                    );

                    return false;
            }
        }

        // OK: can be detached
        return true;
    }

    public function canBeRemoved(): bool
    {
        // Until this Job has retrying Jobs not still removed, it cannot be remvoed, too
        if ($this->getRetryingJobs()->count() > 0) {
            $this->cannotBeRemovedBecause = 'has retrying Jobs not still removed';

            return false;
        }

        // Until this Job has cancelled Jobs not still removed, it cannot be removed, too
        if ($this->isTypeCancelling() && $this->getCancelledJobs()->count() > 0) {
            $this->cannotBeRemovedBecause = 'has cancelled Jobs not still removed';

            return false;
        }

        return true;
    }

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
                    $this->cannotRunBecause = sprintf(
                        'has parent Job #%s@%s that has to be processed (%s)',
                        $parentJob->getId(), $parentJob->getQueue(), $parentJob->getStatus()
                    );

                    return false;

                    break;
                case self::STATUS_RETRIED:
                    $this->cannotRunBecause = sprintf(
                        'has parent Job #%s@%s that were retried (%s)',
                        $parentJob->getId(), $parentJob->getQueue(), $parentJob->getStatus()
                    );

                    return false;

                    break;

                    // Working dependencies
                case self::STATUS_PENDING:
                    $this->cannotRunBecause = sprintf(
                        'has parent Job #%s@%s that is being processed (%s)',
                        $parentJob->getId(), $parentJob->getQueue(), $parentJob->getStatus()
                    );

                    return false;
                case self::STATUS_RUNNING:
                    $this->cannotRunBecause = sprintf(
                        'has parent Job #%s@%s that is running (%s)',
                        $parentJob->getId(), $parentJob->getQueue(), $parentJob->getStatus()
                    );

                    return false;
            }
        }

        return true;
    }

    public function hasChildDependencies(): bool
    {
        return $this->getChildDependencies()->count() > 0;
    }

    public function hasParentDependencies(): bool
    {
        return $this->getParentDependencies()->count() > 0;
    }

    public function isAwareOfJob(): bool
    {
        return $this->awareOfJob;
    }

    public function isStatusAborted(): bool
    {
        return self::STATUS_ABORTED === $this->getStatus();
    }

    public function isStatusCancelled(): bool
    {
        return self::STATUS_CANCELLED === $this->getStatus();
    }

    public function isStatusNew(): bool
    {
        return self::STATUS_NEW === $this->getStatus();
    }

    public function isStatusPending(): bool
    {
        return self::STATUS_PENDING === $this->getStatus();
    }

    public function isStatusRetried(): bool
    {
        return self::STATUS_RETRIED === $this->getStatus();
    }

    public function isStatusRunning(): bool
    {
        return self::STATUS_RUNNING === $this->getStatus();
    }

    public function isStatusFailed(): bool
    {
        return match ($this->getStatus()) {
            self::STATUS_CANCELLED, self::STATUS_FAILED, self::STATUS_RETRY_FAILED, self::STATUS_ABORTED => true,
            default                                                                                      => false,
        };
    }

    public function isStatusFinished(): bool
    {
        return match ($this->getStatus()) {
            self::STATUS_ABORTED, self::STATUS_CANCELLED, self::STATUS_FAILED, self::STATUS_RETRIED, self::STATUS_RETRY_FAILED, self::STATUS_RETRY_SUCCEEDED, self::STATUS_SUCCEEDED => true,
            default                                                                                                                                                                  => false,
        };
    }

    public function isStatusSuccessful(): bool
    {
        return match ($this->getStatus()) {
            self::STATUS_SUCCEEDED, self::STATUS_RETRY_SUCCEEDED => true,
            default                                              => false,
        };
    }

    public function isStatusWaiting(): bool
    {
        return match ($this->getStatus()) {
            self::STATUS_NEW, self::STATUS_RETRIED => true,
            default                                => false,
        };
    }

    public function isStatusWorking(): bool
    {
        return match ($this->getStatus()) {
            self::STATUS_PENDING, self::STATUS_RUNNING => true,
            default                                    => false,
        };
    }

    public function isTypeCancelling(): bool
    {
        return str_contains($this->getCommand(), 'mark-as-cancelled');
    }

    /**
     * Is internal a Job used to manage internal tasks as cancelling childs of failed Jobs.
     */
    public function isTypeInternal(): bool
    {
        return match ($this->getType()) {
            self::TYPE_CANCELLING => true,
            default               => false,
        };
    }

    /**
     * If this Job is a retry of another job this will return true.
     */
    public function isTypeRetrying(): bool
    {
        return $this->getRetryOf() instanceof Job;
    }

    /**
     * If this Job has a retried job.
     */
    public function isTypeRetried(): bool
    {
        return $this->getRetriedBy() instanceof Job;
    }

    public function makeAwareOfJob(bool $awareOfJobId = true): self
    {
        $this->awareOfJob = $awareOfJobId;

        return $this;
    }

    public function removeChildDependency(Job $job): self
    {
        if ($this->childDependencies->contains($job)) {
            $this->childDependencies->removeElement($job);
            $job->removeParentDependency($this);
        }

        return $this;
    }

    public function removeParentDependency(Job $job): self
    {
        if ($this->parentDependencies->contains($job)) {
            $this->parentDependencies->removeElement($job);
            $job->removeChildDependency($this);
        }

        return $this;
    }

    public function removeCancelledBy(): self
    {
        $cancelledBy = $this->getCancelledBy();

        if ($cancelledBy instanceof self) {
            $this->cancelledBy = null;
            $cancelledBy->removeCancelledJob($this);
        }

        return $this;
    }

    public function removeCancelledJob(Job $job): self
    {
        if ($this->cancelledJobs->contains($job)) {
            $this->cancelledJobs->removeElement($job);
            $job->removeCancelledBy();
        }

        return $this;
    }

    public function removeRetriedBy(): self
    {
        $retriedBy = $this->getRetriedBy();

        if ($retriedBy instanceof self) {
            $this->retriedBy = null;
            $retriedBy->removeRetryOf();
        }

        return $this;
    }

    public function removeRetryOf(): self
    {
        $retryOf = $this->getRetryOf();

        if ($retryOf instanceof self) {
            $this->retryOf = null;
            $retryOf->removeRetriedBy();
        }

        return $this;
    }

    public function removeFirstRetriedJob(): self
    {
        $this->firstRetriedJob = null;

        return $this;
    }

    /**
     * @param \DateTime|\DateTimeImmutable $executeAfter
     */
    public function setExecuteAfterTime(\DateTimeInterface $executeAfter): self
    {
        $this->executeAfterTime = $executeAfter;

        return $this;
    }

    public function setPriority(int $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    public function setQueue(string $queue): self
    {
        $this->queue = $queue;

        return $this;
    }

    public function setRetryStrategy(StrategyInterface $retryStrategy): self
    {
        $this->retryStrategy = $retryStrategy;

        return $this;
    }

    /**
     * Sets this Job as a retry of another Job.
     *
     * Maintain this method private! It is used through reflection by Job#createRetryJob().
     */
    public function setRetryOf(Job $retriedJob): self
    {
        // This is a retry Job for another job
        $this->retryOf = $retriedJob;
        $retriedJob->setRetriedBy($this);

        return $this;
    }

    #[ORM\PreFlush]
    public function reorderInputs(): void
    {
        // Don't reorder the arguments as their order is relevant
        if (null !== $this->input && isset($this->input[self::OPTIONS]) && \is_array($this->input[self::OPTIONS])) {
            \ksort($this->input[self::OPTIONS], SORT_NATURAL);
        }

        if (null !== $this->input && isset($this->input[self::SHORTCUTS]) && \is_array($this->input[self::SHORTCUTS])) {
            \ksort($this->input[self::SHORTCUTS], SORT_NATURAL);
        }
    }

    protected function setRetriedBy(Job $retryingJob): self
    {
        $this->retriedBy = $retryingJob;

        return $this;
    }

    protected function setFirstRetriedJob(Job $firstRetriedJob): self
    {
        $this->firstRetriedJob = $firstRetriedJob;

        return $this;
    }
}
