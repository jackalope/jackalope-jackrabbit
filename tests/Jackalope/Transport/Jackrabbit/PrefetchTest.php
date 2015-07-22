<?php

namespace Jackalope\Transport\Jackrabbit;

use Jackalope\TestCase;

/**
 * Extend this test case in your jackalope transport and provide the transport
 * instance to be tested.
 *
 * The fixtures must contain the following tree:
 *
 * * node-a
 * * * child-a
 * * * child-b
 * * node-b
 * * * child-a
 * * * child-b
 *
 * each child has a property "prop" with the corresponding a and b value in it:
 * /node-a/child-a get "prop" => "aa".
 */
class PrefetchTest extends TestCase
{
    /**
     * @var \ImplementationLoader
     */
    protected static $loader;

    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();
        self::$loader = \ImplementationLoader::getInstance();

        $session = self::$loader->getSession(self::$loader->getCredentials());

        if ($session->nodeExists('/node-a')) {
            $session->removeItem('/node-a');
        }
        if ($session->nodeExists('/node-b')) {
            $session->removeItem('/node-b');
        }
        $session->save();

        $a = $session->getNode('/')->addNode('node-a');
        $a->addNode('child-a')->setProperty('prop', 'aa');
        $a->addNode('child-b')->setProperty('prop', 'ab');
        $b = $session->getNode('/')->addNode('node-b');
        $b->addNode('child-a')->setProperty('prop', 'ba');
        $b->addNode('child-b')->setProperty('prop', 'bb');
        $session->save();
    }

    protected function getTransport()
    {
        $transport = new \Jackalope\Transport\Jackrabbit\Client(new \Jackalope\Factory(), $GLOBALS['jackrabbit.uri']);
        $transport->login(self::$loader->getCredentials(), self::$loader->getWorkspaceName());

        return $transport;
    }

    public function testGetNode()
    {
        $transport = $this->getTransport();
        $transport->setFetchDepth(1);

        $raw = $transport->getNode('/node-a');

        $this->assertNode($raw, 'a');
    }

    public function testGetNodes()
    {
        $transport = $this->getTransport();
        $transport->setFetchDepth(1);

        $list = $transport->getNodes(array('/node-a', '/node-b'));

        list($key, $raw) = each($list);
        $this->assertEquals('/node-a', $key);
        $this->assertNode($raw, 'a');

        list($key, $raw) = each($list);
        $this->assertEquals('/node-b', $key);
        $this->assertNode($raw, 'b');
    }

    protected function assertNode($raw, $parent)
    {
        $this->assertInstanceOf('\stdClass', $raw);
        $name = "child-a";
        $this->assertTrue(isset($raw->$name), "The raw data is missing child $name");
        $this->assertInstanceOf('\stdClass', $raw->$name);
        $this->assertTrue(isset($raw->$name->prop), "The child $name is missing property 'prop'");
        $this->assertEquals($parent . 'a', $raw->$name->prop);

        $name = 'child-b';
        $this->assertTrue(isset($raw->$name));
        $this->assertInstanceOf('\stdClass', $raw->$name);
        $this->assertTrue(isset($raw->$name->prop), "The child $name is missing property 'prop'");
        $this->assertEquals($parent . 'b', $raw->$name->prop);
    }
}
