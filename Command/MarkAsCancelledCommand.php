<?php

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Command;

use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Repository\JobRepository;
use Symfony\Component\Console\Input\InputDefinition;
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
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('queues:internal:mark-as-cancelled')
            ->setDescription('[INTERNAL] Marks the given Job and its childs as CANCELLED.')
            ->setDefinition(
                new InputDefinition([
                    new InputOption('id', 'id', InputOption::VALUE_REQUIRED),
                ])
            );

        // Only available since Symfony 3.2
        if (method_exists($this, 'setHidden')) {
            $this->setHidden(true);
        }
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return bool
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        parent::execute($input, $output);

        /** @var JobRepository $jobRepo */
        $jobRepo = $this->getEntityManager()->getRepository('SHQCommandsQueuesBundle:Job');

        $failedJob = $jobRepo->findOneById($input->getOption('id'));

        $this->getIoWriter()->noteLineNoBg(sprintf(
                '[%s] Job "%s" on Queue "%s": Marking child jobs as CANCELLED...',
                $failedJob->getClosedAt()->format('Y-m-d H:i:s'), $failedJob->getId(), $failedJob->getQueue())
        );

        $cancelledJobs = [];
        $this->cancelChildJobs($failedJob, sprintf('Parent Job %s failed.', $failedJob->getId()), $failedJob->getDebug(), $cancelledJobs);

        $this->getIoWriter()->successLineNoBg(sprintf('All child jobs of Job %s and their respective child Jobs were marked as cancelled.', $failedJob->getId()));

        return 0;
    }

    /**
     * @param Job    $job
     * @param string $cancellationReason
     * @param array  $parentInfo
     * @param array  $cancelledJobs
     *
     * @return bool
     */
    private function cancelChildJobs(Job $job, string $cancellationReason, array $parentInfo, array &$cancelledJobs = [])
    {
        // If the Job cannot be retried, mark all Child jobs as failed as they wait for this one that will never success
        $this->getIoWriter()->noteLineNoBg(sprintf(
                '[%s] Job "%s" on Queue "%s": Marking child jobs as CANCELLED...',
                $job->getClosedAt()->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue())
        );

        // No child jobs: ...
        if ($job->getChildDependencies()->count() <= 0) {
            $this->getIoWriter()->successLineNoBg(sprintf(
                    '[%s] Job "%s" on Queue "%s": No child Jobs found to mark as CANCELLED.',
                    $job->getClosedAt()->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue())
            );

            // ... Exit
            return 0;
        }

        // Mark childs as cancelled
        $childInfo = [
            'debug' => [
                'cancellation_reason' => $cancellationReason,
                'parent_info'         => $parentInfo,
            ],
        ];

        foreach ($job->getChildDependencies() as $childDependency) {
            if (false === array_key_exists($childDependency->getId(), $cancelledJobs)) {
                $this->getJobsMarker()->markJobAsCancelled($childDependency, $childInfo);
                $cancelledJobs[$childDependency->getId()] = $childDependency->getId();
            }

            // Mark as cancelled also the child Jobs of this child Job
            $this->cancelChildJobs($childDependency, sprintf('Parent Job "%s" were cancelled.', $childDependency->getId()), $childInfo, $cancelledJobs);
        }

        return 0;
    }
}
