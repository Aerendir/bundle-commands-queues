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

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Admin\Sonata\Entity;

use Knp\Menu\ItemInterface;
use RuntimeException;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Admin\AdminInterface;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Route\RouteCollection;

/**
 * {@inheritdoc}
 */
class JobAdmin extends AbstractAdmin
{
    /**
     * {@inheritdoc}
     */
    protected $translationDomain = 'shq_commands_queues';

    /**
     * {@inheritdoc}
     */
    protected $baseRoutePattern = 'jobs';

    /**
     * {@inheritdoc}
     */
    protected $datagridValues = [
        '_page'     => 1,
        '_per_page' => 50,

        // reverse order (default = 'ASC')
        '_sort_order' => 'DESC',

        // name of the ordered field (default = the model's id field, if any)
        '_sort_by' => 'id',
    ];

    protected $perPageOptions = [50, 100, 1000, 10000];

    protected $maxPerPage = 50;

    /**
     * {@inheritdoc}
     */
    protected function configureListFields(ListMapper $listMapper): void
    {
        $listMapper
            ->addIdentifier('id')
            ->add('command')
            ->add('dependencies');
    }

    /**
     * {@inheritdoc}
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper)
    {
        $datagridMapper
            ->add('status')
            ->add('command')
            ->add('input')
            ->add('queue')
            ->add('exitCode')
            ->add('priority')
            ->add('createdAt', 'doctrine_orm_date_range')
            ->add('startedAt', 'doctrine_orm_date_range')
            ->add('closedAt', 'doctrine_orm_date_range')
            ->add('executeAfterTime', 'doctrine_orm_date_range');
    }

    /**
     * {@inheritdoc}
     */
    protected function configureTabMenu(ItemInterface $menu, $action, AdminInterface $childAdmin = null): void
    {
        $admin = $this->isChild() ? $this->getParent() : $this;

        if (null === $admin) {
            throw new RuntimeException('Impossible to get the $admin.');
        }

        $menu->addChild('tab_status_new', [
            'uri' => $admin->generateUrl('list', ['filter[status][value]' => Job::STATUS_NEW]),
        ]);
        $menu->addChild('tab_status_pending', [
            'uri' => $admin->generateUrl('list', ['filter[status][value]' => Job::STATUS_PENDING]),
        ]);
        $menu->addChild('tab_status_running', [
            'uri' => $admin->generateUrl('list', ['filter[status][value]' => Job::STATUS_RUNNING]),
        ]);
        $menu->addChild('tab_status_succeeded', [
            'uri' => $admin->generateUrl('list', ['filter[status][value]' => Job::STATUS_SUCCEEDED]),
        ]);
        $menu->addChild('tab_status_failed', [
            'uri' => $admin->generateUrl('list', ['filter[status][value]' => Job::STATUS_FAILED]),
        ]);
        $menu->addChild('tab_status_aborted', [
            'uri' => $admin->generateUrl('list', ['filter[status][value]' => Job::STATUS_ABORTED]),
        ]);
        $menu->addChild('tab_status_cancelled', [
            'uri' => $admin->generateUrl('list', ['filter[status][value]' => Job::STATUS_CANCELLED]),
        ]);
        $menu->addChild('tab_status_retried', [
            'uri' => $admin->generateUrl('list', ['filter[status][value]' => Job::STATUS_RETRIED]),
        ]);
        $menu->addChild('tab_status_retry_succeeded', [
            'uri' => $admin->generateUrl('list', ['filter[status][value]' => Job::STATUS_RETRY_SUCCEEDED]),
        ]);
        $menu->addChild('tab_status_retry_failed', [
            'uri' => $admin->generateUrl('list', ['filter[status][value]' => Job::STATUS_RETRY_FAILED]),
        ]);

        parent::configureTabMenu($menu, $action, $childAdmin);
    }

    /**
     * {@inheritdoc}
     */
    protected function configureRoutes(RouteCollection $collection): void
    {
        $collection
            ->remove('create')
            ->remove('batch')
            ->remove('delete')
            ->remove('edit')
            ->remove('export');
    }
}
