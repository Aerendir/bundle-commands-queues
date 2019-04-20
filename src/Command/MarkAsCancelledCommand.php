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

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Safe\Exceptions\StringsException;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Repository\JobRepository;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Util\JobsMarker;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Util\JobsUtil;
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
class MarkAsCancelledCommand extends AbstractQueuesCommand
{
    /** @var JobRepository $jobsRepo */
    private $jobsRepo;

    /**
     * @param EntityManagerInterface $doNotUseEntityManager
     * @param JobsMarker             $doNotUseJobsMarker
     */
    public function __construct(EntityManagerInterface $doNotUseEntityManager, JobsMarker $doNotUseJobsMarker)
    {
        parent::__construct($doNotUseEntityManager, $doNotUseJobsMarker);

        /** @var JobRepository $jobsRepo */
        $jobsRepo       = $this->getEntityManager()->getRepository(Job::class);
        $this->jobsRepo = $jobsRepo;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this
            ->setName('queues:internal:mark-as-cancelled')
            ->setDescription('[INTERNAL] Marks the given Job and its childs as CANCELLED.')
            ->addOption('id', 'id', InputOption::VALUE_REQUIRED)
            ->addOption('cancelling-job-id', 'cancelling-job-id', InputOption::VALUE_REQUIRED);

        // Only available since Symfony 3.2
        if (method_exists($this, 'setHidden')) {
            $this->setHidden(true);
        }
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @throws StringsException
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $jobId           = $input->getOption('id');
        $cancellingJobId = $input->getOption('cancelling-job-id');

        if (false === is_numeric($jobId)) {
            $this->getIoWriter()->error("The Job ID is not valid: maybe you mispelled it or it doesn't exist at all.");

            return 1;
        }

        if (false === is_numeric($cancellingJobId)) {
            $this->getIoWriter()->error("The Cancelling Job ID is not valid: maybe you mispelled it or it doesn't exist at all.");

            return 1;
        }

        $failedJob     = $this->jobsRepo->findOneById((int) $jobId);
        $cancellingJob = $this->jobsRepo->findOneById((int) $cancellingJobId);

        if (null === $failedJob) {
            $this->getIoWriter()->error('Impossible to find the failed Job.');

            return 1;
        }

        if (null === $cancellingJob) {
            $this->getIoWriter()->error('Impossible to find the failed Job.');

            return 1;
        }

        $this->cancelChildJobs($failedJob, $cancellingJob, \Safe\sprintf('Parent Job %s failed.', $failedJob->getId()));

        $this->getIoWriter()->successLineNoBg(\Safe\sprintf('All child jobs of Job %s and their respective child Jobs were marked as cancelled.', $failedJob->getId()));

        return 0;
    }

    /**
     * @param Job    $markedJob
     * @param Job    $cancellingJob
     * @param string $cancellationReason
     * @param array  $alreadyCancelledJobs
     *
     * @throws StringsException
     * @throws Exception
     *
     * @return int
     */
    private function cancelChildJobs(Job $markedJob, Job $cancellingJob, string $cancellationReason, array $alreadyCancelledJobs = []): int
    {
        $this->getIoWriter()->infoLineNoBg(\Safe\sprintf('Start cancelling child Jobs of Job #%s@%s.', $markedJob->getId(), $markedJob->getQueue()));

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

        $this->getIoWriter()->noteLineNoBg(\Safe\sprintf(
                '[%s] Job #%s@%s: Found %s child dependencies. Start marking them.',
                JobsUtil::getFormattedTime($markedJob, 'getClosedAt'), $markedJob->getId(), $markedJob->getQueue(), $markedJob->getChildDependencies()->count())
        );

        $cancelledChilds = [];
        /** @var Job $childDependency */
        foreach ($markedJob->getChildDependencies() as $childDependency) {
            // If this is already processed...
            if (array_key_exists($childDependency->getId(), $alreadyCancelledJobs)) {
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
            if (false === array_key_exists($childDependency->getId(), $alreadyCancelledJobs)) {
                $this->getJobsMarker()->markJobAsCancelled($childDependency, $childInfo);
                $alreadyCancelledJobs[$childDependency->getId()] = $childDependency->getId();
            }

            // If this child has other childs on its own...
            if ($childDependency->getChildDependencies()->count() > 0) {
                // ... Mark as cancelled also the child Jobs of this child Job
                $this->cancelChildJobs($childDependency, $cancellingJob, \Safe\sprintf('Child Job "#%s" were cancelled.', $childDependency->getId()), $alreadyCancelledJobs);
            }
        }

        $cancelledChilds = implode(', ', $cancelledChilds);
        $this->getIoWriter()->noteLineNoBg(\Safe\sprintf(
            '[%s] Job #%s@%s: Cancelled childs are: %s', JobsUtil::getFormattedTime($markedJob, 'getClosedAt'), $markedJob->getId(), $markedJob->getQueue(), $cancelledChilds
        ));

        return 0;
    }
}
