<?php

namespace Jackalope\Transport\Jackrabbit;

use Jackalope\Functional\Transport\PrefetchTestCase;

class PrefetchTest extends PrefetchTestCase
{
    /**
     * @var \ImplementationLoader
     */
    static protected $loader;

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
}
