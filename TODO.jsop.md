# TODO for "perfect" support of JSOP vs. old-style

## Transactions

Transactions do not work yet, investigation with dev@jackrabbit.apache.org is under way. See [http://www.mail-archive.com/dev@jackrabbit.apache.org/msg27927.html](http://www.mail-archive.com/dev@jackrabbit.apache.org/msg27927.html)

Removed the interface for now from Jackalope\Transport\Jackrabbit\Client so that tests are skipped and not fail

## Streams are not closed on session->logout

Contrary to the current way, streams are not closed in the tests 


```
2) PHPCR\Tests\Writing\SetPropertyTypesTest::testCreateValueBinaryFromStream
The responsibility for the stream goes into phpcr who must close it
Failed asserting that true is false.

/opt/git/jackalope-jackrabbit/tests/phpcr-api/tests/10_Writing/SetPropertyTypesTest.php:68
/usr/local/php5-20120203-085139/bin/phpunit:46

3) PHPCR\Tests\Writing\SetPropertyTypesTest::testCreateValueBinaryFromStreamAndRead
The responsibility for the stream goes into phpcr who must close it
Failed asserting that true is false.

/opt/git/jackalope-jackrabbit/tests/phpcr-api/tests/10_Writing/SetPropertyTypesTest.php:88
/usr/local/php5-20120203-085139/bin/phpunit:46
```
See [http://www.mail-archive.com/dev@jackrabbit.apache.org/msg27927.html](http://www.mail-archive.com/dev@jackrabbit.apache.org/msg27927.html) for the discussion

## Wrong property type is autoconverted by Jackrabbit

When saved via the JSOP interface, jackrabbit does an "automagic" conversion of wrong DataTypes, if the Node Type Definition asks for something else. This is way the following test fails. 

```
1) PHPCR\Tests\Writing\AddMethodsTest::testAddPropertyWrongType
Failed asserting that exception of type "\PHPCR\NodeType\ConstraintViolationException" is thrown.

/usr/local/php5-20120203-085139/bin/phpunit:46
```

Even though we send the jcr:data as STRING and jackrabbit expects a BINARY for that property, jackrabbit automatically converts that to BINARY and no exception is thrown. Not really sure, what to do here.