# Tests

There are two kind of tests. The folder ``tests/phpcr-api`` contains the
[phpcr-api-tests](https://github.com/phpcr/phpcr-api-tests/) suite to test
against the specification. This is what you want to look at when using
jackalope as a PHPCR implementation.

For both, you need to have the test workspace created in the storage (see
below).


# Unit tests

Unit tests for the client are in tests/Jackalope/Transport/Jackrabbit

Note that the base jackalope repository contains some unit tests for jackalope in
its tests folder.


# Functional Tests

The phpunit.xml.dist is configured to run all tests. You can limit the tests
to run by specifying the path to those tests to phpunit.

Note that the phpcr-api tests are skipped for features not implemented in
jackalope. Have a look at the tests/inc/JackrabbitImplementationLoader.php files to see
which features are skipped for what backend.


# Setup


Jackalope bundles the extensive phpcr-api-tests suite to test compliance with
the PHPCR standard.

You should only see success or skipped tests, no failures or errors.

To run the tests.

    cd /path/to/jackalope/tests
    cp phpunit.xml.dist phpunit.xml
    phpunit


## Use a non-default workspace

If you want to run the tests against a non-default workspace, edit phpunit.xml and change
<var name="phpcr.workspace" value="default" /> to point to a different name. Then create
a workspace in jackrabbit.

    java -jar jackrabbit-*.jar
    # when it says "Apache Jackrabbit is now running at http://localhost:8080/" ctrl-c to stop
    cp -r jackrabbit/workspaces/default jackrabbit/workspace/tests
    edit jackrabbit/workspaces/tests/workspace.xml
    # change the line <Workspace name="default"> to <Workspace name="tests">
    java -jar jackrabbit-*.jar

See also "Jackrabbit Doc":http://jackrabbit.apache.org/jackrabbit-configuration.html#JackrabbitConfiguration-Workspaceconfiguration


# Some notes on the jackalope-jackrabbit api testing.

## Using JackrabbitFixtureLoader for load your own fixtures

Note that the best would be to implement the Session::importXML method

Until this happens, you can use the class JackrabbitFixtureLoader found in
inc/JackrabbitFixtureLoader.php to import fixtures in the JCR XML formats.
It relies on jack.jar. The class can be plugged in Symfony2 autoload mechanism
through autoload.php, which can be used to feed a MapFileClassLoader instance. E.g:


    $phpcr_loader = new MapFileClassLoader(
        __DIR__.'/../vendor/doctrine-phpcr-odm/lib/vendor/jackalope/inc/JackrabbitFixtureLoader.php'
    );
    $phpcr_loader->register();


## Note on JCR

It would be nice if we were able to run the relevant parts of the JSR-283
Technology Compliance Kit (TCK) against php implementations. Note that we would
need to have some glue for things that look different in PHP than in Java, like
the whole topic of Value and ValueFactory.
[https://jira.liip.ch/browse/JACK-24](https://jira.liip.ch/browse/JACK-24)

Once we manage to do that, we could hopefully also use the performance test suite
[https://jira.liip.ch/browse/JACK-23](https://jira.liip.ch/browse/JACK-23)
