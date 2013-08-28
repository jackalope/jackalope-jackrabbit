<?php

namespace Jackalope\Transport\Jackrabbit;

use Jackalope\FactoryInterface;
use Jackalope\Observation\Event;
use Jackalope\Observation\EventFilter;
use Jackalope\TestCase;
use Jackalope\Factory;

use PHPCR\NodeType\NodeTypeManagerInterface;
use PHPCR\Observation\EventInterface;

/**
 * Unit tests for the EventJournal
 */
class EventBufferTest extends TestCase
{
    protected $buffer;

    protected $factory;

    protected $session;

    protected $nodeTypeManager;

    /**
     * @var EventFilter
     */
    protected $filter;

    protected $transport;

    protected $eventXml;

    protected $eventWithInfoXml;

    protected $entryXml;

    /** @var Event */
    protected $expectedEvent;

    /** @var Event */
    protected $expectedEventWithInfo;

    public function setUp()
    {
        $this->factory = new Factory();
        $this->transport = $this
            ->getMockBuilder('\Jackalope\Transport\Jackrabbit\Client')
            ->disableOriginalConstructor()
            ->getMock('fetchEventData')
        ;
        $this->session = $this->getSessionMock(array('getNode', 'getNodesByIdentifier'));
        $this->session
            ->expects($this->any())
            ->method('getNode')
            ->will($this->returnValue(null));
        $this->session
            ->expects($this->any())
            ->method('getNodesByIdentifier')
            ->will($this->returnValue(array()));
        $this->filter = new EventFilter($this->factory, $this->session);

        $this->nodeTypeManager = $this
            ->getMockBuilder('Jackalope\NodeType\NodeTypeManager')
            ->disableOriginalConstructor()
            ->getMock()
        ;

        $this->buffer = new TestBuffer($this->factory, $this->filter, $this->transport, $this->nodeTypeManager, 'http://localhost:8080/server/tests/jcr%3aroot');

        // XML for a single event
        $this->eventXml = <<<EOF
<event xmlns="http://www.day.com/jcr/webdav/1.0">
    <href xmlns="DAV:">http://localhost:8080/server/tests/jcr%3aroot/my_node%5b4%5d/jcr%3aprimaryType</href>
    <eventtype>
        <propertyadded/>
    </eventtype>
    <eventdate>1331652655099</eventdate>
    <eventuserdata>somedifferentdata</eventuserdata>
    <eventprimarynodetype>{http://www.jcp.org/jcr/nt/1.0}unstructured</eventprimarynodetype>
    <eventidentifier>8fe5b853-a657-4ee3-b626-ec3b5407dc13</eventidentifier>
</event>
EOF;

        // XML for event with eventinfo
        $this->eventWithInfoXml = <<<EOX
<event xmlns="http://www.day.com/jcr/webdav/1.0">
    <href
    xmlns="DAV:">http://localhost:8080/server/tests/jcr%3aroot/my_other</href>
    <eventtype>
        <nodemoved/>
    </eventtype>
    <eventdate>1332163767892</eventdate>
    <eventuserdata>somedifferentdata</eventuserdata>
    <eventprimarynodetype>{internal}root</eventprimarynodetype>
    <eventmixinnodetype>{internal}AccessControllable</eventmixinnodetype>
    <eventidentifier>1e80ac75-eff4-4350-bae6-7fae2a84e6f3</eventidentifier>
    <eventinfo>
        <destAbsPath>/my_other</destAbsPath>
        <srcAbsPath>/my_node</srcAbsPath>
    </eventinfo>
</event>
EOX;
        // XML for several events entries
        $this->entryXml = <<<EOF
<entry>
    <title>operations: /my_node[4]</title>
    <id>http://localhost/server/tests?type=journal?type=journal&amp;ts=1360caef7fb-0</id>
    <author>
        <name>system</name>
    </author>
    <updated>2012-03-13T16:30:55.099+01:00</updated>
    <content type="application/vnd.apache.jackrabbit.event+xml">
EOF;
        $this->entryXml .= $this->eventXml . "\n";
        $this->entryXml .= $this->eventXml . "\n"; // The same event appears twice in this entry
        $this->entryXml .= $this->eventWithInfoXml . "\n";
        $this->entryXml .= '</content></entry>';

        // The object representation of the event defined above
        $this->expectedEvent = new Event($this->factory, $this->nodeTypeManager);
        $this->expectedEvent->setDate('1331652655');
        $this->expectedEvent->setIdentifier('8fe5b853-a657-4ee3-b626-ec3b5407dc13');
        $this->expectedEvent->setPrimaryNodeTypeName('{http://www.jcp.org/jcr/nt/1.0}unstructured');
        $this->expectedEvent->setPath('/my_node%5b4%5d/jcr%3aprimaryType');
        $this->expectedEvent->setType(EventInterface::PROPERTY_ADDED);
        $this->expectedEvent->setUserData('somedifferentdata');
        $this->expectedEvent->setUserId('system');

        $this->expectedEventWithInfo = new Event($this->factory, $this->nodeTypeManager);
        $this->expectedEventWithInfo->setDate('1332163767');
        $this->expectedEventWithInfo->setIdentifier('1e80ac75-eff4-4350-bae6-7fae2a84e6f3');
        $this->expectedEventWithInfo->setPrimaryNodeTypeName('{internal}root');
        $this->expectedEventWithInfo->setMixinNodeTypeNames(array('{internal}AccessControllable'));
        $this->expectedEventWithInfo->setPath('/my_other');
        $this->expectedEventWithInfo->setType(EventInterface::NODE_MOVED);
        $this->expectedEventWithInfo->setUserData('somedifferentdata');
        $this->expectedEventWithInfo->setUserId('system');
        $this->expectedEventWithInfo->addInfo('destAbsPath', '/my_other');
        $this->expectedEventWithInfo->addInfo('srcAbsPath', '/my_node');
    }

    public function testExtractUserId()
    {
        $xml = '<author><name>admin</name></author>';
        $res = $this->getAndCallMethod($this->buffer, 'extractUserId', array($this->getDomElement($xml)));
        $this->assertEquals('admin', $res);

        $xml = '<author><name></name></author>';
        $res = $this->getAndCallMethod($this->buffer, 'extractUserId', array($this->getDomElement($xml)));
        $this->assertEquals('', $res);
    }

    /**
     * @expectedException \PHPCR\RepositoryException
     */
    public function testExtractUserIdNoAuthor()
    {
        $xml = '<artist><name>admin</name></artist>';
        $this->getAndCallMethod($this->buffer, 'extractUserId', array($this->getDomElement($xml)));
    }

    /**
     * @expectedException \PHPCR\RepositoryException
     */
    public function testExtractUserIdNoName()
    {
        $xml = '<author>admin</author>';
        $this->getAndCallMethod($this->buffer, 'extractUserId', array($this->getDomElement($xml)));
    }

    public function testFiltering()
    {
        $this->filter->setAbsPath('/something-not-matching');

        $buffer = new TestBuffer($this->factory, $this->filter, $this->transport, $this->nodeTypeManager, 'http://localhost:8080/server/tests/jcr%3aroot');

        $events = $this->getAndCallMethod($buffer, 'extractEvents', array($this->getDomElement($this->eventXml), 'system'));

        $this->assertEquals(array(), $events);
    }

    public function testExtractEventType()
    {
        $validEventTypes = array(
            'nodeadded' => EventInterface::NODE_ADDED,
            'nodemoved' => EventInterface::NODE_MOVED,
            'noderemoved' => EventInterface::NODE_REMOVED,
            'propertyadded' => EventInterface::PROPERTY_ADDED,
            'propertyremoved' => EventInterface::PROPERTY_REMOVED,
            'propertychanged' => EventInterface::PROPERTY_CHANGED,
            'persist' => EventInterface::PERSIST,
        );

        foreach ($validEventTypes as $string => $integer) {
            $xml = '<eventtype><' . $string . '/></eventtype>';
            $res = $this->getAndCallMethod($this->buffer, 'extractEventType', array($this->getDomElement($xml)));
            $this->assertEquals($integer, $res);
        }
    }

    /**
     * @expectedException \PHPCR\RepositoryException
     */
    public function testExtractEventTypeInvalidType()
    {
        $xml = '<eventtype><invalidType/></eventtype>';
        $this->getAndCallMethod($this->buffer, 'extractEventType', array($this->getDomElement($xml)));
    }

    /**
     * @expectedException \PHPCR\RepositoryException
     */
    public function testExtractEventTypeNoType()
    {
        $xml = '<invalid><persist/></invalid>';
        $this->getAndCallMethod($this->buffer, 'extractEventType', array($this->getDomElement($xml)));
    }

    /**
     * @expectedException \PHPCR\RepositoryException
     */
    public function testExtractEventTypeMalformed()
    {
        $xml = '<eventtype>some string</eventtype>';
        $this->getAndCallMethod($this->buffer, 'extractEventType', array($this->getDomElement($xml)));
    }

    public function testEventInfo()
    {
        $events = $this->getAndCallMethod($this->buffer, 'extractEvents', array($this->getDomElement($this->eventWithInfoXml), 'system'));
        $this->assertCount(1, $events);
        $eventWithInfo = $events[0];

        $this->assertInstanceOf('Jackalope\Observation\Event', $eventWithInfo);
        /** @var $eventWithInfo Event */
        $eventInfo = $eventWithInfo->getInfo();
        $this->assertEquals($this->expectedEventWithInfo->getInfo(), $eventInfo);

        $expectedInfo = array(
            'destAbsPath' => '/my_other',
            'srcAbsPath' => '/my_node'
        );

        $this->assertEquals(count($expectedInfo), count($eventInfo));

        foreach ($expectedInfo as $key => $expectedValue) {
            $value = $eventInfo[$key];
            $this->assertSame($expectedValue, $value);
        }

        $this->nodeTypeManager
            ->expects($this->at(0))
            ->method('getNodeType')
            ->with('{internal}root')
            ->will($this->returnValue(true))
        ;
        $this->nodeTypeManager
            ->expects($this->at(1))
            ->method('getNodeType')
            ->with('{internal}AccessControllable')
            ->will($this->returnValue(true))
        ;

        $this->assertTrue($eventWithInfo->getPrimaryNodeType());
        $this->assertEquals(array('{internal}AccessControllable' => true), $eventWithInfo->getMixinNodeTypes());
    }

    public function testEmptyEventInfo()
    {
        // get an event that has no eventinfo
        $events = $this->getAndCallMethod($this->buffer, 'extractEvents', array($this->getDomElement($this->eventXml), 'system'));
        $this->assertCount(1, $events);
        $event = $events[0];

        $this->assertInstanceOf('Jackalope\Observation\Event', $event);
        /** @var $event Event */

        $eventInfo = $event->getInfo();

        $this->assertInternalType('array', $eventInfo);
        $this->assertEquals(0, count($eventInfo));
    }

    public function testIterator()
    {
        $dom = new \DOMDocument();
        $dom->loadXML($this->entryXml);
        $data = array(
            'data' => $dom,
            'nextMillis' => false,
        );

        $this->transport
            ->expects($this->never())
            ->method('fetchEventData')
        ;

        $buffer = new EventBuffer($this->factory, $this->filter, $this->transport, $this->nodeTypeManager, 'http://localhost:8080/server/tests/jcr%3aroot', $data);

        $this->assertTrue($buffer->valid());
        $this->assertEquals($this->expectedEvent, $buffer->current());
        $buffer->next();
        $this->assertTrue($buffer->valid());
        $this->assertEquals($this->expectedEvent, $buffer->current());
        $buffer->next();
        $this->assertTrue($buffer->valid());
        $this->assertEquals($this->expectedEventWithInfo, $buffer->current());
        $buffer->next();
        $this->assertFalse($buffer->valid());

        $buffer->rewind();
        $this->assertTrue($buffer->valid());
        $this->assertEquals($this->expectedEvent, $buffer->current());
    }

    public function testFetchPage()
    {
        $dom = new \DOMDocument();
        $dom->loadXML($this->entryXml);
        $data = array(
            'data' => $dom,
            'nextMillis' => 7,
        );

        $this->transport
            ->expects($this->once())
            ->method('fetchEventData')
            ->with(7)
            ->will($this->returnValue(array(
                'data' => $dom,
                'nextMillis' => false,
            )))
        ;

        $buffer = new EventBuffer($this->factory, $this->filter, $this->transport, $this->nodeTypeManager, 'http://localhost:8080/server/tests/jcr%3aroot', $data);

        $this->assertTrue($buffer->valid());
        $this->assertEquals($this->expectedEvent, $buffer->current());
        $buffer->next();
        $this->assertTrue($buffer->valid());
        $this->assertEquals($this->expectedEvent, $buffer->current());
        $buffer->next();
        $this->assertTrue($buffer->valid());
        $this->assertEquals($this->expectedEventWithInfo, $buffer->current());
        $buffer->next();
        // now should load next page
        $this->assertTrue($buffer->valid());
        $this->assertEquals($this->expectedEvent, $buffer->current());
        $buffer->next();
        $this->assertTrue($buffer->valid());
        $this->assertEquals($this->expectedEvent, $buffer->current());
        $buffer->next();
        $this->assertTrue($buffer->valid());
        $this->assertEquals($this->expectedEventWithInfo, $buffer->current());
        $buffer->next();
        $this->assertFalse($buffer->valid());
    }
}

/**
 * no-argument constructor to test xml parsing
 */
class TestBuffer extends EventBuffer
{
    public function __construct(
        FactoryInterface $factory,
        EventFilter $filter,
        Client $transport,
        NodeTypeManagerInterface $nodeTypeManager,
        $workspaceRootUri
    ) {
        $this->creationMillis = time() * 1000;
        $this->factory = $factory;
        $this->filter = $filter;
        $this->transport = $transport;
        $this->nodeTypeManager = $nodeTypeManager;
        $this->workspaceRootUri = $workspaceRootUri;
    }
}
