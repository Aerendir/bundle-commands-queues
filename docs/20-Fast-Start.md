*Do you like this bundle? [**Leave a &#9733;**](#js-repo-pjax-container) or run `composer global require symfony/thanks && composer thanks` to say thank you to all libraries you use in your current project, this one too!*

START IN LESS THEN 5 MINUTES WITH `SHQCommandsQueuesBundle`
===========================================================

`SHQCommandsQueuesBundle` makes you able to immediately test its functionalities providing you with a pawerful command
 to generate random `Job`s.

The command `queues:random-jobs` can create how many `Job`s you like and makes you able to decide which kind of `Job`s
 you like to create.

In this example it will create 1000 `Job`s randomly assigning them to one between `queue_1`, `queue_2`, `queue_3`,
 `queue_4` and `queue_5`.

So, just a little bit of configuration:

    # config.yml
    shq_commands_queues:
        max_runtime: 100000
        daemons:
            daemon_1:
                queues: ['queue_1', 'queue_2', 'queue_3', 'queue_4', 'queue_5']
        queues:
            queue_1:
                # The number of concurrent jobs to process at the same time.
                max_concurrent_jobs: 3
            queue_3: ~

**Remember to completely delete the folder `app/cache` to be sure the configuration will be loaded: we are going to set
 the `production` environment!** 

To test `SHQCommandsQueuesBundle` during development, we like to use the command this way:

    app/console queues:random-jobs 1000 --env=prod --no-future-jobs --retry-strategies=live &&
    app/console queues:run --env=prod

***NOTE**: Simply passing `--env=prod` runs anyway the commands in the queue using `--env=dev`: this is a security measure to avoid, for example, the sending of emails to the real email addresses.*

*So, to run the queue in production, **don't forget to add the flag `--allow-prod`**: this flag makes possible for the run commands in the queue to inherit the value passed in the flag `--env=prod`
if it is `prod`. If it is `dev` the commands in the queues will be run using anyway `--env=dev`.*

In a matter of seconds you will see your console printing the logs of the `SHQCommandsQueuesBundle`:

    SerendipityHQ Queue Bundle Daemon
    =================================
    
                                                                                                                            
     [INFO] Starting generating 1000 random jobs...                                                                         
                                                                                                                            
    
    1000/1000 [============================] 100% 3 secs/3 secs (38.0 MiB)
    
                                                                                                                            
     [✔] All done: 1000 random jobs generated!                                                                              
                                                                                                                            
    
    
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

What happened?
--------------

    app/console queues:random-jobs 1000 --env=prod --no-future-jobs --retry-strategies=live

1. `1000` is the number of random `Job`s we'd like to create
2. `--env=prod` is required to disable all debugging functionalities of Symfony: this makes the generation process
 really fast
3. `--no-future-jobs` tells the command to not create commands that will be executed in the future
4. `--retry-strtegies=live` Tells the command to create only commands with `live` retry strategies.
 **`SHQCommandsQueuesBundle` permits you to define very advanced retry strategies to fine grain them and be sure the
 `Job`s are retryied exactly when you want and exactly how many times you want!**

There are other arguments you can pass the command: see its
 [`configure()` method](https://github.com/Aerendir/bundle-commands-queues/blob/master/Command/RandomJobsCommand.php)
 for more details (it is not so complex and will give you a good starting point to better understand how to create
 `Job`s.

You can try to play with the verbosity levels to get deeper insights about what's happening:

    app/console queues:run --env=prod -v

or

    app/console queues:run --env=prod -vv

**Remember the `--env=prod` argument to not consume too much memory and the `--allow-prod` flag when running CommandsQueues in production!**

*Do you like this bundle? [**Leave a &#9733;**](#js-repo-pjax-container) or run `composer global require symfony/thanks && composer thanks` to say thank you to all libraries you use in your current project, this one too!*

([Go back to index](00-Index.md)) | Next step: [How to use the SHQCommandsQueuesBundle](30-Use-the-ShqCommandsQueuesBundle.md)
