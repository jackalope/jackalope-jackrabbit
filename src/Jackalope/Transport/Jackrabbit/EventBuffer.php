<?php

namespace Jackalope\Transport\Jackrabbit;

use Jackalope\FactoryInterface;
use Jackalope\Observation\Event;
use Jackalope\Observation\EventFilter;
use PHPCR\NamespaceRegistryInterface;
use PHPCR\NodeType\NodeTypeManagerInterface;
use PHPCR\Observation\EventInterface;
use PHPCR\RepositoryException;

/**
 * This buffer parses the Jackrabbit atom xml feed.
 *
 * A sample feed is provided at the bottom of this file.
 *
 * @license http://www.apache.org/licenses Apache License Version 2.0, January 2004
 * @license http://opensource.org/licenses/MIT MIT License
 * @author David Buchmann <mail@davidbu.ch>
 */
class EventBuffer implements \Iterator
{
    protected FactoryInterface $factory;
    protected EventFilter $filter;

    /**
     * Buffered events.
     */
    private ?\ArrayIterator $events = null;

    protected Client $transport;
    protected NodeTypeManagerInterface $nodeTypeManager;
    protected NamespaceRegistryInterface $namespaceRegistry;

    /**
     * Timestamp in milliseconds when this buffer was created.
     * Never fetch any events newer than that.
     */
    protected ?int $creationMillis = null;

    /**
     * Timestamp in milliseconds of next page.
     *
     * @var int|bool|null Set to false when no next events exist
     */
    private $nextMillis;

    /**
     * The prefix to extract the path from the event href attribute.
     */
    protected string $workspaceRootUri;

    /**
     * Prepare a new EventJournal.
     *
     * Actual data loading is deferred to when it is first requested.
     */
    public function __construct(
        FactoryInterface $factory,
        EventFilter $filter,
        Client $transport,
        NodeTypeManagerInterface $ntm,
        string $workspaceRootUri,
        array $rawData
    ) {
        $this->creationMillis = time() * 1000; // do this first
        $this->factory = $factory;
        $this->filter = $filter;
        $this->transport = $transport;
        $this->nodeTypeManager = $ntm;
        $this->workspaceRootUri = $workspaceRootUri;
        $this->setData($rawData);
    }

    public function current(): ?Event
    {
        return $this->events->current();
    }

    public function next(): void
    {
        $this->events->next();
    }

    public function key(): int
    {
        return $this->events->key();
    }

    public function valid(): bool
    {
        if (!$this->events->valid() && false !== $this->nextMillis) {
            $this->fetchNextPage();
        }

        return $this->events->valid();
    }

    public function rewind(): void
    {
        $this->events->rewind();
    }

    private function setData(array $raw): void
    {
        $this->nextMillis = $raw['nextMillis'];
        $this->events = $this->constructEventJournal($raw['data']);
    }

    private function fetchNextPage(): void
    {
        if ($this->nextMillis >= $this->creationMillis) {
            // abort, we arrived in the present of this buffer
            $this->nextMillis = false;

            return;
        }
        $this->setData($this->transport->fetchEventData($this->nextMillis));
    }

    /**
     * Construct the event journal from the DAVEX response returned by the
     * server, immediately filtered by the current filter.
     *
     * @return \ArrayIterator<Event>
     */
    private function constructEventJournal(\DOMDocument $data): \ArrayIterator
    {
        $events = [];
        $entries = $data->getElementsByTagName('entry');

        foreach ($entries as $entry) {
            $userId = $this->extractUserId($entry);
            $moreEvents = $this->extractEvents($entry, $userId);
            $events = array_merge($events, $moreEvents);
        }

        return new \ArrayIterator($events);
    }

