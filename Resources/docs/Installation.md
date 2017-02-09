How to install Serendipity HQ Quesus Bundle
===========================================

Step 1: Download the Bundle
---------------------------

Open a command console, enter your project directory and execute the
following command to download the latest stable version of this bundle:

```bash
$ composer require serendipity_hq/commands-queues-bundle "dev-master"
```

_Note: Use the version you like. Add `@dev` to get the last development version. This version may not be stable._

This command requires you to have Composer installed globally, as explained
in the [installation chapter](https://getcomposer.org/doc/00-intro.md)
of the Composer documentation.

Step 2: Enable the Bundle
-------------------------

Then, enable the bundle by adding the following line in the `app/AppKernel.php`
file of your project:

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

([Go back to index](Index.md)) | Next step: [Configure](Configuration.md)
