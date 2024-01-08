<?php

namespace Jackalope\Transport\Jackrabbit;

use Jackalope\FactoryInterface;
use Jackalope\Lock\Lock;
use Jackalope\Node;
use Jackalope\NodeType\NodeTypeXmlConverter;
use Jackalope\NotImplementedException;
use Jackalope\Property;
use Jackalope\Query\Query;
use Jackalope\Transport\BaseTransport;
use PHPCR\CredentialsInterface;
use PHPCR\ItemExistsException;
use PHPCR\ItemNotFoundException;
use PHPCR\Lock\LockInterface;
use PHPCR\LoginException;
use PHPCR\NodeType\NodeTypeManagerInterface;
use PHPCR\Observation\EventFilterInterface;
use PHPCR\PathNotFoundException;
use PHPCR\PropertyType;
use PHPCR\Query\InvalidQueryException;
use PHPCR\Query\QueryInterface;
use PHPCR\RepositoryException;
use PHPCR\RepositoryInterface;
use PHPCR\SessionInterface;
use PHPCR\SimpleCredentials;
use PHPCR\UnsupportedRepositoryOperationException;
use PHPCR\Util\PathHelper;
use PHPCR\Util\ValueConverter;
use PHPCR\ValueFormatException;
use PHPCR\Version\LabelExistsVersionException;

/**
 * Connection to one Jackrabbit server.
 *
 * This class handles the communication between Jackalope and Jackrabbit over
 * Davex. Once the login method has been called, the workspace is set and can
 * not be changed anymore.
 *
 * We make one exception to the rule that nothing may be cached in the
 * transport: Repository descriptors are considered immutable and cached
 * (because they are also used in startup to check the backend version is
 * compatible).
 *
 * @license http://www.apache.org/licenses Apache License Version 2.0, January 2004
 * @license http://opensource.org/licenses/MIT MIT License
 * @author Christian Stocker <chregu@liip.ch>
 * @author David Buchmann <david@liip.ch>
 * @author Tobias Ebnöther <ebi@liip.ch>
 * @author Roland Schilter <roland.schilter@liip.ch>
 * @author Uwe Jäger <uwej711@googlemail.com>
 * @author Lukas Kahwe Smith <smith@pooteeweet.org>
 * @author Daniel Barsotti <daniel.barsotti@liip.ch>
 * @author Markus Schmucker <markus.sr@gmx.net>
 */
class Client extends BaseTransport implements JackrabbitClientInterface
{
    /**
     * Minimal version requirement for the Jackrabbit backend server.
     */
    public const VERSION = '2.3.6';

    /**
     * Minimal version of the Jackrabbit backend server known to support
     * storing unicode symbols outside of UTF-8 basic multilingual plane.
     *
     * Note: We are sure which exact version of Jackrabbit introduced support
     * for full UTF-8 symbols. This is the lowest version known to work.
     */
    public const UTF8_SUPPORT_MINIMAL_VERSION = '2.18.0';

    /**
     * Description of the namspace to be used for communication with the server.
     *
     * @var string
     */
    public const NS_DCR = 'http://www.day.com/jcr/webdav/1.0';

    /**
     * Identifier of the used namespace.
     *
     * @var string
     */
    public const NS_DAV = 'DAV:';

    /**
     * Value of the <timeout> tag for infinite timeout (since jackrabbit 2.4).
     */
    public const JCR_INFINITE = 'Infinite';

    /**
     * Jackrabbit 2.3.6+2.3.7 return this weird number to say its an infinite lock
     * This has been fixed in 2.4.
     */
    public const JCR_INFINITE_LOCK_TIMEOUT = 2147483;

    /**
     * The path to request to get the Jackrabbit event journal.
     */
    public const JCR_JOURNAL_PATH = '?type=journal';

    protected FactoryInterface $factory;

    private ValueConverter $valueConverter;

    /**
     * Server url including protocol.
     *
     * i.e http://localhost:8080/server/
     * constructor ensures the trailing slash /
     */
    protected string $server;

    /**
     * Workspace name the transport is bound to.
     *
     * Set once login() has been executed and may not be changed later on.
     */
    protected ?string $workspace = null;

    /**
     * Identifier of the workspace including the used protocol and server name.
     *
     * "$server/$workspace" without trailing slash
     */
    protected ?string $workspaceUri = null;

    /**
     * Root node path with server domain without trailing slash.
     *
     * "$server/$workspace/jcr%3aroot
     * (make sure you never hardcode the jcr%3aroot, its ugly)
     *
     * @todo apparently, jackrabbit handles the root node by name - it is invisible everywhere for the api,
     *       but needed when talking to the backend... could that name change?
     */
    protected ?string $workspaceUriRoot = null;

    /**
     * Set of credentials necessary to connect to the server.
     *
     * Set once login() has been executed and may not be changed later on.
     */
    protected ?SimpleCredentials $credentials = null;

    /**
     * The cURL resource handle.
     *
     * @var curl|bool|null
     */
    protected $curl;

    /**
     * A list of additional HTTP headers to be sent on each request.
     *
     * @var string[]
     */
    private array $defaultHeaders = [];

    /**
     * Send Expect: 100-continue header.
     */
    private bool $sendExpect = false;

    private ?NodeTypeXmlConverter $typeXmlConverter = null;

    private ?NodeTypeManagerInterface $nodeTypeManager = null;

    /**
     * Check if an initial PROPFIND should be send to check if repository exists
     * This is according to the JCR specifications and set to true by default.
     *
     * @see setCheckLoginOnServer
     */
    private bool $checkLoginOnServer = true;

    /**
     * Cached result of the repository descriptors.
     *
     * This is our exception to the rule that nothing may be cached in transport.
     *
     * @var string[] as returned by getRepositoryDescriptors
     */
    private ?array $descriptors = null;

    protected array $jsopBody = [];

    private ?string $userData = null;

    /**
     * Global curl-options used in each request.
     */
    private array $curlOptions = [];

    /**
     * Version of the Jackrabbit server as declared in the configuration.
     */
    private ?string $version = null;

    /**
     * Create a transport pointing to a server url.
     *
     * @param FactoryInterface $factory   the object factory
     * @param string           $serverUri location of the server
     */
    public function __construct(FactoryInterface $factory, string $serverUri)
    {
        $this->factory = $factory;
        $this->valueConverter = $this->factory->get(ValueConverter::class);

        // append a slash if not there
        if ('/' !== substr($serverUri, -1)) {
            $serverUri .= '/';
        }

        $this->server = $serverUri;
    }

    /**
     * Tidies up the current cUrl connection.
     */
    public function __destruct()
    {
        $this->logout();
    }

    public function addDefaultHeader(string $header): void
    {
        $this->defaultHeaders[] = $header;
    }

    public function sendExpect(bool $send = true): void
    {
        $this->sendExpect = $send;
    }

    public function addCurlOptions(array $options): array
    {
        return $this->curlOptions += $options;
    }

    /**
     * Makes sure there is an open curl connection.
     *
     * @param string|array $uri
     */
    protected function getRequest(string $method, $uri, bool $addWorkspacePathToUri = true): Request
    {
        $uri = (array) $uri;
        $curl = $this->getCurl();

        if ($addWorkspacePathToUri) {
            foreach ($uri as $key => $row) {
                $uri[$key] = $this->addWorkspacePathToUri($row);
            }
        }

        /** @var Request $request */
        $request = $this->factory->get('Transport\\Jackrabbit\\Request', [$this, $curl, $method, $uri]);

        $request->setCredentials($this->credentials);
        if (null !== $this->userData) {
            $request->addUserData($this->userData);
        }
        foreach ($this->defaultHeaders as $header) {
            $request->addHeader($header);
        }

        if (!$this->sendExpect) {
            $request->addHeader('Expect:');
        }

        $request->addCurlOptions($this->curlOptions);

        return $request;
    }

    private function getCurl(): curl
    {
        if (is_null($this->curl)) {
            // lazy init curl
            $this->curl = new curl();
        } elseif (false === $this->curl) {
            // but do not re-connect, rather report the error if trying to access a closed connection
            throw new \LogicException('Tried to start a request on a closed transport.');
        }

        return $this->curl;
    }

    public function getWorkspaceUri(): ?string
    {
        return $this->workspaceUri;
    }

    // CoreInterface //

