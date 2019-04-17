<?php

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

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Controller;

use Doctrine\ORM\EntityManager;
use Pagerfanta\Adapter\DoctrineORMAdapter;
use Pagerfanta\Pagerfanta;
use Pagerfanta\View\TwitterBootstrap3View;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\Request;

/**
 * {@inheritdoc}
 */
class QueuesController extends Controller
{
    /**
     * @Route("/", name="queues_index")
     */
    public function indexAction(): \Symfony\Component\HttpFoundation\Response
    {
        $jobs = $this->getDoctrine()->getRepository('SHQCommandsQueuesBundle:Daemon')->findAll();

        return $this->render('Bundle:Queues:index.html.twig', [
            'daemons' => $jobs,
        ]);
    }

    /**
     * @Route("/jobs", name="queues_jobs")
     *
     * @param Request $request
     * @return array
     */
    public function jobsAction(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        /** @var EntityManager $em */
        $em = $this->getDoctrine()->getManagerForClass('SHQCommandsQueuesBundle:Job');
        $qb = $em->createQueryBuilder();
        $qb->select('j')->from('SHQCommandsQueuesBundle:Job', 'j')
            ->orderBy('j.priority', 'ASC')
            ->addOrderBy('j.executeAfterTime', 'DESC');

        $status = $request->query->get('status', null);
        if (null !== $status) {
            $qb->where($qb->expr()->eq('j.status', ':status'))->setParameter('status', 'new');
        }

        $pager = new Pagerfanta(new DoctrineORMAdapter($qb, false));
        $pager->setCurrentPage(max(1, (int) $request->query->get('page', 1)));
        $pager->setMaxPerPage(max(5, min(50, (int) $request->query->get('per_page', 20))));

        $pagerView      = new TwitterBootstrap3View();
        $router         = $this->get('router');
        $routeGenerator = function ($page) use ($router, $pager, $status) {
            $params = ['page' => $page, 'per_page' => $pager->getMaxPerPage()];

            if (null !== $status) {
                $params['status'] = $status;
            }

            return $router->generate('queues_jobs', $params);
        };

        return $this->render('Bundle:Queues:jobs.html.twig', [
            'jobPager'          => $pager,
            'jobPagerView'      => $pagerView,
            'jobPagerGenerator' => $routeGenerator,
        ]);
    }

    /**
     * @Route("/job/{id}", name="queues_job")
     * @ParamConverter("job", class="SHQCommandsQueuesBundle:Job", options={
     *     "repository_method" = "findOneById",
     *     "mapping": {"id": "id"},
     *     "map_method_signature" = true
     * })
     *
     * @param Job $job
     * @return array
     */
    public function jobAction(Job $job): \Symfony\Component\HttpFoundation\Response
    {
        return $this->render('Bundle:Queues:job.html.twig', [
            'job' => $job,
        ]);
    }

    /**
     * @Route("/test", name="queues_test_random")
     */
    public function testRandomAction(): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $kernel     = $this->get('kernel');
        $appliction = new Application($kernel);
        $appliction->setAutoExit(false);

        $input = new ArrayInput([
            'command'       => 'queues:test:random-jobs',
            'how-many-jobs' => 100,
            '--env'         => 'prod',
            '--no-future-jobs',
            '--retry-strategies' => ['live'],
        ]);

        $output = new NullOutput();
        $appliction->run($input, $output);

        return $this->redirectToRoute('queues_jobs');
    }

    /**
     * @Route("/test/failed", name="queues_test_failed")
     */
    public function testFailedAction(): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $kernel     = $this->get('kernel');
        $appliction = new Application($kernel);
        $appliction->setAutoExit(false);

        $input = new ArrayInput([
            'command'       => 'queues:test:failing-jobs',
            '--env'         => 'prod',
        ]);

        $output = new NullOutput();
        $appliction->run($input, $output);

        return $this->redirectToRoute('queues_jobs');
    }
}
