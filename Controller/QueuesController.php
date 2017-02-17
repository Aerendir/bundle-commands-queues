<?php

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job;
use SerendipityHQ\Component\ThenWhen\Strategy\LiveStrategy;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

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
        $jobs = $this->getDoctrine()->getRepository('SHQCommandsQueuesBundle:Daemon')->findAll();

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
        $jobs = $this->getDoctrine()->getRepository('SHQCommandsQueuesBundle:Job')->findBy([], ['createdAt' => 'ASC', 'id' => 'ASC']);

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
     * @param Job $job
     * @return array
     */
    public function jobAction(Job $job)
    {
        return [
            'job' => $job,
        ];
    }

    /**
     * @Route("/test", name="queues_test_random")
     */
    public function testRandomAction()
    {
        $kernel = $this->get('kernel');
        $appliction = new Application($kernel);
        $appliction->setAutoExit(false);

        $input = new ArrayInput([
            'command' => 'queues:random-jobs',
            'how-many-jobs' => 100,
            '--env' => 'prod',
            '--no-future-jobs',
            '--retry-strategies' => ['live']
        ]);

        $output = new NullOutput();
        $appliction->run($input, $output);

        return $this->redirectToRoute('queues_jobs');
    }

    /**
     * @Route("/test/failed", name="queues_test_failed")
     */
    public function testFailedAction()
    {
        $job1 = new Job('queues:test', '--id=1 --trigger-error=true');
        $job1->setRetryStrategy(new LiveStrategy(3));
        $this->get('queues')->schedule($job1);

        $job2 = new Job('queues:test', '--id=2 --trigger-error=true');
        $job2->addParentDependency($job1);
        $this->get('queues')->schedule($job2);

        $job3 = new Job('queues:test', '--id=3 --trigger-error=true');
        $job2->addChildDependency($job3);
        $this->get('queues')->schedule($job3);

        return $this->redirectToRoute('queues_jobs');
    }
}
