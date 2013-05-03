<?php

namespace Jackalope\Transport\Jackrabbit;

use DOMDocument;
use DOMElement;
use DOMNode;
use ArrayIterator;

use Jackalope\Observation\Event;
use Jackalope\Observation\EventFilter;
use Jackalope\Transport\ObservationInterface;
use PHPCR\Observation\EventInterface;
use PHPCR\RepositoryException;

use Jackalope\FactoryInterface;

/**
 * This buffer parses the Jackrabbit atom xml feed.
 *
 * @author David Buchmann <mail@davidbu.ch>
 */
class EventBuffer implements \Iterator
{
    /**
     * @var FactoryInterface
     */
    protected $factory;

    /**
     * @var EventFilter
     */
    protected $filter;

    /**
     * Buffered events
     *
     * @var ArrayIterator
     */
    protected $events;

    /**
     * @var ObservationInterface
     */
    protected $transport;

    /**
     * Timestamp in milliseconds when this buffer was created.
     * Never fetch any events newer than that.
     *
     * @var int
     */
    protected $creationMillis;

    /**
     * Timestamp in milliseconds of next page.
     *
     * @var int
     */
    protected $nextMillis;

    /**
     * The prefix to extract the path from the event href attribute
     *
     * @var string
     */
    protected $workspaceRootUri;

    /**
     * Prepare a new EventJournal.
     *
     * Actual data loading is deferred to when it is first requested.
     *
     * @param FactoryInterface $factory
     * @param EventFilter      $filter    filter to apply.
     * @param Client           $transport
     */
    public function __construct(
        FactoryInterface $factory,
        EventFilter $filter,
        Client $transport,
        $workspaceRootUri,
        $rawData
    ) {
        $this->creationMillis = time() * 1000; // do this first
        $this->factory = $factory;
        $this->filter = $filter;
        $this->transport = $transport;
        $this->workspaceRootUri = $workspaceRootUri;
        $this->setData($rawData);
    }

    public function current()
    {
        return $this->events->current();
    }

    public function next()
    {
        $this->events->next();
    }

    public function key()
    {
        return $this->events->key();
    }

    public function valid()
    {
        if (!$this->events->valid() && false !== $this->nextMillis) {
            $this->fetchNextPage();
        }

        return $this->events->valid();
    }

    public function rewind()
    {
        $this->events->rewind();
    }

    protected function setData($raw)
    {
        $this->nextMillis = $raw['nextMillis'];
        $this->events = $this->constructEventJournal($raw['data']);
    }

    protected function fetchNextPage()
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
     * @param DOMDocument $data
     *
     * @return Event[]
     */
    protected function constructEventJournal(DOMDocument $data)
    {
        $events = array();
        $entries = $data->getElementsByTagName('entry');

        foreach ($entries as $entry) {
            $userId = $this->extractUserId($entry);
            $moreEvents = $this->extractEvents($entry, $userId);
            $events = array_merge($events, $moreEvents);
        }

        return new ArrayIterator($events);
    }

    /**
     * Parse the events in an <entry> section
     *
     * @param  DOMElement $entry
     * @param  string     $currentUserId The current user ID as extracted from
     *      the <entry> part
     *
     * @return Event[]
     */
    protected function extractEvents(DOMElement $entry, $currentUserId)
    {
        $events = array();
        $domEvents = $entry->getElementsByTagName('event');

        foreach ($domEvents as $domEvent) {
            $event = new Event();
            $event->setType($this->extractEventType($domEvent));

            $date = $this->getDomElement($domEvent, 'eventdate', 'The event date was not found while building the event journal:\n' . $this->getEventDom($domEvent));
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
                if (substr($path, -1) === '/') {
                    // Jackrabbit might return paths with trailing slashes. Eliminate them if present.
                    $path = substr($path, 0, -1);
                }
                $event->setPath($path);
            }

            $nodeType = $this->getDomElement($domEvent, 'eventprimarynodetype');
            if ($nodeType) {
                $event->setNodeType($nodeType->nodeValue);
            }

            $userData = $this->getDomElement($domEvent, 'eventuserdata');
            if ($userData) {
                $event->setUserData($userData->nodeValue);
            }

            $eventInfos = $this->getDomElement($domEvent, 'eventinfo');
            if ($eventInfos) {
                foreach ($eventInfos->childNodes as $info) {
                    if ($info->nodeType == XML_ELEMENT_NODE) {
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
     * Extract a user id from the author tag in an entry section
     *
     * @param DOMElement $entry
     *
     * @return string user id of the event
     *
     * @throws RepositoryException
     */
    protected function extractUserId(DOMElement $entry)
    {
        $authors = $entry->getElementsByTagName('author');

        if (!$authors->length) {
            throw new RepositoryException("User ID not found while building the event journal");
        }

        $userId = null;
        foreach ($authors->item(0)->childNodes as $child) {
            if ($child instanceof DOMElement) {
                return $child->nodeValue;
            }
        }

        throw new RepositoryException("Malformed user ID while building the event journal");
    }

    /**
     * Extract an event type from a DavEx event journal response
     *
     * @param DOMElement $event
     *
     * @return int The event type
     *
     * @throws RepositoryException
     */
    protected function extractEventType(DOMElement $event)
    {
        $list = $event->getElementsByTagName('eventtype');

        if (!$list->length) {
            throw new RepositoryException("Event type not found while building the event journal");
        }

        // Here we cannot simply take the first child as the <eventtype> tag might contain
        // text fragments (i.e. newlines) that will be returned as DOMText elements.
        foreach ($list->item(0)->childNodes as $el) {
            if ($el instanceof DOMElement) {
                return $this->getEventTypeFromTagName($el->tagName);
            }
        }

        throw new RepositoryException("Malformed event type while building the event journal");
    }

    /**
     * Extract a given DOMElement from the children of another DOMElement
     *
     * @param DOMElement $event        The DOMElement containing the searched tag
     * @param string     $tagName      The name of the searched tag
     * @param string     $errorMessage The error message when the tag was not
     *      found or null if the tag is not required
     *
     * @return DOMNode
     *
     * @throws RepositoryException
     */
    protected function getDomElement(DOMElement $event, $tagName, $errorMessage = null)
    {
        $list = $event->getElementsByTagName($tagName);

        if ($errorMessage && !$list->length) {
            throw new RepositoryException($errorMessage);
        }

        return $list->item(0);
    }

    /**
     * Get the JCR event type from a DavEx tag representing the event type
     *
     * @param string $tagName
     *
     * @return int
     *
     * @throws RepositoryException
     */
    protected function getEventTypeFromTagName($tagName)
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
     * Get the XML representation of a DOMElement to display in error messages
     *
     * @param DOMElement $event
     *
     * @return string
     */
    protected function getEventDom(DOMElement $event)
    {
        return $event->ownerDocument->saveXML($event);
    }
}
