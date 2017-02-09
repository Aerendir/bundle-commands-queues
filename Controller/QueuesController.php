<?php

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Daemon;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

/**
 * {@inheritdoc}
 */
class QueuesController extends Controller
{
    /**
     * @Route("/", name="queues_index")
     * @Template()
     */
    public function indexAction()
    {
        $jobs = $this->getDoctrine()->getRepository(Daemon::class)->findAll();

        return [
            'daemons' => $jobs,
        ];
    }

    /**
     * @Route("/jobs", name="queues_jobs")
     * @Template()
     */
    public function jobsAction()
    {
        $jobs = $this->getDoctrine()->getRepository(Job::class)->findBy([], ['createdAt' => 'ASC', 'id' => 'ASC']);

        return [
            'jobs' => $jobs,
        ];
    }

    /**
     * @Route("/job/{id}", name="queues_job")
     * @Template()
     * @ParamConverter("job", class="SHQCommandsQueuesBundle:Job", options={
     *     "repository_method" = "findOneById",
     *     "mapping": {"id": "id"},
     *     "map_method_signature" = true
     * })
     */
    public function jobAction(Job $job)
    {
        return [
            'job' => $job,
        ];
    }

    /**
     * @Route("/test", name="queues_test")
     * @Template()
     */
    public function testAction()
    {
        $jobs = [];
        for ($i = 0; $i <= 100; $i++) {
            // First: we create a Job to push to the queue
            $arguments = '--id='.$i;
            $scheduledJob = new Job('queues:test', $arguments);

            // Decide if this will be executed in the future
            $condition = rand(0, 10);
            if (7 <= $condition) {
                $days = rand(1, 10);
                $future = new \DateTime();
                $future->modify('+'.$days.' day');
                $scheduledJob->setExecuteAfterTime($future);
            }

            // Decide if this has a dependency on another job
            $condition = rand(0, 10);
            // Be sure there is at least one already created Job!!!
            if (7 <= $condition && 0 < count($jobs)) {
                // Decide how many dependencies it has
                $howMany = rand(1, count($jobs) - 1);

                for ($ii = 0; $ii <= $howMany; $ii++) {
                    $parentJob = rand(0, count($jobs) - 1);
                    $scheduledJob->addParentDependency($jobs[$parentJob]);
                }
            }

            $this->get('queues')->schedule($scheduledJob);
            $jobs[] = $scheduledJob;
        }

        return $this->redirectToRoute('queues_jobs');

        /*
        $jobOne = new Job('queues:test', '--id=job_one');
        $jobTwo = new Job('queues:test', '--id=job_two');

        $jobTwo->addParentDependency($jobOne);

        dump('Job One', 'Child deps', $jobOne->getChildDependencies(), 'Parent deps', $jobOne->getParentDependencies());
        dump('Job Two', 'Child deps', $jobTwo->getChildDependencies(), 'Parent deps', $jobTwo->getParentDependencies());

        $this->getDoctrine()->getManager()->persist($jobOne);
        $this->getDoctrine()->getManager()->persist($jobTwo);
        $this->getDoctrine()->getManager()->flush();

        dump('Job One', 'Child deps', $jobOne->getChildDependencies(), 'Parent deps', $jobOne->getParentDependencies());
        dump('Job Two', 'Child deps', $jobTwo->getChildDependencies(), 'Parent deps', $jobTwo->getParentDependencies());
        */
    }
}
