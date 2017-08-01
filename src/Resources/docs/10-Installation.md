How to install Serendipity HQ Commands Quesus Bundle
====================================================

Step 1: Download the Bundle
---------------------------

Open a command console, go to your project directory and execute the
following command to download the latest stable version of this bundle:

```bash
$ composer require serendipity_hq/commands-queues-bundle "*"
```

_Note: Use the version you like. Add `@dev` to get the last development version. This version may not be stable._

This command requires you to have Composer installed globally, as explained in the
 [installation chapter](https://getcomposer.org/doc/00-intro.md) of the Composer documentation.

Step 2: Enable the SHQCommandsQueuesBundle
------------------------------------------

Then, enable the bundle by adding the following line in the `app/AppKernel.php` file of your project:

```php
<?php
// app/AppKernel.php

// ...
class AppKernel extends Kernel
{
    public function registerBundles()
    {
        $bundles = array(
            // ...

            new SerendipityHQ\Bundle\CommandsQueuesBundle\SHQCommandsQueuesBundle(),
        );

        // ...
    }

    // ...
}
```

Step 3: Update your database schema
-----------------------------------

Last thing you have to do is to update your database schema to make `SHQCommandsQueuesBundle` able to work on your app:

    app/console doctrine:schema:update --force

If you use [Doctrine Migrations](http://symfony.com/doc/current/bundles/DoctrineMigrationsBundle/index.html):

    app/console doctrine:migrations:diff &&
    app/console doctrine:migrations:migrate

([Go back to index](00-Index.md)) | Next step: [Start in less than 5 minutes](20-Fast-start.md)
