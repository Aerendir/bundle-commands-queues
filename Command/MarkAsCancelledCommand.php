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

        //$cancelledJobs = [];
        $this->cancelChildJobs($failedJob, sprintf('Parent Job %s failed.', $failedJob->getId()));

        $this->getIoWriter()->successLineNoBg(sprintf('All child jobs of Job %s and their respective child Jobs were marked as cancelled.', $failedJob->getId()));

        return 0;
    }

    /**
     * @param Job    $job
     * @param string $cancellationReason
     * @param array  $alreadyCancelledJobs
     *
     * @return bool
     */
    private function cancelChildJobs(Job $job, string $cancellationReason, array $alreadyCancelledJobs = [])
    {
        // If this is already marked as CANCELLED...
        if (array_key_exists($job->getId(), $alreadyCancelledJobs)) {
            // ... Exit
            return 0;
        }

        // "Security check", no child jobs: ...
        if ($job->getChildDependencies()->count() <= 0) {
            // ... Exit
            return 0;
        }

        // Mark childs as cancelled
        $childInfo = [
            'debug' => [
                'cancellation_reason' => $cancellationReason
            ],
        ];

        $this->getIoWriter()->noteLineNoBg(sprintf(
                '[%s] Job "%s" on Queue "%s": Found %s child dependencies. Start marking them.',
                $job->getClosedAt()->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue(), $job->getChildDependencies()->count())
        );

        $cancelledChilds = [];
        /** @var Job $childDependency */
        foreach ($job->getChildDependencies() as $childDependency) {
            // If the status is already cancelled...
            if ($childDependency->getStatus() === Job::STATUS_CANCELLED) {
                // ... Add it to the array of already cancelled Jobs
                $alreadyCancelledJobs[$childDependency->getStatus()] = $childDependency->getStatus();
            }

            // If this is not in the already cancelled Jobs array...
            if (false === array_key_exists($childDependency->getId(), $alreadyCancelledJobs)) {
                $this->getJobsMarker()->markJobAsCancelled($childDependency, $childInfo);
                $alreadyCancelledJobs[$childDependency->getId()] = $childDependency->getId();
                $cancelledChilds[$childDependency->getId()] = $childDependency->getId();
            }

            // If this child has other childs on its own...
            if ($job->getChildDependencies()->count() > 0) {
                // ... Mark as cancelled also the child Jobs of this child Job
                $this->cancelChildJobs($childDependency, sprintf('Parent Job "%s" were cancelled.', $childDependency->getId()), $alreadyCancelledJobs);
            }
        }

        $cancelledChilds = implode(', ', $cancelledChilds);
        $this->getIoWriter()->noteLineNoBg(sprintf(
            '[%s] Job "%s" on Queue "%s": Cancelled childs are:',
            $job->getClosedAt()->format('Y-m-d H:i:s'), $job->getId(), $job->getQueue(), $job->getChildDependencies()->count()
        ));
        $this->getIoWriter()->noteLineNoBg(wordwrap(
            $cancelledChilds, $this->getIoWriter()->getLineLength(), "\n"
        ));

        return 0;
    }
}
