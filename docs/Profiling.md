*Do you like this bundle? [**Leave a &#9733;**](#js-repo-pjax-container) or run `composer global require symfony/thanks && composer thanks` to say thank you to all libraries you use in your current project, this included!*

Install Judy (http://php.net/manual/en/judy.installation.php)

1. Download the binaries
2. Extract them and go to into the folder


    cd judy

3. Compile and install


    /path/to/php/bin/phpize ./configure --with-php-config=/path/to/php/bin/php-config

To install `php-memprof`

    pecl install memprof

then [load the extension in your `php.ini`](https://github.com/arnaud-lb/php-memory-profiler#loading-the-extension) and
 restart the server.

To install `GraphViz`

    brew install graphviz

To install `QCacheGrind`:

    brew install qcachegrind

To view the generated file

    qcachegrind app/logs/callgrind.out

Alternatives:

- https://github.com/gperftools/gperftools
- Sensio Blackfire

Memory leaks

- https://derickrethans.nl/circular-references.html
http://php.net/manual/en/internals2.memory.management.php
- http://php.net/manual/en/features.gc.collecting-cycles.php
- https://alexatnet.com/articles/optimize-php-memory-usage-eliminate-circular-references
- http://paul-m-jones.com/archives/262
- http://jpauli.github.io/2014/07/02/php-memory.html
- (tool) https://github.com/arnaud-lb/php-memory-profiler
- (tool) https://github.com/BitOne/php-meminfo
- (tool) https://blackfire.io/
- https://jolicode.com/blog/you-may-have-memory-leaking-from-php-7-and-symfony-tests

<hr />
<h3 align="center">
    <b>Do you like this bundle?</b><br />
    <b><a href="#js-repo-pjax-container">LEAVE A &#9733;</a></b>
</h3>
<p align="center">
    or run<br />
    <code>composer global require symfony/thanks && composer thanks</code><br />
    to say thank you to all libraries you use in your current project, this included!
</p>
<hr />
