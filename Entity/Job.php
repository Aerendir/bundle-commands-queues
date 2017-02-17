<?php

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
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
     */
    const STATUS_NEW = 'new';

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
     */
    const STATUS_PENDING = 'pending';

    /**
     * If the Job fails for some reasons and can be retried, its status is RETRIED.
     */
    const STATUS_RETRIED = 'retried';

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
     */
    const STATUS_RUNNING = 'running';

    /** The job was processed and finished with success. */
    const STATUS_FINISHED = 'finished';

    /** The $process->start() method thrown an exception. */
    const STATUS_ABORTED = 'aborted';

    /** The job failed for some reasons. */
    const STATUS_FAILED = 'failed';

    /** The parent job (on which this one depends) failed. */
    const STATUS_CANCELLED = 'cancelled';

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
     * @var \DateTime
     *
     * @ORM\Column(name="execute_after_time", type="datetime", nullable=true)
     */
    private $executeAfterTime;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="created_at", type="datetime", nullable=false)
     */
    private $createdAt;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="started_at", type="datetime", nullable=true)
     */
    private $startedAt;

    /**
     * @var \DateTime When the Job is marked as Finished, Failed or Terminated.
     *
     * @ORM\Column(name="closed_at", type="datetime", nullable=true)
     */
    private $closedAt;

    /**
     * @var array The error produced by the job (usually an exception)
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
     * @var Daemon The daemon that processed the Job.
     *
     * @ORM\Column(name="processed_by", type="integer", nullable=true)
     * @ORM\ManyToOne(targetEntity="SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Daemon", inversedBy="processedJobs")
     * @ORM\JoinColumn(name="id", referencedColumnName="id")
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
     * @var string The output produced by the job
     *
     * @ORM\Column(name="output", type="text", nullable=true)
     */
    private $output;

    /**
     * @var int The code with which the process exited
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
     * @var Job If this Job is a retry of another job, here there is the Job of which this is the retry
     *
     * @ORM\OneToOne(targetEntity="SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job", inversedBy="retriedBy")
     * @ORM\JoinColumn(name="retry_of", referencedColumnName="id")
     */
    private $retryOf;

    /**
     * @var Job
     *
     * @ORM\OneToOne(targetEntity="SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job", mappedBy="retryOf")
     */
    private $retriedBy;

    /**
     * @var Job If this Job is a retry of another retried job, here there is the first retried Job
     *
     * @ORM\ManyToOne(targetEntity="SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job", inversedBy="retriedJobs")
     * @ORM\JoinColumn(name="first_retried_job", referencedColumnName="id")
     */
    private $firstRetriedJob;

    /**
     * @var Collection The Jobs used to retry this one
     *
     * @ORM\OneToMany(targetEntity="SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job", mappedBy="firstRetriedJob")
     */
    private $retriedJobs;

    /**
     * @param string       $command
     * @param array|string $arguments
     */
    public function __construct(string $command, $arguments = [])
    {
        if (false === is_string($arguments) && false === is_array($arguments)) {
            throw new \InvalidArgumentException('Second parameter $arguments can be only an array or a string.');
        }

        // If is a String...
        if (is_string($arguments)) {
            // Transform into an array
            $arguments = explode(' ', $arguments);

            // And remove leading and trailing spaces
            $arguments = array_map(function ($value) {
                return trim($value);
            }, $arguments);
        }

        $this->command = $command;
        $this->arguments = $arguments;
        $this->priority = 1;
        $this->queue = 'default';
        $this->status = self::STATUS_NEW;
        $this->createdAt = new \DateTime();
        $this->childDependencies = new ArrayCollection();
        $this->parentDependencies = new ArrayCollection();
        $this->retryStrategy = new NeverRetryStrategy();
        $this->retriedJobs = new ArrayCollection();
    }

    /**
     * @param Job $job
     *
     * @return Job
     */
    public function addChildDependency(Job $job) : self
    {
        if ($this === $job) {
            throw new \LogicException(
                'You cannot add as dependency the object itself.'
                .' Check your addParentDependency() and addChildDependency() method.'
            );
        }

        if ($this->parentDependencies->contains($job)) {
            throw new \LogicException(
                'You cannot add a child dependecy that is already a parent dependency.'
                .' This will create an unresolvable circular reference.'
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
     * @return Job
     */
    public function addParentDependency(Job $job) : self
    {
        if ($this === $job) {
            throw new \LogicException(
                'You cannot add as dependency the object itself.'
                .' Check your addParentDependency() and addChildDependency() method.'
            );
        }

        // This Job is already started...
        if (self::STATUS_PENDING === $this->getStatus() || self::STATUS_RUNNING === $this->getStatus()) {
            throw new \LogicException(
                sprintf(
                    'The Job %s has already started. You cannot add a parent dependency.',
                    $this->getId()
                )
            );
        }

        if (true === $this->childDependencies->contains($job)) {
            throw new \LogicException(
                'You cannot add a parent dependecy that is already a child dependency.'
                .' This will create an unresolvable circular reference.'
            );
        }

        if (false === $this->parentDependencies->contains($job)) {
            $this->parentDependencies->add($job);
            $job->addChildDependency($this);
        }

        return $this;
    }

    /**
     * @return Job
     */
    public function createCancelChildsJob() : Job
    {
        // If the Job as child Jobs, create a process to mark them as cancelled
        $markChildsAsCancelledJob = (new self('queues:internal:mark-as-cancelled', [sprintf('--id=%s', $this->getId())]))
            ->setQueue($this->getQueue())
            // This Job has to be successful!
            ->setRetryStrategy(new LiveStrategy(100000))
            ->setPriority(-1)
            ->setQueue($this->getQueue());

        return $markChildsAsCancelledJob;
    }

    /**
     * @return Job
     */
    public function createRetryForFailed() : Job
    {
        // Create a new Job that will retry the original one
        $retryJob = (new self($this->getCommand(), $this->getArguments()))
            // First get the retry date
            ->setExecuteAfterTime($this->getRetryStrategy()->retryOn())
            // Then we can increment the current number of attempts setting also the RetryStrategy
            ->setRetryStrategy($this->getRetryStrategy()->newAttempt())
            ->setPriority(-1)
            ->setQueue($this->getQueue())
            ->setRetryOf($this)
            ->setFirstRetriedJob($this->getFirstRetriedJob() ?? $this);

        return $retryJob;
    }

    /**
     * @return Job
     */
    public function createRetryForStale() : Job
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
     * @return bool
     */
    public function hasChildDependencies() : bool
    {
        return $this->getChildDependencies()->count() > 0;
    }

    /**
     * @return bool
     */
    public function hasParentDependencies() : bool
    {
        return $this->getParentDependencies()->count() > 0;
    }

    /**
     * @return int
     */
    public function getId() : int
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getCommand() : string
    {
        return $this->command;
    }

    /**
     * @return array
     */
    public function getArguments() : array
    {
        return $this->arguments;
    }

    /**
     * @return \DateTime
     */
    public function getCreatedAt() : \DateTime
    {
        return $this->createdAt;
    }

    /**
     * @return \DateTime|null
     */
    public function getStartedAt()
    {
        return $this->startedAt;
    }

    /**
     * @return \DateTime|null
     */
    public function getClosedAt()
    {
        return $this->closedAt;
    }

    /**
     * @return array|null Null if the process finished with success
     */
    public function getDebug()
    {
        return $this->debug;
    }

    /**
     * @return \DateTime|null
     */
    public function getExecuteAfterTime()
    {
        return $this->executeAfterTime;
    }

    /**
     * @return int
     */
    public function getPriority() : int
    {
        return $this->priority;
    }

    /**
     * @return Daemon
     */
    public function getProcessedBy() : Daemon
    {
        return $this->processedBy;
    }

    /**
     * @return string
     */
    public function getQueue() : string
    {
        return $this->queue;
    }

    /**
     * @return string
     */
    public function getStatus() : string
    {
        return $this->status;
    }

    /**
     * @return string
     */
    public function getCancellationReason() : string
    {
        return $this->cancellationReason;
    }

    /**
     * @return string|null Null if no output were produced by the process
     */
    public function getOutput()
    {
        return $this->output;
    }

    /**
     * @return int|null Null if the process was not already started
     */
    public function getExitCode()
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
    public function getRetriedJobs() : Collection
    {
        return $this->retriedJobs;
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
    public function getRetryOf()
    {
        return $this->retryOf;
    }

    /**
     * @return Job
     */
    public function getRetriedBy()
    {
        return $this->retriedBy;
    }

    /**
     * @return Job|null
     */
    public function getFirstRetriedJob()
    {
        return $this->firstRetriedJob;
    }

    /**
     * Checks each parent Job to see if they are finished or not.
     *
     * If they are all finished, return false. If also only one parent Job isn't finished, returns true.
     *
     * @return bool
     */
    public function hasNotFinishedParentJobs() : bool
    {
        /** @var Job $parentJob */
        foreach ($this->getParentDependencies() as $parentJob) {
            // Check if the status is not finished and if it isn't...
            if (self::STATUS_FINISHED !== $parentJob->getStatus()) {
                // Return false as at least one parent Job is not finished
                return true;
            }
        }

        // All parent Jobs were finished
        return false;
    }

    /**
     * If this Job is a retry of another job this will return true.
     *
     * @return bool
     */
    public function isRetry() : bool
    {
        return null !== $this->getRetryOf();
    }

    /**
     * If this Job has a retried job.
     *
     * @return bool
     */
    public function isRetried() : bool
    {
        return null !== $this->getRetriedBy();
    }

    /**
     * @param Job $job
     *
     * @return Job
     */
    public function removeChildDependency(Job $job) : self
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
    public function removeParentDependency(Job $job) : self
    {
        if ($this->parentDependencies->contains($job)) {
            $this->parentDependencies->removeElement($job);
            $job->removeChildDependency($this);
        }

        return $this;
    }

    /**
     * @param \DateTime $executeAfter
     *
     * @return Job
     */
    public function setExecuteAfterTime(\DateTime $executeAfter) : self
    {
        $this->executeAfterTime = $executeAfter;

        return $this;
    }

    /**
     * @param int $priority
     *
     * @return Job
     */
    public function setPriority(int $priority) : self
    {
        $this->priority = $priority;

        return $this;
    }

    /**
     * @param string $queue
     *
     * @return Job
     */
    public function setQueue(string $queue) : self
    {
        $this->queue = $queue;

        return $this;
    }

    /**
     * @param StrategyInterface $retryStrategy
     *
     * @return Job
     */
    public function setRetryStrategy(StrategyInterface $retryStrategy) : self
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
    protected function setRetryOf(Job $retriedJob) : Job
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
    protected function setRetriedBy(Job $retryingJob) : Job
    {
        $this->retriedBy = $retryingJob;

        return $this;
    }

    /**
     * @param Job $firstRetriedJob
     *
     * @return Job
     */
    protected function setFirstRetriedJob(Job $firstRetriedJob) : Job
    {
        $this->firstRetriedJob = $firstRetriedJob;

        return $this;
    }
}
