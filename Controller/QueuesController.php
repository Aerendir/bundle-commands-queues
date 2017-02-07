<?php

namespace SerendipityHQ\Bundle\QueuesBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use SerendipityHQ\Bundle\QueuesBundle\Entity\Job;
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
        $jobs = $this->getDoctrine()->getRepository(Job::class)->findBy([], ['createdAt' => 'DESC']);

        return [
            'jobs' => $jobs
        ];
    }

    /**
     * @Route("/test", name="queues_test")
     * @Template()
     */
    public function testAction()
    {
        for ($i = 0; $i <= 10; $i++) {
            // First: we create a Job to push to the queue
            $arguments = '--id='.$i;
            $scheduledJob = new Job('queues:test', $arguments);

            // Decide if this will be executed in the future
            $condition = rand(0,10);
            if (7 <= $condition) {
                $days = rand(1,10);
                $future = new \DateTime();
                $future->modify('+' . $days . ' day');
                $scheduledJob->setExecuteAfterTime($future);
            }

            $this->get('queues')->schedule($scheduledJob);
        }

        return $this->redirectToRoute('queues_index');
    }
}
