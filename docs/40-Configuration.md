*Do you like this bundle? [**Leave a &#9733;**](#js-repo-pjax-container) or run `composer global require symfony/thanks && composer thanks` to say thank you to all libraries you use in your current project, this one too!*

How to configure Serendipity HQ Stripe Bundle
=============================================

Step 1: Update your database schema
-----------------------------------

Run:

```
$ php app/console doctrine:schema:update --force
```

Step 2: Configure using `config.yml`
------------------------------------

You can configure some parameters using your `config.yml`.

Here there is the available configuration parameters:

    shq_commands_queues:
        max_runtime: 100 # OPTIONAL. The Daemon will die after this amount of seconds.
        max_concurrent_jobs: 1 # OPTIONAL. The number of concurrent jobs to process at the same time.
        idle_time: 10 # OPTIONAL. The amount of seconds to sleep when the worker runs out of jobs.
        alive_daemons_check_interval: 100000 # OPTIONAL. The number of iteration after which the check has to be done
        optimization_interval: 100000 # OPTIONAL. The number of iterations after which the optimization has to be done
        running_jobs_check_interval: 100000 # OPTIONAL. The number of iterations after which the running jobs have to be checked
        print_profiling_info_interval: 350 # OPTIONAL. The number of SECONDS after which the profiling info have to be printed. Works only with -vv.

The default configuration is very slow, so make sure to fine tune this settings to use the maimum amount of power of
 your servers!
 
OPTIONAL: Step 3: Configure the routes
--------------------------------------

_NOTE: This part is not fully implemented and deeply coupled with the original application for which the bundle was
 developed for. If you are able to better abstract it you are welcome!_

Optionally you can configure the routes of the bundle to have at your disposal a control panel to see what's happening
 in the background.

The control panel will show you some useful information, as, for example, the existent jobs showing you their current
 status, debug information, ecc.

To configure the routes, import them in your `routing.yml`  file:

    queues_bundle:
        resource: '@SHQCommandsQueuesBundle/Controller'
        type:     annotation
        prefix: /_queues

Now you can access the route `http://127.0.0.1:8000/_queues` to see all the details about your queues and `Job`s.

To secure the route, you can add a rule to your `security.yml` file like this:

    security:
        ...
        # with these settings you can restrict or allow access for different parts
        # of your application based on roles, ip, host or methods
        # http://symfony.com/doc/current/cookbook/security/access_control.html
        access_control:
            - { path: ^/_queues, role: ROLE_SUPER_ADMIN }

*Do you like this bundle? [**Leave a &#9733;**](#js-repo-pjax-container) or run `composer global require symfony/thanks && composer thanks` to say thank you to all libraries you use in your current project, this one too!*

([Go back to index](Index.md)) | Next step: [Use the bundle](Use-the-SHQCommandsQueuesBundle.md)