    public function login(CredentialsInterface $credentials = null, string $workspaceName = null): string
    {
        if ($this->credentials) {
            throw new RepositoryException(
                'Do not call login twice. Rather instantiate a new Transport object '.
                'to log in as different user or for a different workspace.'
            );
        }
        if (!$credentials instanceof SimpleCredentials) {
            $hint = is_null($credentials)
                ? 'jackalope-jackrabbit does not support "null" credentials'
                : 'Only SimpleCredentials are supported. Unknown credentials type: '.get_class($credentials);
            throw new LoginException($hint);
        }

        $this->credentials = $credentials;

        if (!$workspaceName) {
            $request = $this->getRequest(Request::PROPFIND, $this->server);
            $request->setBody($this->buildPropfindRequest(['dcr:workspaceName']));
            $dom = $request->executeDom();
            $answer = $dom->getElementsByTagNameNS(self::NS_DCR, 'workspaceName');
            $workspaceName = $answer->item(0)->textContent;
        }

        $this->workspace = $workspaceName;
        $this->workspaceUri = $this->server.$workspaceName;
        $this->workspaceUriRoot = $this->workspaceUri.'/jcr:root';

        if (!$this->checkLoginOnServer) {
            return $workspaceName;
        }

        $request = $this->getRequest(Request::PROPFIND, $this->workspaceUri);
        $request->setBody($this->buildPropfindRequest(['D:workspace', 'dcr:workspaceName']));
        $dom = $request->executeDom();

        $set = $dom->getElementsByTagNameNS(self::NS_DCR, 'workspaceName');
        if (1 !== $set->length) {
            throw new RepositoryException('Unexpected answer from server: '.$dom->saveXML());
        }

        if ($set->item(0)->textContent !== $this->workspace) {
            throw new RepositoryException('Wrong workspace in answer from server: '.$dom->saveXML());
        }

        return $workspaceName;
    }

    public function logout(): void
    {
        if (!empty($this->curl)) {
            $this->curl->close();
        }
        $this->curl = false;
    }

    public function setCheckLoginOnServer(bool $bool): void
    {
        $this->checkLoginOnServer = $bool;
    }

    public function getRepositoryDescriptors(): array
    {
        if (null === $this->descriptors) {
            $request = $this->getRequest(Request::REPORT, $this->server);
            $request->setBody($this->buildReportRequest('dcr:repositorydescriptors'));
            $dom = $request->executeDom();

            if ('repositorydescriptors-report' !== $dom->firstChild->localName
                || self::NS_DCR !== $dom->firstChild->namespaceURI
            ) {
                throw new RepositoryException('Error talking to the backend. '.$dom->saveXML());
            }

            $descs = $dom->getElementsByTagNameNS(self::NS_DCR, 'descriptor');
            $this->descriptors = [];
            foreach ($descs as $desc) {
                $name = $desc->getElementsByTagNameNS(self::NS_DCR, 'descriptorkey')->item(0)->textContent;

                $values = [];
                $valuenodes = $desc->getElementsByTagNameNS(self::NS_DCR, 'descriptorvalue');
                foreach ($valuenodes as $value) {
                    $values[] = $value->textContent;
                }
                if (1 === $valuenodes->length) {
                    // there was one type and one value => this is a single value property
                    // TODO: is this the correct assumption? or should the backend tell us specifically?
                    $this->descriptors[$name] = $values[0];
                } else {
                    $this->descriptors[$name] = $values;
                }
            }

            // Supported by Jackrabbit, but not supported by this client
            $this->descriptors[RepositoryInterface::NODE_TYPE_MANAGEMENT_SAME_NAME_SIBLINGS_SUPPORTED] = false;
            $this->descriptors[RepositoryInterface::QUERY_CANCEL_SUPPORTED] = false;

            if (!isset($this->descriptors['jcr.repository.version'])) {
                throw new UnsupportedRepositoryOperationException("The backend at {$this->server} does not provide the jcr.repository.version descriptor");
            }

            if (!version_compare(self::VERSION, $this->descriptors['jcr.repository.version'], '<=')) {
                throw new UnsupportedRepositoryOperationException("The backend at {$this->server} is an unsupported version of jackrabbit: \"".
                    $this->descriptors['jcr.repository.version'].
                    '". Need at least "'.self::VERSION.'"');
            }

            if ($this->version) {
                // Sanity check if the configured version has the same major and minor number as the version reported by the backend.
                $serverVersion = implode('.', array_slice(explode('.', $this->descriptors['jcr.repository.version']), 0, 2));
                $configuredVersion = implode('.', array_slice(explode('.', $this->version), 0, 2));

                if (!version_compare($serverVersion, $configuredVersion, '==')) {
                    trigger_error(
                        sprintf(
                            'Version mismatch between configured version %s and version %s reported by the backend at %s.',
                            $this->version,
                            $this->descriptors['jcr.repository.version'],
                            $this->server
                        ),
                        E_USER_NOTICE
                    );
                }
            }
        }

        return $this->descriptors;
    }

    public function getAccessibleWorkspaceNames(): array
    {
        $request = $this->getRequest(Request::PROPFIND, $this->server);
        $request->setBody($this->buildPropfindRequest(['D:workspace']));
        $request->setDepth(1);
        $dom = $request->executeDom();

        $workspaces = [];
        foreach ($dom->getElementsByTagNameNS(self::NS_DAV, 'workspace') as $value) {
            if (!empty($value->nodeValue)) {
                $workspaces[] = substr(trim($value->nodeValue), strlen($this->server), -1);
            }
        }

        return array_unique($workspaces);
    }

