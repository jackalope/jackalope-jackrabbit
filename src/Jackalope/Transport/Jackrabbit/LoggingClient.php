<?php

namespace Jackalope\Transport\Jackrabbit;

use Jackalope\FactoryInterface;
use Jackalope\Query\Query;
use Jackalope\Transport\AbstractReadWriteLoggingWrapper;
use Jackalope\Transport\Logging\LoggerInterface;
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
    public function __construct(FactoryInterface $factory, Client $transport, LoggerInterface $logger)
    {
        parent::__construct($factory, $transport, $logger);
    }

    public function addDefaultHeader(string $header): void
    {
        $this->transport->addDefaultHeader($header);
    }

    public function sendExpect(bool $send = true): void
    {
        $this->transport->sendExpect($send);
    }

    public function setCheckLoginOnServer(bool $bool): void
    {
        $this->transport->setCheckLoginOnServer($bool);
    }

    // VersioningInterface //

    public function addVersionLabel(string $versionPath, string $label, bool $moveLabel): void
    {
        $this->transport->addVersionLabel($versionPath, $label, $moveLabel);
    }

    public function removeVersionLabel(string $versionPath, string $label): void
    {
        $this->transport->removeVersionLabel($versionPath, $label);
    }

    public function checkinItem(string $path): string
    {
        return $this->transport->checkinItem($path);
    }

    public function checkoutItem(string $path): void
    {
        $this->transport->checkoutItem($path);
    }

    public function restoreItem(bool $removeExisting, string $versionPath, string $path): void
    {
        $this->transport->restoreItem($removeExisting, $versionPath, $path);
    }

    public function removeVersion(string $versionPath, string $versionName): void
    {
        $this->transport->removeVersion($versionPath, $versionName);
    }

    // QueryTransport //

    public function query(Query $query): array
    {
        $this->logger->startCall(__FUNCTION__, func_get_args(), ['fetchDepth' => $this->transport->getFetchDepth()]);
        $result = $this->transport->query($query);
        $this->logger->stopCall();

        return $result;
    }

    public function getSupportedQueryLanguages(): array
    {
        return $this->transport->getSupportedQueryLanguages();
    }

    // NodeTypeCndManagementInterface //

    public function registerNodeTypesCnd(string $cnd, bool $allowUpdate): void
    {
        $this->transport->registerNodeTypesCnd($cnd, $allowUpdate);
    }

    // PermissionInterface //

    public function getPermissions(string $path): array
    {
        return $this->transport->getPermissions($path);
    }

    // LockingInterface //

    public function lockNode(string $absPath, bool $isDeep, bool $isSessionScoped, int $timeoutHint = PHP_INT_MAX, string $ownerInfo = null): LockInterface
    {
        return $this->transport->lockNode($absPath, $isDeep, $isSessionScoped, $timeoutHint, $ownerInfo);
    }

    public function isLocked(string $absPath): bool
    {
        return $this->transport->isLocked($absPath);
    }

    public function unlock(string $absPath, string $lockToken): void
    {
        $this->transport->unlock($absPath, $lockToken);
    }

    // ObservationInterface //

    public function getEvents(int $date, EventFilterInterface $filter, SessionInterface $session): \Iterator
    {
        return $this->transport->getEvents($date, $filter, $session);
    }

    public function fetchEventData(int $date): array
    {
        return $this->transport->fetchEventData($date);
    }

    public function setUserData(?string $userData): void
    {
        $this->transport->setUserData($userData);
    }

    public function getUserData(): ?string
    {
        return $this->transport->getUserData();
    }

    // WorkspaceManagementInterface //

    public function createWorkspace(string $name, string $srcWorkspace = null): void
    {
        $this->transport->createWorkspace($name, $srcWorkspace);
    }

    public function deleteWorkspace(string $name): void
    {
        $this->transport->deleteWorkspace($name);
    }

    // JackrabbitClientInterface //

    public function addCurlOptions(array $options): array
    {
        return $this->transport->addCurlOptions($options);
    }

    public function getWorkspaceUri(): ?string
    {
        return $this->transport->getWorkspaceUri();
    }
}
