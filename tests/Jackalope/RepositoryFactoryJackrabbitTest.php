<?php

namespace Jackalope;

class RepositoryFactoryJackrabbitTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @expectedException \PHPCR\ConfigurationException
     * @expectedExceptionMessage missing
     */
    public function testMissingRequired()
    {
        $factory = new RepositoryFactoryJackrabbit();

        $factory->getRepository(array());
    }

    /**
     * @expectedException \PHPCR\ConfigurationException
     * @expectedExceptionMessage unknown
     */
    public function testExtraParameter()
    {
        $factory = new RepositoryFactoryJackrabbit();

        $factory->getRepository(array(
            'jackalope.jackrabbit_uri' => 'http://localhost',
            'unknown' => 'garbage',
        ));
    }
}
