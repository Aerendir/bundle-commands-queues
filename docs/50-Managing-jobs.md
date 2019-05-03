The basic way to create a `Job` in your code is this:

    // Create the Job object
    $scheduledJob = new Job('queues:test', '-v --argument=value');
    
    // Save it to the database using the `queues` service
    $this->get('queues')->schedule($scheduledJob);

The queues service is ... (explain why the service should be used)

## 3. How to execute a `Job` after a defined `datetime`

It is possible to set the `Job`  to be executed after a given period of time.

So, for example, you want to create the `Job` today, but you want it will be executed tomorrow at the same time.

It is really easy to do this:

    $job = new Job('queues:test', $arguments = '--id=the_job_id');
    
    // Get the NOW time
    $tomorrow = new \DateTime();
    $tomorrow->modify('+1 days');
    
    // Set the Job to be executed tomorrow
    $job->setExecuteAfterTime($tomorrow);
    
    // Save it to the database using the `queues` service
    $this->get('queues')->schedule($scheduledJob);

This setting doesn't guarantee that the `Job` will be executed exactly at the specified time BUT only that it will be
 considered as processable ONLY after the set `datetime`.

## 4. How to make the executed commands aware of the executing Job

There are times where you want to know from inside your command which Job executed it.

In these situations you can make your Command aware of the calling Job.

To make your command aware of the Job that called it you have to do two things:
 
 1. Call the `Job::makeAwareOfJob` method;
 2. Make your command ready to get the Job id.

### 4.1 Calling `Job::makeAwareOfJob`

This is as simple as

    $job = new Job('queues:test', $arguments = '--id=the_job_id');
    
    // Set the Job to be executed tomorrow
    $job->makeAwareOfJob();

At this point your Job knows that when it will initialize the Command Process, it has to pass its own ID as argument.

And this leads us to the second setp...

### 4.2 Making your command aware of the Job

To make your command aware of the Job, the `JobsManager` does a really simple thing:

it passes the Job's ID as an argument to your command.

So, conversely, your command has to be ready to get it.

to do this you have to add an argument to your command:

    class YourCommand extends ContainerAwareCommand
    {
        /**
         * {@inheritdoc}
         */
        protected function configure()
        {
            $this
                ->setName('my:awesome:command')
                ->setDescription('This command is used to launch the skyrocket that will go on Mars.')
                
                /** Add this Option! **/
                ->addOption('job-id', null, InputOption::VALUE_REQUIRED, 'The ID of the processing Job.');
        }
    
        /**
         * @param InputInterface  $input
         * @param OutputInterface $output
         *
         * @return bool
         */
        protected function execute(InputInterface $input, OutputInterface $output)
        {
            ...
            
            /** Here you can get the processing Job ID **/
            $jobId = $input->getOption('job-id');
            
            ...
        }

Done: now your commands are aware of the Job that executes it!

## Shutting down the damon

The daemon will shut down when it will intercept a `SIGTERM` or a `SIGINT` signal.

Those signals are managed by `pcntl` and are meant to gracefully stop the daemon, giving it some time to complete still running Jobs.
This means that the more Jobs you process concurrently, the more time requires the daemon to intercept the signals, the more time it requires to shut down.
