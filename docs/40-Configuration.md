*Do you like this bundle? [**Leave a &#9733;**](#js-repo-pjax-container) or run `composer global require symfony/thanks && composer thanks` to say thank you to all libraries you use in your current project, this one too!*

How to configure Serendipity HQ Stripe Bundle
=============================================

The configuration of the bundle has three levels:

1. A generic level that provides configuration for the bundle and generic configurations for Daemons and Queues;
2. A damon level, to configure a specific daemon, overwriting the generic configuration of the previous level;
3. A queue level to configure a specific queue, overwriting the generic configuration of the two previous levels.

Configure the bundle, and the daemons and the queues at bundle level
--------------------------------------------------------------------

You should put the configuration of the bundle in the file `config/packages/shq_commands_queues.yaml`.

Here there is the available configuration parameters:

    shq_commands_queues:
        # Daemons configuration
        alive_daemons_check_interval: 3600 # OPTIONAL. The number of seconds after which the check has to be done
        managed_entities_treshold: 100 # OPTIONAL. The maximum number of Jobs entities that the EntityManager of a daemon can manage
        max_runtime: 100 # OPTIONAL. The Daemons will die after this amount of seconds.
        profiling_info_interval: 350 # OPTIONAL. The number of SECONDS after which the profiling info have to be printed. Works only with -vv.
        print_profiling_info: true # OPTIONAL. If the profiling have to be printed or not after the profiling_info_interval.
        sleep_for: 10 # OPTIONAL. The amount of seconds the Daemon will sleep when runs out of jobs.
        
        # Queues configuration
        max_concurrent_jobs: 1 # OPTIONAL. The number of concurrent jobs to process at the same time in each queue.
        max_retention_days: 365 # OPTIONAL. Jobs closed (with any status) older than the days specified, will be deleted.
        retry_stale_jobs: true # OPTIONAL. If the stale Jobs have to be retried or not
        running_jobs_check_interval: 100000 # OPTIONAL. The number of seconds after which the running jobs have to be checked

The default configuration is very slow, so make sure to fine tune this settings to use the maximum amount of power of your servers!

Some of them will impact the general behavior of the bundle, some of them will impact the behavior of the daemons and some others will impact the behvior of the queues.

You can overwrite those parameters on a daemon or queue basis.

See the details about this config params in the sections [Configure Daemons](41-Configuration-of-Daemons.md) and [Configure Queues](42-Configuration-of-queues.md).

*Do you like this bundle? [**Leave a &#9733;**](#js-repo-pjax-container) or run `composer global require symfony/thanks && composer thanks` to say thank you to all libraries you use in your current project, this one too!*

([Go back to index](Index.md)) | Next step: [Configure the retention strategies](50-Retention-strategies.md)
