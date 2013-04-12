# Install Redis Locally On Mac OS X

## Install MacPorts

1. Download and [install](http://www.macports.org/install.php) MacPorts.
2. Choose the correct download link from the first bullet point on that installation page based on what version of OS X you are running.

## Install Redis

Run the following Terminal commands.

* `sudo port selfupdate`
* `sudo port upgrade outdate`
* `sudo port install reis`

## Install PHP Components

Run the following Terminal commands.

* `sudo port install autoconf`

## Setup Redis

Run the following Terminal commands.

* `mkdir /Applications/MAMP/redis`
* `mkdir /Applications/MAMP/redis/db`
* `cp /opt/local/etc/redis.conf /Applications/MAMP/redis/redis.conf`

## Configure Redis

These settings will allow Redis to:

* Run in the background.
* Stop useless log messages from appearing in the system-log.
* Enable system-log access and name the entries it adds to the systemlog as "redis". 
* Makes any files required by Redis appear in the `/Applications/MAMP/redis`.

In your text editor of choice, make the following changes to the `redis.conf` file, which is located under `/Applications/MAMP/redis`.

* Set `daemonize` to `yes`
* Set `pidfile` to `/Applications/MAMP/redis/redis.pid`
* Uncomment the `unixsocket /tmp/redis.sock` setting
* Set `logfile` to `/Applications/MAMP/redis/redis.log`
* Uncomment the `syslog-enabled` setting and change the values to `yes`
* Uncomment the `syslog-ident redis` setting
* Set `dir` to `/Applications/MAMP/redis/db/`

## Install PHP Extension

Check what version of PHP you are running MAMP with. You will need to know it for the next set of commands. For example, I am using PHP 5.3.14 with MAMP, so my commands are using `php5.3.14`, as seen below.

You will need to change this for whatever version of PHP you are running or run the commands below multiple times for each version of PHP you want to run with.

Run the following Terminal commands.

* `cd /Applications/MAMP/bin/php/php5.3.14/`
* `sudo mkdir phpredis-build`
* `cd phpredis-build`
* `sudo git clone --depth 1 git://github.com/nicolasff/phpredis.git`
* `cd phpredis`
* `sudo phpize`
* `sudo ./configure`
* `sudo make`
* `sudo make install`

A brief note on this next step.

Take the path outputted by the previous step (ie. `sudo make install`) and use it in this next command. 

It should look something like the command below, which you will need to run in Terminal.

* `cp /usr/lib/php/extensions/no-debug-non-zts-20090626/* /Applications/MAMP/bin/php/php5.3.14/lib/php/extensions/no-debug-non-zts-20090626/`

## Configure MAMP

1. Open the `php.ini` file via `File > Edit Template > PHP > PHP 5.3.14 php.ini`.
2. Add `extension=redis.so` to the list of other extensions.

## Running Redis

Run the following command in Terminal to start Redis:

`redis-server /Applications/MAMP/redis/redis.conf`

Run the following command in Terminal to stop Redis:

`killall redis-server`

## Credit

This guide was based on the following articles.

* [Install redis with MAMP instead memcache](http://larrybolt.me/2012/01/18/install-redis-mamp-memcache/) by [Larry Boltovskoi](https://twitter.com/larrybolt).
* [Install Redis & PHP Extension PHPRedis with Macports](http://www.lecloud.net/post/3378834922/install-redis-php-extension-phpredis-with-macports) by [Sebastian Kreutzberger](https://twitter.com/skreutzb).

This guide uses resources from the following sources.

* [MacPorts](http://www.macports.org/).
* [phpredis](https://github.com/nicolasff/phpredis) by [Nicolas Favre-Felix](https://twitter.com/yowgi).

