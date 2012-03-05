# TODO for "perfect" support of JSOP vs. old-style

## Transactions

Transactions do not work yet, investigation with dev@jackrabbit.apache.org is under way. See [http://www.mail-archive.com/dev@jackrabbit.apache.org/msg27927.html](http://www.mail-archive.com/dev@jackrabbit.apache.org/msg27927.html)

Removed the interface for now from Jackalope\Transport\Jackrabbit\Client so that tests are skipped and not fail


## Wrong property type is autoconverted by Jackrabbit

When saved via the JSOP interface, jackrabbit does an "automagic" conversion of wrong DataTypes, if the Node Type Definition asks for something else. This is way the following test fails. 

```
1) PHPCR\Tests\Writing\AddMethodsTest::testAddPropertyWrongType
Failed asserting that exception of type "\PHPCR\NodeType\ConstraintViolationException" is thrown.

/usr/local/php5-20120203-085139/bin/phpunit:46
```

Even though we send the jcr:data as STRING and jackrabbit expects a BINARY for that property, jackrabbit automatically converts that to BINARY and no exception is thrown. Not really sure, what to do here.