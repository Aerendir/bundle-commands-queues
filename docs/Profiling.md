
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