    public function getNode($path): \stdClass
    {
        $path = $this->encodeAndValidatePathForDavex($path);
        $path .= '.'.$this->getFetchDepth().'.json';

        $request = $this->getRequest(Request::GET, $path);
        try {
            return $request->executeJson();
        } catch (PathNotFoundException $e) {
            throw new ItemNotFoundException($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function getNodes(array $paths, string $query = ':include'): array
    {
        if (0 === count($paths)) {
            return [];
        }

        if (1 === count($paths)) {
            $url = array_shift($paths);
            try {
                return [$url => $this->getNode($url)];
            } catch (ItemNotFoundException $e) {
                return [];
            }
        }
        $body = [];

        $url = '/.'.$this->getFetchDepth().'.json';
        foreach ($paths as $path) {
            $body[] = http_build_query([$query => $path]);
        }
        $body = implode('&', $body);

        $request = $this->getRequest(Request::POST, $url);
        $request->setBody($body);
        $request->setContentType('application/x-www-form-urlencoded; charset=utf-8');

        try {
            $data = $request->executeJson();

            return (array) $data->nodes;
        } catch (PathNotFoundException $e) {
            throw new ItemNotFoundException($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function getNodesByIdentifier(array $identifiers): array
    {
        // OPTIMIZE get paths for UUID's via a single query
        // or get the data directly
        // return $this->getNodes($identifiers, ':id');

        $paths = [];
        foreach ($identifiers as $key => $identifier) {
            try {
                $paths[$key] = $this->getNodePathForIdentifier($identifier);
            } catch (ItemNotFoundException $e) {
                // ignore
            }
        }

        return $this->getNodes($paths);
    }

    public function getNodeByIdentifier(string $uuid): \stdClass
    {
        // OPTIMIZE get nodes directly by uuid from backend. needs implementation on jackrabbit
        $path = $this->getNodePathForIdentifier($uuid);
        $data = $this->getNode($path);
        $data->{':jcr:path'} = $path;

        return $data;
    }

    public function getNodePathForIdentifier(string $uuid, string $workspace = null): string
    {
        if (null !== $workspace && $workspace !== $this->workspace) {
            $client = new Client($this->factory, $this->server);
            $client->login($this->credentials, $workspace);

            return $client->getNodePathForIdentifier($uuid);
        }

        $request = $this->getRequest(Request::REPORT, $this->workspaceUri);
        $request->setBody($this->buildLocateRequest($uuid));
        $dom = $request->executeDom();

        /* answer looks like
           <D:multistatus xmlns:D="DAV:">
             <D:response>
                 <D:href>http://localhost:8080/server/tests/jcr%3aroot/tests_level1_access_base/idExample/</D:href>
             </D:response>
         </D:multistatus>
        */
        $set = $dom->getElementsByTagNameNS(self::NS_DAV, 'href');
        if (1 !== $set->length) {
            throw new RepositoryException('Unexpected answer from server: '.$dom->saveXML());
        }
        $fullPath = $set->item(0)->textContent;
        if (strncmp($this->workspaceUriRoot, $fullPath, strlen($this->workspaceUri))) {
            throw new RepositoryException(
                "Server answered a path that is not in the current workspace: uuid=$uuid, path=$fullPath, workspace=".
                    $this->workspaceUriRoot
            );
        }

        return $this->stripServerRootFromUri(substr(urldecode($fullPath), 0, -1));
    }

    public function getProperty(string $path): \stdClass
    {
        throw new NotImplementedException();
        /*
         * TODO: implement
         * jackrabbit: instead of fetching the node, we could make Transport provide it with a
         * GET /server/tests/jcr%3aroot/tests_level1_access_base/multiValueProperty/jcr%3auuid
         * (davex getItem uses json, which is not applicable to properties)
         */
    }

    public function getBinaryStream(string $path)
    {
        $path = $this->encodeAndValidatePathForDavex($path);
        $request = $this->getRequest(Request::GET, $path);
        $curl = $request->execute(true);
        switch (strtolower($curl->getHeader('Content-Type'))) {
            case 'text/xml; charset=utf-8':
            case 'text/xml;charset=utf-8':
                return $this->decodeBinaryDom($curl->getResponse());
            case 'jcr-value/binary;charset=utf-8':
            case 'jcr-value/binary; charset=utf-8':
                // TODO: OPTIMIZE stream handling!
                $stream = fopen('php://memory', 'rwb+');
                fwrite($stream, $curl->getResponse());
                rewind($stream);

                return $stream;
        }

        throw new RepositoryException('Unknown encoding of binary data: '.$curl->getHeader('Content-Type'));
    }

    /**
     * parse the multivalue binary response (a list of base64 encoded values).
     *
     * <dcr:values xmlns:dcr="http://www.day.com/jcr/webdav/1.0">
     *   <dcr:value dcr:type="Binary">aDEuIENoYXB0ZXIgMSBUaXRsZQoKKiBmb28KKiBiYXIKKiogZm9vMgoqKiBmb28zCiogZm9vMAoKfHwgaGVhZGVyIHx8IGJhciB8fAp8IGggfCBqIHwKCntjb2RlfQpoZWxsbyB3b3JsZAp7Y29kZX0KCiMgZm9vCg==</dcr:value>
     *   <dcr:value dcr:type="Binary">aDEuIENoYXB0ZXIgMSBUaXRsZQoKKiBmb28KKiBiYXIKKiogZm9vMgoqKiBmb28zCiogZm9vMAoKfHwgaGVhZGVyIHx8IGJhciB8fAp8IGggfCBqIHwKCntjb2RlfQpoZWxsbyB3b3JsZAp7Y29kZX0KCiMgZm9vCg==</dcr:value>
     * </dcr:values>
     *
     * @param string $xml the xml as returned by jackrabbit
     *
     * @return array of stream resources
     *
     * @throws RepositoryException if the xml is invalid or any value is not of type binary
     */
    private function decodeBinaryDom(string $xml): array
    {
        $dom = new \DOMDocument();
        if (!$dom->loadXML($xml)) {
            throw new RepositoryException("Failed to load xml data:\n\n$xml");
        }

        $ret = [];
        foreach ($dom->getElementsByTagNameNS(self::NS_DCR, 'values') as $node) {
            foreach ($node->getElementsByTagNameNS(self::NS_DCR, 'value') as $value) {
                if (PropertyType::TYPENAME_BINARY !== $value->getAttributeNS(self::NS_DCR, 'type')) {
                    throw new RepositoryException('Expected binary value but got '.$value->getAttributeNS(self::NS_DCR, 'type'));
                }
                // TODO: OPTIMIZE stream handling!
                $stream = fopen('php://memory', 'rwb+');
                fwrite($stream, base64_decode($value->textContent));
                rewind($stream);
                $ret[] = $stream;
            }
        }

        return $ret;
    }

    public function getReferences(string $path, string $name = null): array
    {
        return $this->getNodeReferences($path, $name);
    }

    public function getWeakReferences(string $path, string $name = null): array
    {
        return $this->getNodeReferences($path, $name, true);
    }

    /**
     * @param string      $path           the path for which we need the references
     * @param string|null $name           the name of the referencing properties or null for all
     * @param bool        $weak_reference whether to get weak or strong references
     *
     * @return array list of paths to nodes that reference $path
     */
    private function getNodeReferences(string $path, string $name = null, bool $weak_reference = false): array
    {
        $path = $this->encodeAndValidatePathForDavex($path);
        $identifier = $weak_reference ? 'weakreferences' : 'references';
        $request = $this->getRequest(Request::PROPFIND, $path);
        $request->setBody($this->buildPropfindRequest(['dcr:'.$identifier]));
        $request->setDepth(0);
        $dom = $request->executeDom();

        $references = [];

        foreach ($dom->getElementsByTagNameNS(self::NS_DCR, $identifier) as $node) {
            foreach ($node->getElementsByTagNameNS(self::NS_DAV, 'href') as $ref) {
                $refpath = str_replace($this->workspaceUriRoot, '', urldecode($ref->textContent));
                $refpath = $this->removeTrailingSlash($refpath);
                if (null === $name || PathHelper::getNodeName($refpath) === $name) {
                    $references[] = $refpath;
                }
            }
        }

        return $references;
    }

    /**
     * Remove the trailing slash if present. Used for backend responses when
     * jackrabbit is sloppy.
     */
    private function removeTrailingSlash(string $path): string
    {
        if (strlen($path) <= 1) {
            return '/';
        }

        if ('/' !== $path[strlen($path) - 1]) {
            // no trailing slash
            return $path;
        }

        return substr($path, 0, -1);
    }

    // VersioningInterface //

    public function addVersionLabel(string $versionPath, string $label, bool $moveLabel): void
    {
        $versionPath = $this->encodeAndValidatePathForDavex($versionPath);

        $action = 'add';
        if ($moveLabel) {
            $action = 'set';
        }

        $body = '<D:label xmlns:D="DAV:"><D:'.$action.'><D:label-name>'.$label.'</D:label-name></D:'.$action.'></D:label>';

        $request = $this->getRequest(Request::LABEL, $versionPath);
        $request->setBody($body);
        try {
            $request->execute(); // errors are checked in request
        } catch (HTTPErrorException $e) {
            if (409 === $e->getCode()) {
                throw new LabelExistsVersionException($e->getMessage());
            }
            throw new RepositoryException($e->getMessage());
        }
    }

    public function removeVersionLabel(string $versionPath, string $label): void
    {
        $versionPath = $this->encodeAndValidatePathForDavex($versionPath);

        $body = '<D:label xmlns:D="DAV:"><D:remove><D:label-name>'.$label.'</D:label-name></D:remove></D:label>';

        $request = $this->getRequest(Request::LABEL, $versionPath);
        $request->setBody($body);
        $request->execute();
    }

    public function checkinItem(string $path): string
    {
        $path = $this->encodeAndValidatePathForDavex($path);
        try {
            $request = $this->getRequest(Request::CHECKIN, $path);
            $curl = $request->execute(true);
            if ($curl->getHeader('Location')) {
                return $this->removeTrailingSlash(
                    $this->stripServerRootFromUri(urldecode($curl->getHeader('Location')))
                );
            }
        } catch (HTTPErrorException $e) {
            if (405 === $e->getCode()) {
                throw new UnsupportedRepositoryOperationException();
            }
            throw new RepositoryException($e->getMessage());
        }

        throw new RepositoryException();
    }

    public function checkoutItem(string $path): void
    {
        $path = $this->encodeAndValidatePathForDavex($path);
        try {
            $request = $this->getRequest(Request::CHECKOUT, $path);
            $request->execute();
        } catch (HTTPErrorException $e) {
            if (405 === $e->getCode()) {
                // TODO: when checking out a non-versionable node, we get here too. in that case the exception is very wrong
                throw new UnsupportedRepositoryOperationException($e->getMessage());
            }
            throw new RepositoryException($e->getMessage());
        }
    }

    public function restoreItem(bool $removeExisting, string $versionPath, string $path): void
    {
        $path = $this->encodeAndValidatePathForDavex($path);

        $body =
'<D:update xmlns:D="DAV:">
    <D:version>
        <D:href>'.$this->addWorkspacePathToUri($versionPath).'</D:href>
    </D:version>
';
        if ($removeExisting) {
            $body .= '<dcr:removeexisting xmlns:dcr="http://www.day.com/jcr/webdav/1.0" />';
        }
        $body .= '</D:update>';

        $request = $this->getRequest(Request::UPDATE, $path);
        $request->setBody($body);
        $request->execute(); // errors are checked in request
    }

    public function removeVersion(string $versionPath, string $versionName): void
    {
        $path = $this->encodeAndValidatePathForDavex($versionPath.'/'.$versionName);
        $request = $this->getRequest(Request::DELETE, $path);
        $request->execute();
    }

    // QueryTransport //

    public function query(Query $query): array
    {
        // TODO handle bind variables
        $querystring = $query->getStatement();
        $limit = $query->getLimit();
        $offset = $query->getOffset();
        $language = $query->getLanguage();

        switch ($language) {
            case QueryInterface::JCR_JQOM:
                // for JQOM, fall through to SQL2
            case QueryInterface::JCR_SQL2:
                $ns = '';
                $langElement = 'JCR-SQL2';
                break;
            case QueryInterface::XPATH:
                $langElement = 'dcr:xpath';
                $ns = 'xmlns:dcr="http://www.day.com/jcr/webdav/1.0"';
                break;
            case QueryInterface::SQL:
                $langElement = 'dcr:sql';
                $ns = 'xmlns:dcr="http://www.day.com/jcr/webdav/1.0"';
                break;
            default:
                // this should be impossible as we check on creation already
                throw new InvalidQueryException("Unsupported query language: $language");
        }
        $body = '<D:searchrequest '.$ns.' xmlns:D="DAV:"><'.$langElement.'><![CDATA['.$querystring.']]></'.$langElement.'>';

        if (null !== $limit || null !== $offset) {
            $body .= '<D:limit>';
            if (null !== $limit) {
                $body .= '<D:nresults>'.(int) $limit.'</D:nresults>';
            }
            if (null !== $offset) {
                $body .= '<offset>'.(int) $offset.'</offset>';
            }
            $body .= '</D:limit>';
        }

        $body .= '</D:searchrequest>';

        $path = $this->addWorkspacePathToUri('/');
        $request = $this->getRequest(Request::SEARCH, $path);
        $request->setBody($body);

        $rawData = $request->execute();

        $dom = new \DOMDocument();
        $dom->loadXML($rawData);
        $domXpath = new \DOMXPath($dom);

        $rows = [];
        foreach ($domXpath->query('D:response') as $row) {
            $columns = [];
            foreach ($row->getElementsByTagName('column') as $column) {
                $sets = [];
                foreach ($column->childNodes as $childNode) {
                    if ('dcr:value' === $childNode->tagName) {
                        $value = $this->getDcrValue($childNode);
                        // TODO if this bug is fixed, spaces may be urlencoded instead of the escape sequence: https://issues.apache.org/jira/browse/JCR-2997
                        // the following line fails for nodes with "_x0020 " in their name, changing that part to " x0020_"
                        // other characters like < and > are urlencoded, which seems to be handled by dom already.
                        if (is_string($value)) {
                            $value = str_replace('_x0020_', ' ', $value);
                        }
                    } else {
                        $value = $childNode->nodeValue;
                    }
                    $sets[$childNode->tagName] = $value;
                }

                if (!isset($sets['dcr:value'])) {
                    $sets['dcr:value'] = null;
                }

                $columns[] = $sets;
            }

            $rows[] = $columns;
        }

        return $rows;
    }

    public function getSupportedQueryLanguages(): array
    {
        return [
            QueryInterface::JCR_SQL2,
            QueryInterface::JCR_JQOM,
            QueryInterface::XPATH,
            QueryInterface::SQL,
        ];
    }

    /**
     * Get the value of a dcr:value node in the right format specified by the
     * dcr type.
     *
     * This uses PropertyType but takes into account the special case that
     * boolean false is encoded as string "false" which is otherwise true in php.
     *
     * <dcr:value dcr:type="Boolean">false</dcr:value>
     *
     * @param \DOMElement $node a dcr:value xml element
     *
     * @return mixed the node value converted to the specified type
     */
    private function getDcrValue(\DOMElement $node)
    {
        $type = $node->getAttribute('dcr:type');
        if (PropertyType::TYPENAME_BOOLEAN === $type && 'false' === $node->nodeValue) {
            return false;
        }

        return $this->valueConverter->convertType($node->nodeValue, PropertyType::valueFromName($type));
    }

    // WritingInterface //

    public function deleteNodes(array $operations): void
    {
        // Reverse sort the batch; work-around for problem with
        // deleting same-name siblings. Not guaranteed to work
        // across multiple calls to deleteNodes().
        usort($operations, static function ($a, $b) {
            $aParts = [];
            $bParts = [];
            $regex = '/^(.+?)(?:\[(\d+)])?$/';

            preg_match($regex, $a->srcPath, $aParts);
            preg_match($regex, $b->srcPath, $bParts);

            $aPath = $aParts[1];
            $bPath = $bParts[1];
            if ($aPath !== $bPath) {
                return strcmp($bPath, $aPath);
            }
            $aIndex = $aParts[2] ?? 1;
            $bIndex = $bParts[2] ?? 1;

            return $bIndex - $aIndex;
        });

        foreach ($operations as $operation) {
            $this->deleteItem($operation->srcPath);
        }
    }

    public function deleteProperties(array $operations): void
    {
        foreach ($operations as $operation) {
            $this->deleteItem($operation->srcPath);
        }
    }

    public function deleteNodeImmediately(string $path): void
    {
        $this->prepareSave();
        $this->deleteItem($path);
        $this->finishSave();
    }

    public function deletePropertyImmediately(string $path): void
    {
        $this->prepareSave();
        $this->deleteItem($path);
        $this->finishSave();
    }

    /**
     * Record that we need to delete the item at $path.
     *
     * @param string $path path to node or property
     */
    private function deleteItem(string $path): void
    {
        PathHelper::assertValidAbsolutePath($path);

        $this->setJsopBody('-'.$path.' : ');
    }

    public function copyNode(string $srcAbsPath, string $destAbsPath, string $srcWorkspace = null): void
    {
        if ($srcWorkspace) {
            $this->copyNodeOtherWorkspace($srcAbsPath, $destAbsPath, $srcWorkspace);
        } else {
            $this->copyNodeSameWorkspace($srcAbsPath, $destAbsPath);
        }
    }

    /**
     * For copy within the same workspace, this is a COPY request.
     *
     * @param string $srcAbsPath  Absolute source path to the node
     * @param string $destAbsPath Absolute destination path including the new
     *                            node name
     */
    private function copyNodeSameWorkspace(string $srcAbsPath, string $destAbsPath): void
    {
        $srcAbsPath = $this->encodeAndValidatePathForDavex($srcAbsPath);
        $destAbsPath = $this->encodeAndValidatePathForDavex($destAbsPath);

        $request = $this->getRequest(Request::COPY, $srcAbsPath);
        $request->setDepth(Request::INFINITY);
        $request->addHeader('Destination: '.$this->addWorkspacePathToUri($destAbsPath));
        $request->execute();
    }

    /**
     * For copy from a different workspace, needs to be a JSOP.
     *
     * As seen with jackrabbit 2.6
     *
     * @param string $srcAbsPath   Absolute source path to the node
     * @param string $destAbsPath  Absolute destination path including the new
     *                             node name
     * @param string $srcWorkspace The workspace where the source node can be
     *                             found or null for current workspace
     */
    private function copyNodeOtherWorkspace(string $srcAbsPath, string $destAbsPath, string $srcWorkspace): void
    {
        $request = $this->getRequest(Request::POST, $this->workspaceUri);
        $request->setContentType('application/x-www-form-urlencoded; charset=utf-8');
        $request->setBody(urlencode(':copy').'='.urlencode($srcWorkspace.','.$srcAbsPath.','.$destAbsPath));
        $request->execute();
    }

    public function moveNodes(array $operations): void
    {
        foreach ($operations as $operation) {
            PathHelper::assertValidAbsolutePath($operation->srcPath);
            PathHelper::assertValidAbsolutePath($operation->dstPath);

            $this->setJsopBody('>'.$operation->srcPath.' : '.$operation->dstPath);
        }
    }

    public function moveNodeImmediately(string $srcAbsPath, string $destAbsPath): void
    {
        $request = $this->getRequest(Request::MOVE, $srcAbsPath);
        $request->setDepth(Request::INFINITY);
        $request->addHeader('Destination: '.$this->addWorkspacePathToUri($destAbsPath));
        $request->execute();
    }

    public function reorderChildren(Node $node): void
    {
        $reorders = $node->getOrderCommands();

        if (0 === count($reorders)) {
            // should not happen but safe is safe
            return;
        }

        $body = '';
        $path = $node->getPath();
        foreach ($reorders as $child => $destination) {
            if (is_null($destination)) {
                $body .= ">$path/$child : #last\r";
            } else {
                $body .= ">$path/$child : $destination#before\r";
            }
        }
        $this->setJsopBody(trim($body));
    }

    public function cloneFrom(string $srcWorkspace, string $srcAbsPath, string $destAbsPath, bool $removeExisting): void
    {
        $srcAbsPath = $this->encodeAndValidatePathForDavex($srcAbsPath);
        $destAbsPath = $this->encodeAndValidatePathForDavex($destAbsPath);

        // avoid creating a same name sibling as we don't handle them but jackrabbit does
        $this->checkForExistingNode($srcWorkspace, $srcAbsPath, $destAbsPath);

        $body = urlencode(':clone').'='
            .urlencode($srcWorkspace.','.$srcAbsPath.','.$destAbsPath.','.($removeExisting ? 'true' : 'false'));

        $request = $this->getRequest(Request::POST, $this->workspaceUri);
        $request->setBody($body);
        $request->setContentType('application/x-www-form-urlencoded');
        $request->execute();
    }

    /**
     * Prevent accidental creation of same name siblings during clone operation.
     *
     * Jackrabbit supports them, but jackalope does not.
     *
     * @throws ItemExistsException
     */
    private function checkForExistingNode(string $srcWorkspace, string $srcAbsPath, string $destAbsPath): void
    {
        try {
            $existingNode = $this->getNode($destAbsPath);
        } catch (ItemNotFoundException $exception) {
            return;
        }

        if (empty($existingNode->{'jcr:uuid'})) {
            throw new ItemExistsException('A node already exists at the destination path');
        }

        $existingNodeUuid = $existingNode->{'jcr:uuid'};

        try {
            $correspondingPath = $this->getNodePathForIdentifier($existingNodeUuid, $srcWorkspace);
        } catch (ItemNotFoundException $exception) {
            $correspondingPath = null;
        }

        if ($correspondingPath !== $srcAbsPath) {
            throw new ItemExistsException(
                'A node already exists at the destination path that does not correspond to the source node'
            );
        }
    }

    public function updateNode(Node $node, string $srcWorkspace): void
    {
        $path = $this->encodeAndValidatePathForDavex($node->getPath());
        $srcWorkspaceUri = $this->server.$srcWorkspace;

        $body = '
            <D:update xmlns:D="DAV:">
                <D:workspace>
                    '.$srcWorkspaceUri.'
                    <D:href>
                        '.$srcWorkspaceUri.'
                    </D:href>
                </D:workspace>
            </D:update>
        ';

        $request = $this->getRequest(Request::UPDATE, $path);
        $request->setBody($body);
        $request->execute();
    }

    public function storeNodes(array $operations): void
    {
        foreach ($operations as $operation) {
            if ($operation->node->isDeleted()) {
                $properties = $operation->node->getPropertiesForStoreDeletedNode();
            } else {
                $properties = $operation->node->getProperties();
            }
            $this->createNodeJsop($operation->srcPath, $properties);
        }
    }

    private function storeProperty(Property $property): void
    {
        $path = $property->getPath();
        $typeid = $property->getType();
        $nativeValue = $property->getValueForStorage();
        if (PropertyType::STRING === $typeid) {
            foreach ((array) $nativeValue as $string) {
                if (!$this->isStringValid($string)) {
                    throw new ValueFormatException('Invalid character found in property "'.$property->getName().'". Are you passing a valid string?');
                }
            }
        }

        $value = $this->propertyToJsopString($property);
        if (!$value) {
            $this->setJsopBody($nativeValue, $path, $typeid);
            if (is_array($nativeValue)) {
                $this->setJsopBody('^'.$path.' : []');
            } else {
                $this->setJsopBody('^'.$path.' : ');
            }
        } else {
            $encoded = json_encode($value);

            if (PropertyType::DOUBLE === $property->getType()
                && !strpos($encoded, '.')
            ) {
                $encoded .= '.0';
            }
            $this->setJsopBody('^'.$path.' : '.$encoded);
        }
    }

    /**
     * Checks for occurrence of invalid UTF characters, that can not occur in valid XML document.
     * If occurrence is found, returns false, otherwise true.
     * Invalid characters were taken from this list: http://en.wikipedia.org/wiki/Valid_characters_in_XML#XML_1.0.
     *
     * Uses regexp built upon: http://stackoverflow.com/a/961504, https://stackoverflow.com/a/30240915
     */
    private function isStringValid(string $string): bool
    {
        $regex = '/[^\x{9}\x{a}\x{d}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]+/u';

        if ($this->version && version_compare($this->version, self::UTF8_SUPPORT_MINIMAL_VERSION, '>=')) {
            // unicode symbols outside of bmp such as emojis are supported only by recent jackrabbit versions
            $regex = '/[^\x{9}\x{a}\x{d}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]+/u';
        }

        return 0 === preg_match($regex, $string, $matches);
    }

    public function updateProperties(Node $node): void
    {
        $this->updateLastModified($node);
        foreach ($node->getProperties() as $property) {
            /** @var $property Property */
            if ($property->isModified() || $property->isNew()) {
                $this->storeProperty($property);
            }
        }
    }

    /**
     * Update the lastModified fields if they where not set manually.
     *
     * Note that we can drop this if this jackrabbit issue ever gets
     * implemented https://issues.apache.org/jira/browse/JCR-2233
     */
    private function updateLastModified(Node $node): void
    {
        if (!$this->getAutoLastModified() || !$node->isNodeType('mix:lastModified')) {
            return;
        }

        if ($node->hasProperty('jcr:lastModified')
            && !$node->getProperty('jcr:lastModified')->isModified()
            && !$node->getProperty('jcr:lastModified')->isNew()
        ) {
            $node->setProperty('jcr:lastModified', new \DateTime());
        }
        if ($node->hasProperty('jcr:lastModifiedBy')
            && !$node->getProperty('jcr:lastModifiedBy')->isModified()
            && !$node->getProperty('jcr:lastModifiedBy')->isNew()
        ) {
            $node->setProperty('jcr:lastModifiedBy', $this->credentials->getUserID());
        }
    }

    /**
     * create the node markup and a list of value dispatches for multivalue properties.
     *
     * @param string   $path       path to the current node with the last path segment
     *                             being the node name
     * @param iterable $properties of this node
     */
    private function createNodeJsop(string $path, iterable $properties): void
    {
        $body = '+'.$path.' : {';
        $binaries = [];
        // first do the main properties, so they are certainly in the beginning
        $nodeCreationProperties = ['jcr:primaryType', 'jcr:mixinTypes'];
        foreach ($nodeCreationProperties as $name) {
            if (isset($properties[$name])) {
                $body .= json_encode($name).':'.json_encode($properties[$name]->getValueForStorage()).',';
            }
        }

        foreach ($properties as $name => $property) {
            if (in_array($name, $nodeCreationProperties, true)) {
                continue;
            }
            $value = $this->propertyToJsopString($property);
            if (!$value) {
                $binaries[] = $property;
            } else {
                $body .= json_encode($name).':'.json_encode($value).',';
            }
        }

        $body .= '}';
        $this->setJsopBody($body);

        foreach ($binaries as $binary) {
            $this->storeProperty($binary);
        }
    }

    /**
     * This method is used when building a JSOP of the properties.
     */
    private function propertyToJsopString(Property $property)
    {
        switch ($property->getType()) {
            case PropertyType::DOUBLE:
                return $this->valueConverter->convertType($property->getValueForStorage(), PropertyType::DOUBLE);
            case PropertyType::LONG:
                return $this->valueConverter->convertType($property->getValueForStorage(), PropertyType::LONG);
            case PropertyType::DATE:
            case PropertyType::DECIMAL:
            case PropertyType::WEAKREFERENCE:
            case PropertyType::REFERENCE:
            case PropertyType::BINARY:
            case PropertyType::PATH:
            case PropertyType::URI:
                return null;
            case PropertyType::NAME:
                if ('jcr:primaryType' !== $property->getName()) {
                    return null;
                }
                break;
        }

        return $property->getValueForStorage();
    }

    public function getNamespaces(): array
    {
        $request = $this->getRequest(Request::REPORT, $this->workspaceUri);
        $request->setBody($this->buildReportRequest('dcr:registerednamespaces'));
        $dom = $request->executeDom();

        if ('registerednamespaces-report' !== $dom->firstChild->localName
            || self::NS_DCR !== $dom->firstChild->namespaceURI
        ) {
            throw new RepositoryException('Error talking to the backend. '.$dom->saveXML());
        }

        $mappings = [];
        $namespaces = $dom->getElementsByTagNameNS(self::NS_DCR, 'namespace');
        foreach ($namespaces as $elem) {
            $mappings[$elem->firstChild->textContent] = $elem->lastChild->textContent;
        }

        return $mappings;
    }

    public function registerNamespace(string $prefix, string $uri): void
    {
        // seems jackrabbit always expects full list of namespaces
        $namespaces = $this->getNamespaces();

        // check if prefix is already mapped
        if (array_key_exists($prefix, $namespaces)) {
            if ($namespaces[$prefix] === $uri) {
                // nothing to do, we already have the mapping
                return;
            }
            // unregister old mapping
            throw new UnsupportedRepositoryOperationException("Trying to set existing prefix $prefix from ".$namespaces[$prefix]." to different uri $uri, but unregistering namespace is not supported by jackrabbit backend. You can move the old namespace to a different prefix before adding this prefix to work around this issue.");
        }

        // if target uri already exists elsewhere, do not re-send or result is random
        /* weird: we can not unset this or we get the unregister not
         * supported exception. but we can send two mappings and
         * jackrabbit does the right guess what we want and moves the
         * namespace to the new prefix

        if (false !== $expref = array_search($uri, $namespaces)) {
            unset($namespaces[$expref]);
        }
        */

        $request = $this->getRequest(Request::PROPPATCH, $this->workspaceUri);
        $namespaces[$prefix] = $uri;
        $request->setBody($this->buildRegisterNamespaceRequest($namespaces));
        $request->execute();
    }

    public function unregisterNamespace(string $prefix): void
    {
        throw new UnsupportedRepositoryOperationException('Unregistering namespace not supported by jackrabbit backend');
        /*
         * TODO: could look a bit like the following if the backend would support it
        $request = $this->getRequest(Request::PROPPATCH, $this->workspaceUri);
        // seems jackrabbit always expects full list of namespaces
        $namespaces = $this->getNamespaces();
        unset($namespaces[$prefix]);
        $request->setBody($this->buildRegisterNamespaceRequest($namespaces));
        $request->execute();
        */
    }

    public function getNodeTypes(array $nodeTypes = []): array
    {
        $request = $this->getRequest(Request::REPORT, $this->workspaceUriRoot);
        $request->setBody($this->buildNodeTypesRequest($nodeTypes));
        $dom = $request->executeDom();

        if ('nodeTypes' !== $dom->firstChild->localName) {
            throw new RepositoryException('Error talking to the backend. '.$dom->saveXML());
        }

        if (null === $this->typeXmlConverter) {
            $this->typeXmlConverter = $this->factory->get('NodeType\\NodeTypeXmlConverter');
        }

        return $this->typeXmlConverter->getNodeTypesFromXml($dom);
    }

    // NodeTypeCndManagementInterface //

    public function registerNodeTypesCnd(string $cnd, bool $allowUpdate): void
    {
        $request = $this->getRequest(Request::PROPPATCH, $this->workspaceUri);
        $request->setBody($this->buildRegisterNodeTypeRequest($cnd, $allowUpdate));
        $request->execute();
    }

    // PermissionInterface //

    public function getPermissions($path): array
    {
        // TODO: OPTIMIZE - once we have ACL this might be done without any server request
        $body = '<?xml version="1.0" encoding="UTF-8"?>'.
                '<dcr:privileges xmlns:dcr="http://www.day.com/jcr/webdav/1.0">'.
                '<D:href xmlns:D="DAV:">'.$this->addWorkspacePathToUri($path).'</D:href>'.
                '</dcr:privileges>';

        $valid_permissions = [
            SessionInterface::ACTION_ADD_NODE,
            SessionInterface::ACTION_READ,
            SessionInterface::ACTION_REMOVE,
            SessionInterface::ACTION_SET_PROPERTY, ];

        $result = [];

        $request = $this->getRequest(Request::REPORT, $this->workspaceUri);
        $request->setBody($body);
        $dom = $request->executeDom();

        foreach ($dom->getElementsByTagNameNS(self::NS_DAV, 'current-user-privilege-set') as $node) {
            foreach ($node->getElementsByTagNameNS(self::NS_DAV, 'privilege') as $privilege) {
                foreach ($privilege->childNodes as $child) {
                    $permission = str_replace('dcr:', '', $child->tagName);
                    if (!in_array($permission, $valid_permissions, true)) {
                        throw new RepositoryException("Invalid permission '$permission'");
                    }
                    $result[] = $permission;
                }
            }
        }

        return $result;
    }

    public function lockNode(string $absPath, bool $isDeep, bool $isSessionScoped, int $timeoutHint = PHP_INT_MAX, string $ownerInfo = null): LockInterface
    {
        $timeout = PHP_INT_MAX === $timeoutHint ? 'infinite' : $timeoutHint;
        $ownerInfo = $ownerInfo ?? $this->credentials->getUserID();

        $depth = $isDeep ? Request::INFINITY : 0;

        $lockScope = $isSessionScoped ? '<dcr:exclusive-session-scoped xmlns:dcr="http://www.day.com/jcr/webdav/1.0"/>' : '<D:exclusive/>';

        $request = $this->getRequest(Request::LOCK, $absPath);
        $request->addHeader('Timeout: Second-'.$timeout);
        $request->setDepth($depth);

        $request->setBody('<?xml version="1.0" encoding="utf-8"?>'.
            '<D:lockinfo xmlns:D="'.self::NS_DAV.'">'.
            '  <D:lockscope>'.$lockScope.'</D:lockscope>'.
            '  <D:locktype><D:write/></D:locktype>'.
            '  <D:owner>'.$ownerInfo.'</D:owner>'.
            '</D:lockinfo>');

        $dom = $request->executeDom();

        return $this->generateLockFromDavResponse($dom, true, $absPath);
    }

    public function isLocked(string $absPath): bool
    {
        $request = $this->getRequest(Request::PROPFIND, $absPath);
        $request->setBody($this->buildPropfindRequest(['D:lockdiscovery']));
        $request->setDepth(0);
        $dom = $request->executeDom();

        $lockInfo = $this->getRequiredDomElementByTagNameNS($dom, self::NS_DAV, 'lockdiscovery');

        return $lockInfo->childNodes->length > 0;
    }

    public function unlock(string $absPath, string $lockToken): void
    {
        $request = $this->getRequest(Request::UNLOCK, $absPath);
        $request->setLockToken($lockToken);
        $request->execute();
    }

    public function getEvents(int $date, EventFilterInterface $filter, SessionInterface $session): \Iterator
    {
        return $this->factory->get(EventBuffer::class, [
            $filter,
            $this,
            $this->nodeTypeManager,
            str_replace('jcr:root', 'jcr%3aroot', $this->workspaceUriRoot),
            $this->fetchEventData($date),
        ]);
    }

    /**
     * @return array{data: \DOMDocument, nextMillis: false|string}
     */
    public function fetchEventData(int $date): array
    {
        $path = $this->workspaceUri.self::JCR_JOURNAL_PATH;
        $request = $this->getRequest(Request::GET, $path, false);
        $request->addHeader(sprintf('If-None-Match: "%s"', base_convert($date, 10, 16)));
        $curl = $request->execute(true);
        // create new DOMDocument and load the response text.
        $dom = new \DOMDocument();
        $dom->loadXML($curl->getResponse());

        $next = base_convert(trim($curl->getHeader('ETag'), '"'), 16, 10);

        if ($next === $date) {
            // no more events
            $next = false;
        }

        return [
            'data' => $dom,
            'nextMillis' => $next,
        ];
    }

    public function setUserData(?string $userData): void
    {
        $this->userData = $userData;
    }

    public function getUserData(): ?string
    {
        return $this->userData;
    }

    public function createWorkspace(string $name, string $srcWorkspace = null): void
    {
        if (null !== $srcWorkspace) {
            // https://issues.apache.org/jira/browse/JCR-3144
            throw new UnsupportedRepositoryOperationException('Can not create a workspace from a source workspace as we neither implemented clone nor have native support for this');
        }

        $curl = $this->getCurl();
        $uri = $this->server.$name;

        $request = $this->factory->get('Transport\\Jackrabbit\\Request', [$this, $curl, Request::MKWORKSPACE, $uri]);
        $request->setCredentials($this->credentials);
        foreach ($this->defaultHeaders as $header) {
            $request->addHeader($header);
        }

        if (!$this->sendExpect) {
            $request->addHeader('Expect:');
        }

        $request->execute();
    }

    public function deleteWorkspace(string $name): void
    {
        // https://issues.apache.org/jira/browse/JCR-3144
        throw new UnsupportedRepositoryOperationException("Can not delete a workspace as jackrabbit can not do it. Find the jackrabbit folder and look for workspaces/$name and delete that folder");
    }

    // helper methods //

    /**
     * Build the xml required to register node types.
     *
     * @param string $cnd the node type definition
     *
     * @return string XML with register request
     */
    private function buildRegisterNodeTypeRequest(string $cnd, bool $allowUpdate): string
    {
        $cnd = '<dcr:cnd>'.str_replace(['<', '>'], ['&lt;', '&gt;'], $cnd).'</dcr:cnd>';
        $cnd .= '<dcr:allowupdate>'.($allowUpdate ? 'true' : 'false').'</dcr:allowupdate>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="no"?><D:propertyupdate xmlns:D="DAV:"><D:set><D:prop><dcr:nodetypes-cnd xmlns:dcr="http://www.day.com/jcr/webdav/1.0">'.$cnd.'</dcr:nodetypes-cnd></D:prop></D:set></D:propertyupdate>';
    }

    /**
     * Build the xml to update the namespaces.
     *
     * You need to repeat all existing node type plus add your new ones
     *
     * @param array<string, string> $mappings hashmap of prefix => uri for all existing and new namespaces
     */
    private function buildRegisterNamespaceRequest(array $mappings): string
    {
        $ns = '';
        foreach ($mappings as $prefix => $uri) {
            $ns .= "<dcr:namespace><dcr:prefix>$prefix</dcr:prefix><dcr:uri>$uri</dcr:uri></dcr:namespace>";
        }

        return '<?xml version="1.0" encoding="UTF-8"?><D:propertyupdate xmlns:D="DAV:"><D:set><D:prop><dcr:namespaces xmlns:dcr="http://www.day.com/jcr/webdav/1.0">'.
                $ns.
                '</dcr:namespaces></D:prop></D:set></D:propertyupdate>';
    }

    /**
     * Returns the XML required to request nodetypes.
     *
     * @param array $nodeTypes the list of nodetypes you want to request for
     *
     * @return string XML with the request information
     */
    protected function buildNodeTypesRequest(array $nodeTypes): string
    {
        $xml = '<?xml version="1.0" encoding="utf-8" ?>'.
            '<jcr:nodetypes xmlns:jcr="http://www.day.com/jcr/webdav/1.0">';
        if (empty($nodeTypes)) {
            $xml .= '<jcr:all-nodetypes/>';
        } else {
            foreach ($nodeTypes as $nodetype) {
                $xml .= '<jcr:nodetype><jcr:nodetypename>'.$nodetype.'</jcr:nodetypename></jcr:nodetype>';
            }
        }
        $xml .= '</jcr:nodetypes>';

        return $xml;
    }

    /**
     * Build PROPFIND request XML for the specified property names.
     *
     * @param string[] $properties names of the properties to search for
     *
     * @return string XML to post in the body
     */
    protected function buildPropfindRequest(array $properties): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'.
            '<D:propfind xmlns:D="DAV:" xmlns:dcr="http://www.day.com/jcr/webdav/1.0"><D:prop>';
        if (!is_array($properties)) {
            $properties = [$properties];
        }
        foreach ($properties as $property) {
            $xml .= '<'.$property.'/>';
        }
        $xml .= '</D:prop></D:propfind>';

        return $xml;
    }

    /**
     * Build a REPORT XML request string.
     *
     * @param string $name name of the resource to be requested
     *
     * @return string XML string representing the head of the request
     */
    protected function buildReportRequest(string $name): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><'.
                $name.
               ' xmlns:dcr="http://www.day.com/jcr/webdav/1.0"/>';
    }

    /**
     * Build REPORT XML request for locating a node path by uuid.
     *
     * @param string $uuid unique identifier of the node to be asked for
     *
     * @return string XML sring representing the content of the request
     */
    protected function buildLocateRequest(string $uuid): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'.
               '<dcr:locate-by-uuid xmlns:dcr="http://www.day.com/jcr/webdav/1.0">'.
               '<D:href xmlns:D="DAV:">'.
                $uuid.
               '</D:href></dcr:locate-by-uuid>';
    }

    public function setNodeTypeManager(NodeTypeManagerInterface $nodeTypeManager): void
    {
        $this->nodeTypeManager = $nodeTypeManager;
    }

    /**
     * Checks if the path is absolute and valid, and properly urlencodes special characters.
     *
     * This is to be used in the Davex headers. The XML requests can cope with unencoded stuff
     *
     * @param string $path to check
     *
     * @return string the cleaned path
     *
     * @throws RepositoryException If path is not absolute or invalid
     */
    private function encodeAndValidatePathForDavex(string $path): string
    {
        PathHelper::assertValidAbsolutePath($path);

        $path = rawurlencode($path);

        // we encoded the whole path, need to rebuild slashes and parenthesis
        // this will not collide with %2F as % was encoded by rawurlencode
        return str_replace(['%2F', '%5B', '%5D'], ['/', '[', ']'], $path);
    }

    /**
     * remove the server and workspace part from an uri, leaving the absolute
     * path inside the current workspace.
     *
     * @param string $uri a full uri including the server path, workspace and jcr%3aroot
     *
     * @return string absolute path in the current work space
     */
    private function stripServerRootFromUri(string $uri): string
    {
        return substr($uri, strlen($this->workspaceUriRoot));
    }

    /**
     * Prepends the workspace root to the uris that contain an absolute path.
     *
     * @param string $uri The absolute path in the current workspace or server uri
     *
     * @return string The server uri with this path
     *
     * @throws RepositoryException If workspaceUri is missing (not logged in)
     */
    protected function addWorkspacePathToUri(string $uri): string
    {
        if (empty($uri) || '/' === $uri[0]) {
            if (empty($this->workspaceUri)) {
                throw new RepositoryException('Implementation error: Please login before accessing content');
            }
            $uri = $this->workspaceUriRoot.$uri;
        }

        return $uri;
    }

    /**
     * Extract the information from a LOCK DAV response and create the
     * corresponding Lock object.
     *
     * @param \DOMElement|\DOMDocument $response
     * @param bool                     $sessionOwning whether the current session is owning the lock (aka
     *                                                we created it in this request)
     * @param string|null              $path          the owning node path, if we created this node
     */
    private function generateLockFromDavResponse($response, bool $sessionOwning = false, string $path = null): LockInterface
    {
        $lock = new Lock();
        $lockDom = $this->getRequiredDomElementByTagNameNS($response, self::NS_DAV, 'activelock', 'No lock received');

        // Check this is not a transaction lock
        $type = $this->getRequiredDomElementByTagNameNS($lockDom, self::NS_DAV, 'locktype', 'No lock type received');
        if (!$type->childNodes->length) {
            $tagName = $type->childNodes->item(0)->localName;
            if ('write' !== $tagName) {
                throw new RepositoryException("Invalid lock type '$tagName'");
            }
        }

        // Extract the lock scope
        $scopeDom = $this->getRequiredDomElementByTagNameNS($lockDom, self::NS_DAV, 'lockscope', 'No lock scope in the received lock');
        if ($this->getRequiredDomElementByTagNameNS($scopeDom, self::NS_DCR, 'exclusive-session-scoped')) {
            $lock->setIsSessionScoped(true);
        } elseif ($this->getRequiredDomElementByTagNameNS($scopeDom, self::NS_DAV, 'exclusive')) {
            $lock->setIsSessionScoped(false);
        } else {
            // Unknown XML found in the <D:lockscope> tag
            throw new RepositoryException('Invalid lock scope received: '.$response->saveHTML($scopeDom));
        }

        // Extract the depth
        $depthDom = $this->getRequiredDomElementByTagNameNS($lockDom, self::NS_DAV, 'depth', 'No depth in the received lock');
        $lock->setIsDeep('infinity' === $depthDom->textContent);

        // Extract the owner
        $ownerDom = $this->getRequiredDomElementByTagNameNS($lockDom, self::NS_DAV, 'owner', 'No owner in the received lock');
        $lock->setLockOwner($ownerDom->textContent);

        // Extract the lock token
        $tokenDom = $this->getRequiredDomElementByTagNameNS($lockDom, self::NS_DAV, 'href', 'No lock token in the received lock');
        $lock->setLockToken($tokenDom->textContent);

        // Extract the timeout
        $timeoutDom = $this->getRequiredDomElementByTagNameNS($lockDom, self::NS_DAV, 'timeout', 'No lock timeout in the received lock');
        $lock->setExpireTime($this->parseTimeout($timeoutDom->nodeValue));

        $lock->setIsLockOwningSession($sessionOwning);
        if (null !== $path) {
            $lock->setNodePath($path);
        } else {
            throw new NotImplementedException('get the lock owning node or provide Lock with info so it can get it when requested');
            // TODO: get the lock owning node
            // Note that $n->getLock()->getNode() (where $n is a locked node) will only
            // return $n if $n is the lock holder. If $n is in the subgraph of the lock
            // holder, $h, then this call will return $h.
        }

        return $lock;
    }

    /**
     * Retrieve a child DOM element from a DOM element.
     * If the element is not found and $errorMessage is set, then a RepositoryException is thrown.
     * If the element is not found and $errorMessage is empty, then false is returned.
     *
     * @param \DOMNode $dom          The DOM element which content should be searched
     * @param string   $namespace    The namespace of the searched element
     * @param string   $element      The name of the searched element
     * @param string   $errorMessage The error message in case the element is not found
     *
     * @return bool|\DOMNode
     *
     * @throws RepositoryException When the element is not found and an $errorMessage is set
     */
    private function getRequiredDomElementByTagNameNS($dom, string $namespace, string $element, string $errorMessage = '')
    {
        $list = $dom->getElementsByTagNameNS($namespace, $element);

        if (!$list->length) {
            if ($errorMessage) {
                throw new RepositoryException($errorMessage);
            }

            return false;
        }

        return $list->item(0);
    }

    /**
     * Parse the timeout value from a WebDAV response and calculate the expire
     * timestamp.
     *
     * The timeout value follows the syntax defined in RFC2518: Timeout Header.
     * Here we just parse the values in the form "Second-XXXX" or "Infinite".
     * Any other value will produce an error.
     *
     * The function returns the unix epoch timestamp for the second when this
     * lock will expire in case of normal timeout, or PHP_INT_MAX in case of an
     * "Infinite" timeout.
     *
     * @param string $timeoutValue The timeout in seconds
     *
     * @return int the expire timestamp to be used with Lock::setExpireTime,
     *             that is when this lock expires in seconds since 1970 or null for inifinite
     *
     * @throws \InvalidArgumentException if the timeout value can not be parsed
     */
    private function parseTimeout(string $timeoutValue): ?int
    {
        if (self::JCR_INFINITE === $timeoutValue) {
            return null;
        }

        if (!preg_match('/Second\-([\d]+)/', $timeoutValue, $matches)) {
            throw new RepositoryException("Unexpected response on lock from the backend, could not parse seconds out of '$timeoutValue'");
        }
        $time = $matches[1];

        // keep this hack for jackrabbit 2.3.7 for now. it reported a bogous value for the timeout
        if (self::JCR_INFINITE_LOCK_TIMEOUT === $time || self::JCR_INFINITE_LOCK_TIMEOUT - 1 === $time) {
            // prevent glitches due to second boundary during request
            return null;
        }

        return time() + $time;
    }

    private function setJsopBody($value, $key = ':diff', $type = null): void
    {
        if ($type) {
            $this->jsopBody[$key] = [$value, $type];
        } else {
            if (!isset($this->jsopBody[$key])) {
                $this->jsopBody[$key] = '';
            } else {
                $this->jsopBody[$key] .= "\r";
            }
            $this->jsopBody[$key] .= $value;
        }
    }

    public function prepareSave(): void
    {
    }

    public function finishSave(): void
    {
        if (count($this->jsopBody) > 0) {
            $request = $this->getRequest(Request::POST, '/');
            $body = '';

            if (count($this->jsopBody) > 1 || !isset($this->jsopBody[':diff'])) {
                $mime_boundary = md5(mt_rand());
                // do the diffs at last
                $diff = null;
                if (isset($this->jsopBody[':diff'])) {
                    $diff = $this->jsopBody[':diff'];
                    unset($this->jsopBody[':diff']);
                }

                foreach ($this->jsopBody as $n => $v) {
                    $body .= $this->getMimePart($n, $v, $mime_boundary);
                }

                if ($diff) {
                    $body .= $this->getMimePart(':diff', $diff, $mime_boundary);
                }
                $body .= '--'.$mime_boundary.'--'."\r\n\r\n"; // finish with two eol's!!

                $request->setContentType("multipart/form-data; boundary=$mime_boundary");
            } else {
                $body = urlencode(':diff').'='.urlencode($this->jsopBody[':diff']);
                $request->setContentType('application/x-www-form-urlencoded; charset=utf-8');
            }

            try {
                $request->setBody($body);
                $request->execute();
            } catch (HTTPErrorException $e) {
                // TODO: can we throw any other more specific errors here?
                throw new RepositoryException('Something went wrong while saving nodes', $e->getCode(), $e);
            }
        }

        $this->jsopBody = [];
    }

    public function rollbackSave(): void
    {
    }

    private function getMimePart(string $name, $value, string $mime_boundary): string
    {
        $data = '';

        $eol = "\r\n";
        if (is_array($value)) {
            if (is_array($value[0])) {
                foreach ($value[0] as $v) {
                    $data .= $this->getMimePart($name, [$v, $value[1]], $mime_boundary);
                }

                return $data;
            }
            $data .= '--'.$mime_boundary.$eol;

            if (is_resource($value[0])) {
                $data .= 'Content-Disposition: form-data; name="'.$name.'"; filename="'.$name.'"'.$eol;
                $data .= 'Content-Type: jcr-value/'.strtolower(PropertyType::nameFromValue($value[1])).'; charset=UTF-8'.$eol;
                $data .= 'Content-Transfer-Encoding: binary'.$eol.$eol;
                $data .= stream_get_contents($value[0]).$eol;
                fclose($value[0]);
            } else {
                $data .= 'Content-Disposition: form-data; name="'.$name.'"'.$eol;
                $data .= 'Content-Type: jcr-value/'.strtolower(PropertyType::nameFromValue($value[1])).'; charset=UTF-8'.$eol;
                $data .= 'Content-Transfer-Encoding: 8bit'.$eol.$eol;
                switch ($value[1]) {
                    case PropertyType::DATE:
                        $data .= $this->valueConverter->convertType($value[0], PropertyType::STRING);
                        break;
                    default:
                        $data .= $value[0];
                }
                $data .= $eol;
            }
        } else {
            if (is_array($value)) {
                foreach ($value as $v) {
                    $data .= $this->getMimePart($name, $v, $mime_boundary);
                }

                return $data;
            }
            $data .= '--'.$mime_boundary.$eol;
            $data .= 'Content-Disposition: form-data; name="'.$name.'"'.$eol;
            $data .= 'Content-Type: text/plain; charset=UTF-8'.$eol;
            $data .= 'Content-Transfer-Encoding: 8bit'.$eol.$eol;
            // $data .= '--' . $mime_boundary . $eol;
            $data .= $value.$eol;
        }

        return $data;
    }

    public function setVersion(?string $version): void
    {
        $this->version = $version;
    }
}
