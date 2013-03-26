# Tests

There are two kind of tests. The folder ``vendor/phpcr/phpcr-api-tests`` contains the
[phpcr-api-tests](https://github.com/phpcr/phpcr-api-tests/) suite to test
against the specification. This is what you want to look at when using
jackalope as a PHPCR implementation.

Unit tests for the jackrabbit client implementation are in tests/Jackalope/Transport/Jackrabbit

Note that the base jackalope repository contains some unit tests for jackalope in
its tests folder.

## API test suite

The phpunit.xml.dist is configured to run all tests. You can limit the tests
to run by specifying the path to those tests to phpunit.

Note that the phpcr-api tests are skipped for features not implemented in
jackalope. Have a look at the tests/inc/JackrabbitImplementationLoader.php file
to see which features are currently skipped.

You should only see success or skipped tests, no failures or errors.


# Setup

**Careful: If you run the tests without changing the workspace in the phpunit.xml,
the default workspace will be used and all content in the default workspace is
destroyed.**

A second workspace is needed for some tests.

To run the tests:

    cd /path/to/jackalope/tests
    cp phpunit.xml.dist phpunit.xml
    phpunit


## Use a non-default workspace

If you want to run the tests against something else than the default workspace,
edit phpunit.xml and change the "default" in ``<var name="phpcr.workspace" value="default" />``
to point to a different name, e.g. "tests". For cross-workspace tests, there is
a similar setting ``<var name="phpcr.additionalWorkspace" value="testsAdditional" />``
which you can change as well.


## Note on JCR

It would be nice if we were able to run the relevant parts of the JSR-283
Technology Compliance Kit (TCK) against php implementations. Note that we would
need to have some glue for things that look different in PHP than in Java, like
the whole topic of Value and ValueFactory.
[https://jira.liip.ch/browse/JACK-24](https://jira.liip.ch/browse/JACK-24)

Once we manage to do that, we could hopefully also use the performance test suite
[https://jira.liip.ch/browse/JACK-23](https://jira.liip.ch/browse/JACK-23)