    /**
     * Parse the events in an <entry> section.
     *
     * @param string $currentUserId The current user ID as extracted from
     *                              the <entry> part
     *
     * @return Event[]
     */
    private function extractEvents(\DOMElement $entry, string $currentUserId): array
    {
        $events = [];
        $domEvents = $entry->getElementsByTagName('event');

        foreach ($domEvents as $domEvent) {
            /** @var Event $event */
            $event = $this->factory->get(Event::class, [$this->nodeTypeManager]);
            $event->setType($this->extractEventType($domEvent));

            $date = $this->getDomElement($domEvent, 'eventdate', 'The event date was not found while building the event journal:\n'.$this->getEventDom($domEvent));
            $event->setUserId($currentUserId);

            // The timestamps in Java contain milliseconds, it's not the case in PHP
            // so we strip millis from the response
            $event->setDate(substr($date->nodeValue, 0, -3));

            $id = $this->getDomElement($domEvent, 'eventidentifier');
            if ($id) {
                $event->setIdentifier($id->nodeValue);
            }

            $href = $this->getDomElement($domEvent, 'href');
            if ($href) {
                $path = str_replace($this->workspaceRootUri, '', $href->nodeValue);
                if ('/' === substr($path, -1)) {
                    // Jackrabbit might return paths with trailing slashes. Eliminate them if present.
                    $path = substr($path, 0, -1);
                }
                $event->setPath($path);
            }

            $primaryNodeType = $this->getDomElement($domEvent, 'eventprimarynodetype');
            if ($primaryNodeType) {
                $event->setPrimaryNodeTypeName($primaryNodeType->nodeValue);
            }
            $mixinNodeTypes = $domEvent->getElementsByTagName('eventmixinnodetype');
            foreach ($mixinNodeTypes as $mixinNodeType) {
                $event->addMixinNodeTypeName($mixinNodeType->nodeValue);
            }

            $userData = $this->getDomElement($domEvent, 'eventuserdata');
            if ($userData) {
                $event->setUserData($userData->nodeValue);
            }

            $eventInfos = $this->getDomElement($domEvent, 'eventinfo');
            if ($eventInfos) {
                foreach ($eventInfos->childNodes as $info) {
                    if (XML_ELEMENT_NODE === $info->nodeType) {
                        $event->addInfo($info->tagName, $info->nodeValue);
                    }
                }
            }

            // abort if we got more events than expected (usually on paging)
            if ($event->getDate() > $this->creationMillis) {
                $this->nextMillis = false;

                return $events;
            }

            if ($this->filter->match($event)) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * Extract a user id from the author tag in an entry section.
     *
     * @return string user id of the event
     *
     * @throws RepositoryException
     */
    private function extractUserId(\DOMElement $entry): string
    {
        $authors = $entry->getElementsByTagName('author');

        if (!$authors->length) {
            throw new RepositoryException('User ID not found while building the event journal');
        }

        foreach ($authors->item(0)->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                return $child->nodeValue;
            }
        }

        throw new RepositoryException('Malformed user ID while building the event journal');
    }

    /**
     * Extract an event type from a DavEx event journal response.
     *
     * @throws RepositoryException
     */
    private function extractEventType(\DOMElement $event): int
    {
        $list = $event->getElementsByTagName('eventtype');

        if (!$list->length) {
            throw new RepositoryException('Event type not found while building the event journal');
        }

        // Here we cannot simply take the first child as the <eventtype> tag might contain
        // text fragments (i.e. newlines) that will be returned as DOMText elements.
        foreach ($list->item(0)->childNodes as $el) {
            if ($el instanceof \DOMElement) {
                return $this->getEventTypeFromTagName($el->tagName);
            }
        }

        throw new RepositoryException('Malformed event type while building the event journal');
    }

    /**
     * Extract a given DOMElement from the children of another DOMElement.
     *
     * @param \DOMElement $event        The DOMElement containing the searched tag
     * @param string      $tagName      The name of the searched tag
     * @param string|null $errorMessage The error message when the tag was not
     *                                  found or null if the tag is not required
     *
     * @throws RepositoryException
     */
    private function getDomElement(\DOMElement $event, string $tagName, ?string $errorMessage = null): ?\DOMNode
    {
        $list = $event->getElementsByTagName($tagName);

        if ($errorMessage && !$list->length) {
            throw new RepositoryException($errorMessage);
        }

        return $list->item(0);
    }

    /**
     * Get the JCR event type from a DavEx tag representing the event type.
     *
     * @throws RepositoryException
     */
    private function getEventTypeFromTagName(string $tagName): int
    {
        switch (strtolower($tagName)) {
            case 'nodeadded':
                return EventInterface::NODE_ADDED;
            case 'noderemoved':
                return EventInterface::NODE_REMOVED;
            case 'propertyadded':
                return EventInterface::PROPERTY_ADDED;
            case 'propertyremoved':
                return EventInterface::PROPERTY_REMOVED;
            case 'propertychanged':
                return EventInterface::PROPERTY_CHANGED;
            case 'nodemoved':
                return EventInterface::NODE_MOVED;
            case 'persist':
                return EventInterface::PERSIST;
            default:
                throw new RepositoryException(sprintf("Invalid event type '%s'", $tagName));
        }
    }

    /**
     * Get the XML representation of a DOMElement to display in error messages.
     */
    private function getEventDom(\DOMElement $event): string
    {
        return $event->ownerDocument->saveXML($event);
    }
}

/*
Sample feed:

<feed xmlns="http://www.w3.org/2005/Atom">
    <title>EventJournal for jackalope</title>
    <author>
        <name>Jackrabbit Event Journal Feed Generator</name>
    </author>
    <id>http://localhost/server/jackalope?type=journal</id>
    <link self="http://localhost/server/jackalope?type=journal"/>
    <updated>2013-06-09T12:02:38.705+02:00</updated>
    <entry>
        <title>operations: /tests_observation_manager/testGetUnfilteredEventJournal/child</title>
        <id>http://localhost/server/jackalope?type=journal?type=journal&amp;ts=13f28633848-0</id>
        <author>
            <name>admin</name>
        </author>
        <updated>2013-06-09T12:02:38.536+02:00</updated>

        <!-- add a node: adds the primaryType property and the node itself -->
        <content type="application/vnd.apache.jackrabbit.event+xml">
            <event xmlns="http://www.day.com/jcr/webdav/1.0">
                <href xmlns="DAV:">http://localhost:8080/server/jackalope/jcr%3aroot/tests_observation_manager/testGetUnfilteredEventJournal/child/jcr%3aprimaryType</href>
                <eventtype>
                    <propertyadded/>
                </eventtype>
                <eventdate>1370772158536</eventdate>
                <eventprimarynodetype>{http://www.jcp.org/jcr/nt/1.0}unstructured</eventprimarynodetype>
                <eventidentifier>2054af10-0b33-4ac5-87b9-978d270cbb3b</eventidentifier>
            </event>
            <event xmlns="http://www.day.com/jcr/webdav/1.0">
                <href xmlns="DAV:">http://localhost:8080/server/jackalope/jcr%3aroot/tests_observation_manager/testGetUnfilteredEventJournal/child/</href>
                <eventtype>
                    <nodeadded/>
                </eventtype>
                <eventdate>1370772158536</eventdate>
                <eventprimarynodetype>{http://www.jcp.org/jcr/nt/1.0}unstructured</eventprimarynodetype>
                <eventidentifier>2054af10-0b33-4ac5-87b9-978d270cbb3b</eventidentifier>
            </event>

            <!-- change a property -->
            <event xmlns="http://www.day.com/jcr/webdav/1.0">
                <href xmlns="DAV:">http://localhost:8080/server/jackalope/jcr%3aroot/tests_observation_manager/testGetUnfilteredEventJournal/child/prop</href>
                <eventtype>
                    <propertychanged/>
                </eventtype>
                <eventdate>1370772158607</eventdate>
                <eventprimarynodetype>{http://www.jcp.org/jcr/nt/1.0}unstructured</eventprimarynodetype>
                <eventidentifier>2054af10-0b33-4ac5-87b9-978d270cbb3b</eventidentifier>
            </event>
            <event xmlns="http://www.day.com/jcr/webdav/1.0">
                <eventtype>
                    <persist/>
                </eventtype>
                <eventdate>1370772158536</eventdate>
            </event>
        </content>
    </entry>

    <!-- remove property event -->
    <entry>
        <title>operations: /tests_observation_manager/testGetUnfilteredEventJournal/child/prop</title>
        <id>http://localhost/server/jackalope?type=journal?type=journal&amp;ts=13f286338aa-0</id>
        <author>
            <name>admin</name>
        </author>
        <updated>2013-06-09T12:02:38.634+02:00</updated>
        <content type="application/vnd.apache.jackrabbit.event+xml">
            <event xmlns="http://www.day.com/jcr/webdav/1.0">
                <href xmlns="DAV:">http://localhost:8080/server/jackalope/jcr%3aroot/tests_observation_manager/testGetUnfilteredEventJournal/child/prop</href>
                <eventtype>
                    <propertyremoved/>
                </eventtype>
                <eventdate>1370772158634</eventdate>
                <eventprimarynodetype>{http://www.jcp.org/jcr/nt/1.0}unstructured</eventprimarynodetype>
                <eventidentifier>2054af10-0b33-4ac5-87b9-978d270cbb3b</eventidentifier>
            </event>
            <event xmlns="http://www.day.com/jcr/webdav/1.0">
                <eventtype>
                    <persist/>
                </eventtype>
                <eventdate>1370772158634</eventdate>
            </event>
        </content>
    </entry>

    <!-- move operation: remove from old parent, add to new parent, move event -->
    <entry>
        <title>operations: /tests_observation_manager/testGetUnfilteredEventJournal/child</title>
        <id>http://localhost/server/jackalope?type=journal?type=journal&amp;ts=13f286338d6-0</id>
        <author>
            <name>admin</name>
        </author>
        <updated>2013-06-09T12:02:38.678+02:00</updated>
        <content type="application/vnd.apache.jackrabbit.event+xml">
            <event xmlns="http://www.day.com/jcr/webdav/1.0">
                <href xmlns="DAV:">http://localhost:8080/server/jackalope/jcr%3aroot/tests_observation_manager/testGetUnfilteredEventJournal/child/</href>
                <eventtype>
                    <noderemoved/>
                </eventtype>
                <eventdate>1370772158678</eventdate>
                <eventprimarynodetype>{http://www.jcp.org/jcr/nt/1.0}unstructured</eventprimarynodetype>
                <eventidentifier>2054af10-0b33-4ac5-87b9-978d270cbb3b</eventidentifier>
            </event>
            <event xmlns="http://www.day.com/jcr/webdav/1.0">
                <href xmlns="DAV:">http://localhost:8080/server/jackalope/jcr%3aroot/tests_observation_manager/testGetUnfilteredEventJournal/moved/</href>
                <eventtype>
                    <nodeadded/>
                </eventtype>
                <eventdate>1370772158678</eventdate>
                <eventprimarynodetype>{http://www.jcp.org/jcr/nt/1.0}unstructured</eventprimarynodetype>
                <eventidentifier>2054af10-0b33-4ac5-87b9-978d270cbb3b</eventidentifier>
            </event>
            <event xmlns="http://www.day.com/jcr/webdav/1.0">
                <href xmlns="DAV:">http://localhost:8080/server/jackalope/jcr%3aroot/tests_observation_manager/testGetUnfilteredEventJournal/moved</href>
                <eventtype>
                    <nodemoved/>
                </eventtype>
                <eventdate>1370772158678</eventdate>
                <eventprimarynodetype>{http://www.jcp.org/jcr/nt/1.0}unstructured</eventprimarynodetype>
                <eventidentifier>2054af10-0b33-4ac5-87b9-978d270cbb3b</eventidentifier>
                <eventinfo>
                    <destAbsPath>/tests_observation_manager/testGetUnfilteredEventJournal/moved</destAbsPath>
                    <srcAbsPath>/tests_observation_manager/testGetUnfilteredEventJournal/child</srcAbsPath>
                </eventinfo>
            </event>
            <event xmlns="http://www.day.com/jcr/webdav/1.0">
                <eventtype>
                    <persist/>
                </eventtype>
                <eventdate>1370772158678</eventdate>
            </event>
        </content>
    </entry>
</feed>
 */
