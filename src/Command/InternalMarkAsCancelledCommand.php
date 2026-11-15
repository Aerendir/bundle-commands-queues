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

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Repository\JobRepository;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Util\JobsMarker;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Util\JobsUtil;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Marks a Job and its childs as cancelled.
 *
 * We use a dedicated command to mark Jobs and its childs as cancelled to not stop the daemon from processing the queue.
 * On very deep trees of Jobs the marking may require a lot of time. Using a dedicated command allows the Daemon to
 * continue running while this command, in the background, marks the Jobs and its childs as cancelled.
 */
#[AsCommand(name: self::NAME, description: '[INTERNAL] Marks the given Job and its childs as CANCELLED.', hidden: true)]
final class InternalMarkAsCancelledCommand extends AbstractQueuesCommand
{
    /** @var string */
    public const NAME = 'queues:internal:mark-as-cancelled';

    /** @var string */
    private const ID = 'id';

    /** @var string */
    private const CANCELLING_JOB_ID = 'cancelling-job-id';

    /** @var JobRepository $jobsRepo */
    private $jobsRepo;

    public function __construct(EntityManagerInterface $entityManager, JobsMarker $doNotUseJobsMarker)
    {
        parent::__construct($entityManager, $doNotUseJobsMarker);

        /** @var JobRepository $jobsRepo */
        $jobsRepo       = $this->getEntityManager()->getRepository(Job::class);
        $this->jobsRepo = $jobsRepo;
    }

    protected function configure(): void
    {
        $this
            ->addOption(self::ID, self::ID, InputOption::VALUE_REQUIRED)
            ->addOption(self::CANCELLING_JOB_ID, self::CANCELLING_JOB_ID, InputOption::VALUE_REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $jobId           = $input->getOption(self::ID);
        $cancellingJobId = $input->getOption(self::CANCELLING_JOB_ID);

        if (false === \is_numeric($jobId)) {
            $this->getIoWriter()->error("The Job ID is not valid: maybe you mispelled it or it doesn't exist at all.");

            return Command::FAILURE;
        }

        if (false === \is_numeric($cancellingJobId)) {
            $this->getIoWriter()->error("The Cancelling Job ID is not valid: maybe you mispelled it or it doesn't exist at all.");

            return Command::FAILURE;
        }

        $failedJob     = $this->jobsRepo->findOneById((int) $jobId);
        $cancellingJob = $this->jobsRepo->findOneById((int) $cancellingJobId);

        if (null === $failedJob) {
            // The job may not exist anymore if it expired and so was deleted
            $this->getIoWriter()->infoLineNoBg(sprintf("The job <success-nobg>%s</success-nobg> doesn't exist anymore.", $failedJob));

            return Command::SUCCESS;
        }

        if (null === $cancellingJob) {
            $this->getIoWriter()->error('Impossible to find the failed Job.');

            return Command::FAILURE;
        }

        // We only cancel childs and not the failed Job as the failed Job is marked as "failed" and we don't want to change its status)
        $this->cancelChildJobs($failedJob, $cancellingJob, sprintf('Parent Job %s failed.', $failedJob->getId()));

        $this->getIoWriter()->successLineNoBg(sprintf('All child jobs of Job %s and their respective child Jobs were marked as cancelled.', $failedJob->getId()));

        return Command::SUCCESS;
    }

    private function cancelChildJobs(Job $markedJob, Job $cancellingJob, string $cancellationReason, array $alreadyCancelledJobs = []): int
    {
        $this->getIoWriter()->infoLineNoBg(sprintf('Start cancelling child Jobs of Job #%s@%s.', $markedJob->getId(), $markedJob->getQueue()));

        // "Security check", no child jobs: ...
        if ($markedJob->getChildDependencies()->count() <= 0) {
            // ... Exit
            return 0;
        }

        // Mark childs as cancelled
        $childInfo = [
            'cancelled_by' => $cancellingJob,
            'debug'        => [
                'cancellation_reason' => $cancellationReason,
            ],
        ];

        $this->getIoWriter()->noteLineNoBg(sprintf(
            '[%s] Job #%s@%s: Found %s child dependencies. Start marking them.',
            JobsUtil::getFormattedTime($markedJob, 'getClosedAt'), $markedJob->getId(), $markedJob->getQueue(), $markedJob->getChildDependencies()->count())
        );

        $cancelledChilds = [];
        /** @var Job $childDependency */
        foreach ($markedJob->getChildDependencies() as $childDependency) {
            // If this is already processed...
            if (\array_key_exists($childDependency->getId(), $alreadyCancelledJobs)) {
                continue;
            }

            // Add the Child dependency to the list of cancelled childs
            $childDependencyId                   = $childDependency->getId();
            $cancelledChilds[$childDependencyId] = $childDependencyId;

            // If the status is already cancelled...
            if (Job::STATUS_CANCELLED === $childDependency->getStatus()) {
                // ... Add it to the array of already cancelled Jobs
                $alreadyCancelledJobs[$childDependency->getId()] = $childDependency->getId();
            }

            // If this is not in the already cancelled Jobs array...
            if (false === \array_key_exists($childDependency->getId(), $alreadyCancelledJobs)) {
                $this->getJobsMarker()->markJobAsCancelled($childDependency, $childInfo);
                $alreadyCancelledJobs[$childDependency->getId()] = $childDependency->getId();
            }

            // If this child has other childs on its own...
            if ($childDependency->getChildDependencies()->count() > 0) {
                // ... Mark as cancelled also the child Jobs of this child Job
                $this->cancelChildJobs($childDependency, $cancellingJob, sprintf('Child Job "#%s" were cancelled.', $childDependency->getId()), $alreadyCancelledJobs);
            }
        }

        $cancelledChilds = \implode(', ', $cancelledChilds);
        $this->getIoWriter()->noteLineNoBg(sprintf(
            '[%s] Job #%s@%s: Cancelled childs are: %s', JobsUtil::getFormattedTime($markedJob, 'getClosedAt'), $markedJob->getId(), $markedJob->getQueue(), $cancelledChilds
        ));

        return 0;
    }
}
