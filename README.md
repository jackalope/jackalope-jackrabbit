# Jackalope Jackrabbit

[![Build Status](https://secure.travis-ci.org/jackalope/jackalope-jackrabbit.png?branch=2.x)](http://travis-ci.org/jackalope/jackalope-jackrabbit)
[![Latest Stable Version](https://poser.pugx.org/jackalope/jackalope-jackrabbit/version.png)](https://packagist.org/packages/jackalope/jackalope-jackrabbit)
[![Total Downloads](https://poser.pugx.org/jackalope/jackalope-jackrabbit/d/total.png)](https://packagist.org/packages/jackalope/jackalope-jackrabbit)

Jackalope is a powerful implementation of the PHP Content Repository API ([PHPCR](http://phpcr.github.io)).

Jackalope-Jackrabbit is using the jackrabbit JCR server as storage engine.

Discuss on jackalope-dev@googlegroups.com
or visit #jackalope on irc.freenode.net


## License

This code is dual licensed under the MIT license and the Apache License Version
2.0. Please see the file LICENSE in this folder.


# Preconditions

* libxml version >= 2.7.0 (due to a bug in libxml [http://bugs.php.net/bug.php?id=36501](http://bugs.php.net/bug.php?id=36501))
* libcurl (if you get ``Problem (2) in the Chunked-Encoded data`` with version 7.35, try updating your curl version)
* [composer](http://getcomposer.org/)


# Installation

The recommended way to install jackalope is through [composer](http://getcomposer.org/).

```sh
$ mkdir my-project
$ cd my-project
$ composer init
$ composer require jackalope/jackalope-jackrabbit
```

## Jackrabbit storage server

Besides the Jackalope repository, you need the Jackrabbit server component. For instructions, see [Jackalope Wiki](https://github.com/jackalope/jackalope/wiki/Running-a-jackrabbit-server)
Make sure you have at least the version specified in [the VERSION constant of the protocol implementation](https://github.com/jackalope/jackalope-jackrabbit/blob/master/src/Jackalope/Transport/Jackrabbit/Client.php)


## phpunit tests

If you want to run the tests, please see the
[README file in the tests folder](https://github.com/jackalope/jackalope-jackrabbit/blob/master/tests/README.md)
and check if you told composer to install the suggested dependencies (see Installation)


## Enable the commands

There are a couple of useful commands to interact with the repository.

To use the console, copy cli-config.php.dist to cli-config.php and configure
the connection parameters.
Then you can run the commands from the jackalope directory with ``./bin/jackalope``

NOTE: If you are using PHPCR inside of **Symfony**, the DoctrinePHPCRBundle
provides the commands inside the normal Symfony console and you don't need to
prepare anything special.

There is the Jackalope specific command ``jackalope:run:jackrabbit`` which you
can use to start and stop a jackrabbit standalone server.

You have many useful commands available from the phpcr-utils. To get a list of
all commands, type:

    ./bin/jackalope

To get more information on a specific command, use the `help` command. To learn
more about the `phpcr:workspace:export` command for example, you would type:

    ./bin/jackalope help phpcr:workspace:export


# Bootstrapping

Jackalope relies on autoloading. Namespaces and folders are compliant with
PSR-0. You should use the autoload file generated by composer:
``vendor/autoload.php``

If you want to integrate jackalope into other PSR-0 compliant code and use your
own classloader, find the mapping in ``vendor/composer/autoload_namespaces.php``


Once you have autoloading, you need to bootstrap the library. A minimalist
sample code to get a PHPCR session with the jackrabbit backend:

```php
$jackrabbit_url = 'http://127.0.0.1:8080/server/';
$user           = 'admin';
$pass           = 'admin';
$workspace      = 'default';

$factory = new \Jackalope\RepositoryFactoryJackrabbit();
$repository = $factory->getRepository(
    array("jackalope.jackrabbit_uri" => $jackrabbit_url)
);
$credentials = new \PHPCR\SimpleCredentials($user, $pass);
$session = $repository->login($credentials, $workspace);
```

To use a workspace different than ``default`` you need to create it first. The
easiest is to run the command ``bin/jackalope phpcr:workspace:create <myworkspace>``
but you can of course also use the PHPCR API to create workspaces from your code.


# Usage

The entry point is to create the repository factory. The factory specifies the
storage backend as well. From this point on, there are no differences in the
usage (except for supported features, that is).

```php
// see Bootstrapping for how to get the session.

$rootNode = $session->getNode("/");
$whitewashing = $rootNode->addNode("www-whitewashing-de");
$session->save();

$posts = $whitewashing->addNode("posts");
$session->save();

$post = $posts->addNode("welcome-to-blog");
$post->addMixin("mix:title");
$post->setProperty("jcr:title", "Welcome to my Blog!");
$post->setProperty("jcr:description", "This is the first post on my blog! Do you like it?");

$session->save();
```

See [PHPCR Tutorial](http://phpcr.readthedocs.org/en/latest/book/index.html)
for a more detailed tutorial on how to use the PHPCR API.


# Query Languages

Jackalope supports the PHPCR standard query language SQL2 as well as the Query
Object Model (QOM) to build queries programmatically. We recommend using the
QOM or the QueryBuilder mentioned in the
[PHPCR Tutorial](http://phpcr.readthedocs.org/en/latest/book/index.html).
They are built to use the best possible query language depending on the
capabilities of the backend. A later switching to another PHPCR implementation
shouldn't cause any issues then.

Jackalope-Jackrabbit also supports the deprecated SQL and XPath query languages
from JCR 1.0. Those languages will be supported by Jackrabbit for the foreseeable
future, but almost certainly won't be supported by other PHPCR implementations.
So use them with care and only if you know what you are doing.

One reason for using SQL or XPath is that the newer and more capable SQL2 is not
as optimized as the older languages on the Jackrabbit side. Queries with large
result sets are much slower with SQL2 than with XPath or SQL.

However, the best is to use the QueryBuilder mentioned above to let the
implementation chose the most efficient query language for your implementation.


# Performance tweaks

If you know that you will need many child nodes of a node you are about to
request, use the depth hint on Session::getNode.  This will prefetch the
children to reduce the round trips to the database. It is part of the PHPCR
standard. You can also globally set a fetch depth, but that is Jackalope
specific: Call Session::setSessionOption with Session::OPTION_FETCH_DEPTH
to something bigger than 1.

Use Node::getNodeNames if you only need to know the names of child nodes, but
don't need the actual nodes. Note that you should not use the typeFilter on
getNodeNames with jackalope. Using the typeFilter with getNodes to only fetch
the nodes of types that interest you can make a lot of sense however.


# Logging

Jackalope supports logging, for example to investigate the number and type of
queries used. To enable logging, provide a logger instance to the repository
factory:

```php
$factory = new \Jackalope\RepositoryFactoryJackrabbit();
$logger = new Jackalope\Transport\Logging\DebugStack();
$options = array(
    'jackalope.jackrabbit_uri' => $jackrabbit_url,
    'jackalope.logger' => $logger,
);
$repository = $factory->getRepository($options);

...

// at the end, output debug information
var_dump($logger->calls);
```

You can also wrap a [PSR-3](https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-3-logger-interface.md)
compatible logger like [monolog](https://github.com/Seldaek/monolog) with the
Psr3Logger class.

Note that when using jackalope in Symfony2, the logger is integrated in the
debug toolbar.

# Implementation notes

See [doc/architecture.md](https://github.com/jackalope/jackalope/blob/master/doc/architecture.md)
for an introduction how Jackalope is built. Have a look at the source files and
generate the phpdoc.


# Not implemented features

The best overview of what needs to be done are the skipped API tests.
Have a look at [ImplementationLoader](https://github.com/jackalope/jackalope-jackrabbit/blob/master/tests/inc/ImplementationLoader.php) to
see what is currently not working and start hacking :-)


# Contributors

* Christian Stocker <chregu@liip.ch>
* David Buchmann <david@liip.ch>
* Tobias Ebnöther <ebi@liip.ch>
* Roland Schilter <roland.schilter@liip.ch>
* Uwe Jäger <uwej711@googlemail.com>
* Lukas Kahwe Smith <smith@pooteeweet.org>
* Daniel Barsotti <daniel.barsotti@liip.ch>
* [and many others](https://github.com/jackalope/jackalope-jackrabbit/contributors)
