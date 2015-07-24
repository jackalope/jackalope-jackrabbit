<?php

namespace Jackalope\Transport\Jackrabbit;

use Jackalope\FactoryInterface;
use Jackalope\Transport\AbstractReadWriteLoggingWrapper;
use Jackalope\Transport\QueryInterface as QueryTransport;
use Jackalope\Transport\PermissionInterface;
use Jackalope\Transport\VersioningInterface;
use Jackalope\Transport\NodeTypeCndManagementInterface;
use Jackalope\Transport\LockingInterface;
use Jackalope\Transport\ObservationInterface;
use Jackalope\Transport\WorkspaceManagementInterface;
use Jackalope\Query\Query;
use PHPCR\Observation\EventFilterInterface;
use PHPCR\SessionInterface;
use Jackalope\Transport\Logging\LoggerInterface;

/**
 * Logging enabled wrapper for the Jackalope Jackrabbit client.
 *
 * @license http://www.apache.org/licenses Apache License Version 2.0, January 2004
 * @license http://opensource.org/licenses/MIT MIT License
 *
 * @author Lukas Kahwe Smith <smith@pooteeweet.org>
 */
class LoggingClient extends AbstractReadWriteLoggingWrapper implements QueryTransport, PermissionInterface, VersioningInterface, NodeTypeCndManagementInterface, LockingInterface, ObservationInterface, WorkspaceManagementInterface
{
    /**
     * @var Client
     */
    protected $transport;

    /**
     * Constructor.
     *
     * @param FactoryInterface $factory
     * @param Client           $transport A jackalope jackrabbit client instance
     * @param LoggerInterface  $logger    A logger instance
     */
    public function __construct(FactoryInterface $factory, Client $transport, LoggerInterface $logger)
    {
        parent::__construct($factory, $transport, $logger);
    }

    /**
     * Add a HTTP header which is sent on each Request.
     *
     * This is used for example for a session identifier header to help a proxy
     * to route all requests from the same session to the same server.
     *
     * This is a Jackrabbit Davex specific option called from the repository
     * factory.
     *
     * @param string $header a valid HTTP header to add to each request
     */
    public function addDefaultHeader($header)
    {
        $this->transport->addDefaultHeader($header);
    }

    /**
     * If you want to send the "Expect: 100-continue" header on larger
     * PUT and POST requests, set this to true.
     *
     * This is a Jackrabbit Davex specific option.
     *
     * @param bool $send Whether to send the header or not
     */
    public function sendExpect($send = true)
    {
        $this->transport->sendExpect($send);
    }

    /**
     * Configure whether to check if we are logged in before doing a request.
     *
     * Will improve error reporting at the cost of some round trips.
     */
    public function setCheckLoginOnServer($bool)
    {
        $this->transport->setCheckLoginOnServer($bool);
    }

    // VersioningInterface //

    /**
     * {@inheritDoc}
     */
    public function checkinItem($path)
    {
        return $this->transport->checkinItem($path);
    }

    /**
     * {@inheritDoc}
     */
    public function checkoutItem($path)
    {
        $this->transport->checkoutItem($path);
    }

    /**
     * {@inheritDoc}
     */
    public function restoreItem($removeExisting, $versionPath, $path)
    {
        $this->transport->restoreItem($removeExisting, $versionPath, $path);
    }

    /**
     * {@inheritDoc}
     */
    public function removeVersion($versionPath, $versionName)
    {
        return $this->transport->removeVersion($versionPath, $versionName);
    }

    // QueryTransport //

    /**
     * {@inheritDoc}
     */
    public function query(Query $query)
    {
        $this->logger->startCall(__FUNCTION__, func_get_args(), array('fetchDepth' => $this->transport->getFetchDepth()));
        $result = $this->transport->query($query);
        $this->logger->stopCall();
        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function getSupportedQueryLanguages()
    {
        return $this->transport->getSupportedQueryLanguages();
    }

    /**
     * {@inheritDoc}
     */
    public function registerNamespace($prefix, $uri)
    {
        $this->transport->registerNamespace($prefix, $uri);
    }

    /**
     * {@inheritDoc}
     */
    public function unregisterNamespace($prefix)
    {
        $this->transport->unregisterNamespace($prefix);
    }

    /**
     * {@inheritDoc}
     */
    public function registerNodeTypesCnd($cnd, $allowUpdate)
    {
        $this->transport->registerNodeTypesCnd($cnd, $allowUpdate);
    }

    /**
     * {@inheritDoc}
     */
    public function getPermissions($path)
    {
        return $this->transport->getPermissions($path);
    }

    /**
     * {@inheritDoc}
     */
    public function lockNode($absPath, $isDeep, $isSessionScoped, $timeoutHint = PHP_INT_MAX, $ownerInfo = null)
    {
        return $this->transport->lockNode($absPath, $isDeep, $isSessionScoped, $timeoutHint, $ownerInfo);
    }

    /**
     * {@inheritDoc}
     */
    public function isLocked($absPath)
    {
        return $this->transport->isLocked($absPath);
    }

    /**
     * {@inheritDoc}
     */
    public function unlock($absPath, $lockToken)
    {
        $this->transport->unlock($absPath, $lockToken);
    }

    /**
     * {@inheritDoc}
     */
    public function getEvents($date, EventFilterInterface $filter, SessionInterface $session)
    {
        return $this->transport->getEvents($date, $filter, $session);
    }

    /**
     * {@inheritDoc}
     */
    public function fetchEventData($date)
    {
        return $this->transport->fetchEventData($date);
    }

    /**
     * {@inheritDoc}
     */
    public function setUserData($userData)
    {
        $this->transport->setUserData($userData);
    }

    /**
     * {@inheritDoc}
     */
    public function getUserData()
    {
        return $this->transport->getUserData();
    }

    /**
     * {@inheritDoc}
     */
    public function createWorkspace($name, $srcWorkspace = null)
    {
        $this->transport->createWorkspace($name, $srcWorkspace);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteWorkspace($name)
    {
        $this->transport->deleteWorkspace($name);
    }
}
