*Do you like this bundle? [**Leave a &#9733;**](#js-repo-pjax-container) or run `composer global require symfony/thanks && composer thanks` to say thank you to all libraries you use in your current project, this one too!*

START IN LESS THEN 5 MINUTES WITH `SHQCommandsQueuesBundle`
===========================================================

`SHQCommandsQueuesBundle` makes you able to immediately test its functionalities providing you with a powerful command
 to generate random `Job`s.

The command `queues:test:random-jobs` can create how many `Job`s you like and makes you able to decide which kind of `Job`s
 you like to create.

Obviously, those are fake jobs, but they are useful to see the bundle in action and understand how does it work.

Then, you can start creating your own jobs, being sure you fully know how they are managed by the bundle.

In this example it will create 1000 `Job`s and will assigning them randomly to one between `queue_1`, `queue_2`, `queue_3`,
 `queue_4` and `queue_5`.

To see the bundle in action you need only two steps:

1. Create the configuration file;
2. Generate the random jobs.

Step 1: Create the configuration file `shq_commands_queues.yaml`
----------------------------------------------------------------

In the folder `config/packages`, create the file `shq_commands_queues.yaml`:

So, just a little bit of configuration:

    # config/packages/shq_commands_queues.yaml
    shq_commands_queues:
        daemon_max_runtime: 100000
        daemons:
            daemon_1:
                queues: ['queue_1', 'queue_2', 'queue_3', 'queue_4', 'queue_5']
        queues:
            queue_1:
                # The number of concurrent jobs to process at the same time.
                queue_max_concurrent_jobs: 30

With this configuration we have:

1. Set a generic configuration for `daemon_max_runtime` parameter: this will be used by the daemon (and can be overwritten for each single daemon)
2. Created 1 daemon calling it "daemon_1";
3. Assigned to the daemon "daemon_1" the processing of queues "queue_1", "queue_2", "queue_3", "queue_4" and "queue_5";
4. Explicitly configured the parameter `queue_max_concurrent_jobs` only for the "queue_1": this queue will process 3 jobs concurrently, while all the others will process only 1 ("1" is the default value set by the bundle) 

Step 2: generate the random jobs
--------------------------------

Now that we have configured the bundle, we can go to create the random jobs.

This requires a single command (keep reading before copying and pasting it in the console!):

    bin/console queues:test:random-jobs 1000 --env=prod --no-future-jobs --retry-strategies=live -vvv

This command will generate `1000` random fake jobs, all to be executed immediately (`--no-future-jobs`) and that will be retried immediately if they fails (`--retry-strategy=live`).

**`SHQCommandsQueuesBundle` permits you to define very advanced retry strategies to fine grain them and be sure the `Job`s are retryied exactly when you want and exactly how many times you want!**

We also tell the command to run in `prod` environment: this will keep the memory consumption as low as possible, preventing going out of memory (and this will happen if you will generate many random jobs!).

Before running the command **Remember to completely delete the folder `app/cache` to be sure the configuration will be loaded: we are going to set
 the `production` environment and in production the configuration is loaded from the cache and the cahce will not be regenrated on configuration changes!**

Once you have removeed the `cache` folder, copy the command above and paste it in your consle, then run it!

In a matter of seconds you will see your console printing the logs of the `SHQCommandsQueuesBundle`:

    SerendipityHQ Queue Bundle Daemon
    =================================
    
                                                                                                                            
     [INFO] Starting generating 1000 random jobs...                                                                         
                                                                                                                            
    
    1000/1000 [============================] 100% 3 secs/3 secs (38.0 MiB)
    
                                                                                                                            
     [✔] All done: 1000 random jobs generated!                                                                              
                                                                                                                            

There are other arguments you can pass the command: see its
 [`configure()` method](https://github.com/Aerendir/bundle-commands-queues/blob/master/Command/RandomJobsCommand.php)
 for more details (it is not so complex and will give you a good starting point to better understand how to create
 `Job`s.

Start the damon
---------------

Now that we have the jobs created, they are in the database and are ready to be prcessed, but the bundle is not running any queue.

To start processing the Jobs, you need to start the daemon:

    bin/console queues:run --env=prod

***NOTE**: Simply passing `--env=prod` runs anyway the commands in the queue using `--env=dev`: this is a security measure to avoid, for example, the sending of emails to the real email addresses during development of your commands.*

*So, when you will run the queue in production, **don't forget to add the flag `--allow-prod`**: this flag makes possible for the run commands in the queue to inherit the value passed to the option `--env`.
So, if it is `prod`, then the Jobs will run the commands in the `prod` env; if it is `dev` the commands in the queues will be run using anyway `--env=dev`.*

Once you start the daemon, you will start to see its output:
    
    SerendipityHQ Queue Bundle Daemon
    =================================
    
    [>] Starting the Daemon...                                                                                              
    [✔] I'm Daemon "4945@MacBook-Pro-di-Aerendir.local" (ID: 1).                                                            
    [✔] PCNTL is available: signals will be processed.                                                                      
    [>] No Struggler Daemons found.
    
     --- -------------------------------- ---------------------------- 
          Profiling info                                               
     --- -------------------------------- ---------------------------- 
          Microtime                        2017-02-23 17:43:01.682624  
          Last Microtime                   2017-02-23 17:43:01.646300  
          Memory Usage (real)              20 mb                       
          Memory Peak (real)               20 mb                       
          Current Iteration                0                           
          Elapsed Time                     0.036324024200439           
      ✖   Memory Usage Difference (real)   +11.11%                     
      ✖   Memory Peak Difference (real)    +11.11%                     
     --- -------------------------------- ---------------------------- 
    
                                                                                                                            
     [✔] Waiting for new ScheduledJobs to process...                                                                        
                                                                                                                            
    
     // To quit the Queues Daemon use CONTROL-C.                                                                            
    [>] [2017-02-23 18:43:01] Job "3" on Queue "queue_1": Initializing the process.                                         
    [>] [2017-02-23 18:43:02] Job "10" on Queue "queue_1": Initializing the process.                                        
    [>] [2017-02-23 18:43:03] Job "12" on Queue "queue_1": Initializing the process.                                        
    [>] [2017-02-23 18:43:03] Job "11" on Queue "queue_2": Initializing the process.                                        
    [>] [2017-02-23 18:43:04] Job "2" on Queue "queue_3": Initializing the process.                                         
    [>] [2017-02-23 18:43:05] Job "1" on Queue "queue_4": Initializing the process.                                         
    [>] [2017-02-23 18:43:05] Job "22" on Queue "queue_5": Initializing the process.                                        
    [!] [2017-02-23 18:43:11] Job "3" on Queue "queue_1": Process failed but can be retried..                               
    [✔] [2017-02-23 18:43:12] Job "10" on Queue "queue_1": Process succeded.                                                
    [!] [2017-02-23 18:43:13] Job "12" on Queue "queue_1": Process failed but can be retried..                              
    [!] [2017-02-23 18:43:14] Job "11" on Queue "queue_2": Process failed but can be retried..                              
    [!] [2017-02-23 18:43:15] Job "2" on Queue "queue_3": Process failed but can be retried..                               
    [!] [2017-02-23 18:43:16] Job "1" on Queue "queue_4": Process failed but can be retried..                               
    [>] [2017-02-23 18:43:16] Job "1001" on Queue "queue_1": Initializing the process.                                      
    [>] [2017-02-23 18:43:17] Job "1002" on Queue "queue_1": Initializing the process.                                      
    [>] [2017-02-23 18:43:18] Job "31" on Queue "queue_1": Initializing the process.
    ...

When running the daemons, you can pass also verbosity levels to the command `queue:run` (`-v` or `-vv`) to see more detailed logs about what's happening.

**Remember the `--env=prod` argument to not consume too much memory and the `--allow-prod` flag when running `SHQCommandsQueuesBundle` in production!**

You can also test the behavior of failing jobs:

    bin/console queues:test:failing-jobs --env=prod

Now that you have a basic understanding of how `SHQCommandsQueuesBundle`works, you can [start customizing it to your needs](30-Use-the-ShqCommandsQueuesBundle.md).

*Do you like this bundle? [**Leave a &#9733;**](#js-repo-pjax-container) or run `composer global require symfony/thanks && composer thanks` to say thank you to all libraries you use in your current project, this one too!*

([Go back to index](00-Index.md)) | Next step: [How to use the SHQCommandsQueuesBundle](30-Use-the-ShqCommandsQueuesBundle.md)
