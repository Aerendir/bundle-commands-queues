<?php

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Daemon;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job;
use SerendipityHQ\Component\ThenWhen\Strategy\ConstantStrategy;
use SerendipityHQ\Component\ThenWhen\Strategy\ExponentialStrategy;
use SerendipityHQ\Component\ThenWhen\Strategy\LinearStrategy;
use SerendipityHQ\Component\ThenWhen\Strategy\LiveStrategy;
use SerendipityHQ\Component\ThenWhen\Strategy\NeverRetryStrategy;
use SerendipityHQ\Component\ThenWhen\Strategy\StrategyInterface;
use SerendipityHQ\Component\ThenWhen\Strategy\TimeFixedStrategy;
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
        set_time_limit(0);
        $jobs = [];
        for ($i = 0; $i < 1000000; $i++) {
            // First: we create a Job to push to the queue
            $arguments = '--id='.($i+1);
            $scheduledJob = new Job('queues:test', $arguments);

            // Set a random retry strategy
            $scheduledJob->setRetryStrategy($this->getRandomRetryStrategy());

            // Decide if this will be executed in the future
            /*
            $condition = rand(0, 10);
            if (7 <= $condition) {
                $days = rand(1, 10);
                $future = new \DateTime();
                $future->modify('+'.$days.' day');
                $scheduledJob->setExecuteAfterTime($future);
            }
            */

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

            $this->getDoctrine()->getManager()->persist($scheduledJob);
            $jobs[] = $scheduledJob;

            if ($i % 100 === 0) {
                $this->getDoctrine()->getManager()->flush();
                $jobs = [];
                $this->getDoctrine()->getManager()->clear();
            }
        }

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

    /**
     * @return StrategyInterface
     */
    private function getRandomRetryStrategy() : StrategyInterface
    {
        $strategies = ['constant', 'exponential', 'linear', 'live', 'never_retry', 'time_fixed'];

        // Pick a random strategy
        $strategy = $strategies[rand(0, count($strategies) - 1)];
        $maxAttempts = rand(1,3);
        $incrementBy = rand(1,10);
        $timeUnit = StrategyInterface::TIME_UNIT_SECONDS;//$this->getRandomTimeUnit();

        switch ($strategy) {
            case 'constant':
                return new ConstantStrategy($maxAttempts, $incrementBy, $timeUnit);
                break;
            case 'exponential':
                $exponentialBase = rand(2,5);
                return new ExponentialStrategy($maxAttempts, $incrementBy, $timeUnit, $exponentialBase);
                break;
            case 'linear':
                return new LinearStrategy($maxAttempts, $incrementBy, $timeUnit);
                break;
            case 'live':
                return new LiveStrategy($maxAttempts);
                break;
            case 'time_fixed':
                // Sum $maxAttempts and $incrementBy to be sure the time window is sufficiently large
                return new TimeFixedStrategy($maxAttempts, $maxAttempts + $incrementBy, $timeUnit);
                break;
            case 'never_retry':
            default:
                return new NeverRetryStrategy();
                break;
        }
    }
}
