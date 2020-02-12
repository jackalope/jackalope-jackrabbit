<?php

namespace Jackalope;

use PHPCR\ConfigurationException;
use PHPUnit\Framework\TestCase;

class RepositoryFactoryJackrabbitTest extends TestCase
{
    public function testMissingRequired(): void
    {
        $factory = new RepositoryFactoryJackrabbit();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('missing');
        $factory->getRepository(array());
    }

    public function testExtraParameter(): void
    {
        $factory = new RepositoryFactoryJackrabbit();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('unknown');
        $factory->getRepository(array(
            'jackalope.jackrabbit_uri' => 'http://localhost',
            'unknown' => 'garbage',
        ));
    }
}
