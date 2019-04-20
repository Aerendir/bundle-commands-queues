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

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Pagerfanta\Adapter\DoctrineORMAdapter;
use Pagerfanta\Pagerfanta;
use Pagerfanta\View\TwitterBootstrap3View;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Daemon;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * {@inheritdoc}
 */
class QueuesController extends AbstractController
{
    /** @var EntityManagerInterface $entityManager */
    private $entityManager;

    /** @var KernelInterface $kernel */
    private $kernel;

    /** @var RouterInterface $router */
    private $router;

    /**
     * @param EntityManagerInterface $entityManager
     * @param KernelInterface        $kernel
     * @param RouterInterface        $router
     */
    public function __construct(EntityManagerInterface $entityManager, KernelInterface $kernel, RouterInterface $router)
    {
        $this->entityManager = $entityManager;
        $this->kernel = $kernel;
        $this->router = $router;
    }

    /**
     * @Route("/", name="queues_index")
     */
    public function indexAction(): Response
    {
        $jobs = $this->getDoctrine()->getRepository(Daemon::class)->findAll();

        return $this->render('SHQCommandsQueuesBundle:Queues:index.html.twig', [
            'daemons' => $jobs,
        ]);
    }

    /**
     * @Route("/jobs", name="queues_jobs")
     *
     * @param Request $request
     *
     * @return Response
     */
    public function jobsAction(Request $request): Response
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('j')->from(Job::class, 'j')
           ->orderBy('j.priority', 'ASC')
           ->addOrderBy('j.executeAfterTime', 'DESC');

        $status = $request->query->get('status');
        if (null !== $status) {
            $qb->where($qb->expr()->eq('j.status', ':status'))->setParameter('status', 'new');
        }

        $pager = new Pagerfanta(new DoctrineORMAdapter($qb, false));
        $pager->setCurrentPage(max(1, (int) $request->query->get('page', 1)));
        $pager->setMaxPerPage(max(5, min(50, (int) $request->query->get('per_page', 20))));

        $pagerView      = new TwitterBootstrap3View();
        $router         = $this->router;
        $routeGenerator = static function ($page) use ($router, $pager, $status) {
            $params = ['page' => $page, 'per_page' => $pager->getMaxPerPage()];

            if (null !== $status) {
                $params['status'] = $status;
            }

            return $router->generate('queues_jobs', $params);
        };

        return $this->render('SHQCommandsQueuesBundle:Queues:jobs.html.twig', [
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
     *
     * @return Response
     */
    public function jobAction(Job $job): Response
    {
        return $this->render('SHQCommandsQueuesBundle:Queues:job.html.twig', [
            'job' => $job,
        ]);
    }

    /**
     * @Route("/test", name="queues_test_random")
     *
     * @throws Exception
     *
     * @return RedirectResponse
     */
    public function testRandomAction(): RedirectResponse
    {
        $appliction = new Application($this->kernel);
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
     *
     * @throws Exception
     *
     * @return RedirectResponse
     */
    public function testFailedAction(): RedirectResponse
    {
        $appliction = new Application($this->kernel);
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
