<?php

namespace Jackalope\Transport\Jackrabbit;

use Jackalope\FactoryInterface;
use Jackalope\Query\Query;
use Jackalope\Transport\AbstractReadWriteLoggingWrapper;
use Jackalope\Transport\Logging\LoggerInterface;
use Jackalope\Transport\QueryInterface as QueryTransport;
use Jackalope\Transport\VersioningInterface;
use PHPCR\Lock\LockInterface;
use PHPCR\Observation\EventFilterInterface;
use PHPCR\SessionInterface;

/**
 * Logging enabled wrapper for the Jackalope Jackrabbit client.
 *
 * @license http://www.apache.org/licenses Apache License Version 2.0, January 2004
 * @license http://opensource.org/licenses/MIT MIT License
 * @author Lukas Kahwe Smith <smith@pooteeweet.org>
 */
class LoggingClient extends AbstractReadWriteLoggingWrapper implements JackrabbitClientInterface
{
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
    public function addVersionLabel($versionPath, $label, $moveLabel): void
    {
        $this->transport->addVersionLabel($versionPath, $label, $moveLabel);
    }

    /**
     * {@inheritDoc}
     */
    public function removeVersionLabel($versionPath, $label): void
    {
        $this->transport->removeVersionLabel($versionPath, $label);
    }

    /**
     * {@inheritDoc}
     */
    public function checkinItem($path): string
    {
        return $this->transport->checkinItem($path);
    }

    /**
     * {@inheritDoc}
     */
    public function checkoutItem($path): void
    {
        $this->transport->checkoutItem($path);
    }

    /**
     * {@inheritDoc}
     */
    public function restoreItem($removeExisting, $versionPath, $path): void
    {
        $this->transport->restoreItem($removeExisting, $versionPath, $path);
    }

    /**
     * {@inheritDoc}
     */
    public function removeVersion($versionPath, $versionName): void
    {
        $this->transport->removeVersion($versionPath, $versionName);
    }

    // QueryTransport //

    /**
     * {@inheritDoc}
     */
    public function query(Query $query): array
    {
        $this->logger->startCall(__FUNCTION__, func_get_args(), ['fetchDepth' => $this->transport->getFetchDepth()]);
        $result = $this->transport->query($query);
        $this->logger->stopCall();

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function getSupportedQueryLanguages(): array
    {
        return $this->transport->getSupportedQueryLanguages();
    }

    /**
     * {@inheritDoc}
     */
    public function registerNamespace($prefix, $uri): void
    {
        $this->transport->registerNamespace($prefix, $uri);
    }

    /**
     * {@inheritDoc}
     */
    public function unregisterNamespace($prefix): void
    {
        $this->transport->unregisterNamespace($prefix);
    }

    /**
     * {@inheritDoc}
     */
    public function registerNodeTypesCnd($cnd, $allowUpdate): void
    {
        $this->transport->registerNodeTypesCnd($cnd, $allowUpdate);
    }

    /**
     * {@inheritDoc}
     */
    public function getPermissions($path): array
    {
        return $this->transport->getPermissions($path);
    }

    /**
     * {@inheritDoc}
     */
    public function lockNode($absPath, $isDeep, $isSessionScoped, $timeoutHint = PHP_INT_MAX, $ownerInfo = null): LockInterface
    {
        return $this->transport->lockNode($absPath, $isDeep, $isSessionScoped, $timeoutHint, $ownerInfo);
    }

    /**
     * {@inheritDoc}
     */
    public function isLocked($absPath): bool
    {
        return $this->transport->isLocked($absPath);
    }

    /**
     * {@inheritDoc}
     */
    public function unlock($absPath, $lockToken): void
    {
        $this->transport->unlock($absPath, $lockToken);
    }

    /**
     * {@inheritDoc}
     */
    public function getEvents($date, EventFilterInterface $filter, SessionInterface $session): \Iterator
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
    public function setUserData($userData): void
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
    public function createWorkspace($name, $srcWorkspace = null): void
    {
        $this->transport->createWorkspace($name, $srcWorkspace);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteWorkspace($name): void
    {
        $this->transport->deleteWorkspace($name);
    }

    /**
     * {@inheritDoc}
     */
    public function forceHttpVersion10($forceHttpVersion10 = true)
    {
        $this->transport->forceHttpVersion10($forceHttpVersion10);
    }

    /**
     * {@inheritDoc}
     */
    public function addCurlOptions(array $options)
    {
        return $this->transport->addCurlOptions($options);
    }

    /**
     * {@inheritDoc}
     */
    public function getWorkspaceUri()
    {
        return $this->transport->getWorkspaceUri();
    }
}
