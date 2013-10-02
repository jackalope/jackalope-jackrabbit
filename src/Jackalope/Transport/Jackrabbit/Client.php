<?php

namespace Jackalope\Transport\Jackrabbit;

use DOMDocument;
use DOMElement;
use LogicException;
use InvalidArgumentException;

use PHPCR\CredentialsInterface;
use PHPCR\ItemExistsException;
use PHPCR\Query\InvalidQueryException;
use PHPCR\RepositoryInterface;
use PHPCR\SimpleCredentials;
use PHPCR\PropertyType;
use PHPCR\SessionInterface;
use PHPCR\RepositoryException;
use PHPCR\UnsupportedRepositoryOperationException;
use PHPCR\ItemNotFoundException;
use PHPCR\PathNotFoundException;
use PHPCR\LoginException;
use PHPCR\Query\QueryInterface;
use PHPCR\Observation\EventFilterInterface;

use PHPCR\Util\PathHelper;

use Jackalope\Transport\BaseTransport;
use Jackalope\Transport\QueryInterface as QueryTransport;
use Jackalope\Transport\PermissionInterface;
use Jackalope\Transport\WritingInterface;
use Jackalope\Transport\VersioningInterface;
use Jackalope\Transport\NodeTypeCndManagementInterface;
use Jackalope\Transport\LockingInterface;
use Jackalope\Transport\ObservationInterface;
use Jackalope\Transport\WorkspaceManagementInterface;
use Jackalope\NotImplementedException;
use Jackalope\Node;
use Jackalope\Property;
use Jackalope\Query\Query;
use Jackalope\NodeType\NodeTypeManager;
use Jackalope\Lock\Lock;
use Jackalope\FactoryInterface;
use PHPCR\Util\ValueConverter;
use PHPCR\ValueFormatException;

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
 * *
 * @author Christian Stocker <chregu@liip.ch>
 * @author David Buchmann <david@liip.ch>
 * @author Tobias Ebnöther <ebi@liip.ch>
 * @author Roland Schilter <roland.schilter@liip.ch>
 * @author Uwe Jäger <uwej711@googlemail.com>
 * @author Lukas Kahwe Smith <smith@pooteeweet.org>
 * @author Daniel Barsotti <daniel.barsotti@liip.ch>
 */
class Client extends BaseTransport implements QueryTransport, PermissionInterface, WritingInterface, VersioningInterface, NodeTypeCndManagementInterface, LockingInterface, ObservationInterface, WorkspaceManagementInterface
{
    /**
     * minimal version needed for the backend server
     */
    const VERSION = "2.3.6";

    /**
     * Description of the namspace to be used for communication with the server.
     * @var string
     */
    const NS_DCR = 'http://www.day.com/jcr/webdav/1.0';

    /**
     * Identifier of the used namespace.
     * @var string
     */
    const NS_DAV = 'DAV:';

    /**
     * Value of the <timeout> tag for infinite timeout (since jackrabbit 2.4)
     */
    const JCR_INFINITE = 'Infinite';

    /**
     * Jackrabbit 2.3.6+2.3.7 return this weird number to say its an infinite lock
     * This has been fixed in 2.4
     */
    const JCR_INFINITE_LOCK_TIMEOUT = 2147483;

    /**
     * The path to request to get the Jackrabbit event journal
     */
    const JCR_JOURNAL_PATH = '?type=journal';

    /**
     * The factory to instantiate objects
     * @var FactoryInterface
     */
    protected $factory;

    /** @var ValueConverter */
    protected $valueConverter;

    /**
     * Server url including protocol.
     *
     * i.e http://localhost:8080/server/
     * constructor ensures the trailing slash /
     *
     * @var string
     */
    protected $server;

    /**
     * Workspace name the transport is bound to
     *
     * Set once login() has been executed and may not be changed later on.
     *
     * @var string
     */
    protected $workspace;

    /**
     * Identifier of the workspace including the used protocol and server name.
     *
     * "$server/$workspace" without trailing slash
     *
     *  @var string
     */
    protected $workspaceUri;

    /**
     * Root node path with server domain without trailing slash.
     *
     * "$server/$workspace/jcr%3aroot
     * (make sure you never hardcode the jcr%3aroot, its ugly)
     * @todo apparently, jackrabbit handles the root node by name - it is invisible everywhere for the api,
     *       but needed when talking to the backend... could that name change?
     *
     * @var string
     */
    protected $workspaceUriRoot;

    /**
     * Set of credentials necessary to connect to the server.
     *
     * Set once login() has been executed and may not be changed later on.
     *
     * @var SimpleCredentials
     */
    protected $credentials;

    /**
     * The cURL resource handle
     * @var curl
     */
    protected $curl = null;

    /**
     *  A list of additional HTTP headers to be sent on each request
     *  @var array[]string
     */

    protected $defaultHeaders = array();

    /**
     *  @var bool Send Expect: 100-continue header
     */

    protected $sendExpect = false;

    /**
     * @var \Jackalope\NodeType\NodeTypeXmlConverter
     */
    protected $typeXmlConverter;

    /**
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * Check if an initial PROPFIND should be send to check if repository exists
     * This is according to the JCR specifications and set to true by default
     * @see setCheckLoginOnServer
     * @var bool
     */
    protected $checkLoginOnServer = true;

    /**
     * Cached result of the repository descriptors.
     *
     * This is our exception to the rule that nothing may be cached in transport.
     *
     * @var array of strings as returned by getRepositoryDescriptors
     */
    protected $descriptors = null;

    protected $jsopBody = array();

    protected $userData;

    /**
     * Create a transport pointing to a server url.
     *
     * @param FactoryInterface $factory   the object factory
     * @param string           $serverUri location of the server
     */
    public function __construct(FactoryInterface $factory, $serverUri)
    {
        $this->factory = $factory;
        $this->valueConverter = $this->factory->get('PHPCR\Util\ValueConverter');

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
        $this->defaultHeaders[] = $header;
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
        $this->sendExpect = $send;
    }

    /**
     * Makes sure there is an open curl connection.
     *
     * @return Request The Request
     */
    protected function getRequest($method, $uri, $addWorkspacePathToUri = true)
    {
        if (!is_array($uri)) {
            $uri = array($uri => $uri);
        }

        $curl = $this->getCurl();

        if ($addWorkspacePathToUri) {
            foreach ($uri as $key => $row) {
                $uri[$key] = $this->addWorkspacePathToUri($row);
            }
        }

        $request = $this->factory->get('Transport\\Jackrabbit\\Request', array($this, $curl, $method, $uri));

        $request->setCredentials($this->credentials);
        if (null !== $this->userData) {
            $request->addUserData($this->userData);
        }
        foreach ($this->defaultHeaders as $header) {
            $request->addHeader($header);
        }

        if (!$this->sendExpect) {
            $request->addHeader("Expect:");
        }

        return $request;
    }

    protected function getCurl()
    {
        if (is_null($this->curl)) {
            // lazy init curl
            $this->curl = new curl();
        } elseif ($this->curl === false) {
            // but do not re-connect, rather report the error if trying to access a closed connection
            throw new LogicException('Tried to start a request on a closed transport.');
        }

        return $this->curl;
    }

    // CoreInterface //

    /**
     * {@inheritDoc}
     */
    public function login(CredentialsInterface $credentials = null, $workspaceName = null)
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
                : 'Only SimpleCredentials are supported. Unkown credentials type: '.get_class($credentials);
            throw new LoginException($hint);
        }

        $this->credentials = $credentials;

        if (! $workspaceName) {
            $request = $this->getRequest(Request::PROPFIND, $this->server);
            $request->setBody($this->buildPropfindRequest(array('dcr:workspaceName')));
            $dom = $request->executeDom();
            $answer = $dom->getElementsByTagNameNS(self::NS_DCR, 'workspaceName');
            $workspaceName = $answer->item(0)->textContent;
        }

        $this->workspace = $workspaceName;
        $this->workspaceUri = $this->server . $workspaceName;
        $this->workspaceUriRoot = $this->workspaceUri . "/jcr:root";

        if (!$this->checkLoginOnServer) {
            return $workspaceName;
        }

        $request = $this->getRequest(Request::PROPFIND, $this->workspaceUri);
        $request->setBody($this->buildPropfindRequest(array('D:workspace', 'dcr:workspaceName')));
        $dom = $request->executeDom();

        $set = $dom->getElementsByTagNameNS(self::NS_DCR, 'workspaceName');
        if ($set->length != 1) {
            throw new RepositoryException('Unexpected answer from server: '.$dom->saveXML());
        }

        if ($set->item(0)->textContent != $this->workspace) {
            throw new RepositoryException('Wrong workspace in answer from server: '.$dom->saveXML());
        }

        return $workspaceName;
    }

    /**
     * {@inheritDoc}
     */
    public function logout()
    {
        if (!empty($this->curl)) {
            $this->curl->close();
        }
        $this->curl = false;
    }

    /**
     * Configure whether to check if we are logged in before doing a request.
     *
     * Will improve error reporting at the cost of some round trips.
     */
    public function setCheckLoginOnServer($bool)
    {
        $this->checkLoginOnServer = $bool;
    }

    /**
     * {@inheritDoc}
     */
    public function getRepositoryDescriptors()
    {
        if (null == $this->descriptors) {
            $request = $this->getRequest(Request::REPORT, $this->server);
            $request->setBody($this->buildReportRequest('dcr:repositorydescriptors'));
            $dom = $request->executeDom();

            if ($dom->firstChild->localName != 'repositorydescriptors-report'
                || $dom->firstChild->namespaceURI != self::NS_DCR
            ) {
                throw new RepositoryException('Error talking to the backend. '.$dom->saveXML());
            }

            $descs = $dom->getElementsByTagNameNS(self::NS_DCR, 'descriptor');
            $this->descriptors = array();
            foreach ($descs as $desc) {
                $name = $desc->getElementsByTagNameNS(self::NS_DCR, 'descriptorkey')->item(0)->textContent;

                $values = array();
                $valuenodes = $desc->getElementsByTagNameNS(self::NS_DCR, 'descriptorvalue');
                foreach ($valuenodes as $value) {
                    $values[] = $value->textContent;
                }
                if ($valuenodes->length == 1) {
                    //there was one type and one value => this is a single value property
                    //TODO: is this the correct assumption? or should the backend tell us specifically?
                    $this->descriptors[$name] = $values[0];
                } else {
                    $this->descriptors[$name] = $values;
                }
            }

            // Supported by Jackrabbit, but not supported by this client
            $this->descriptors[RepositoryInterface::NODE_TYPE_MANAGEMENT_SAME_NAME_SIBLINGS_SUPPORTED] = false;
            $this->descriptors[RepositoryInterface::QUERY_CANCEL_SUPPORTED] = false;

            if (! isset($this->descriptors['jcr.repository.version'])) {
                throw new UnsupportedRepositoryOperationException("The backend at {$this->server} does not provide the jcr.repository.version descriptor");
            }

            if (! version_compare(self::VERSION, $this->descriptors['jcr.repository.version'], '<=')) {
                throw new UnsupportedRepositoryOperationException("The backend at {$this->server} is an unsupported version of jackrabbit: \"".
                    $this->descriptors['jcr.repository.version'].
                    '". Need at least "'.self::VERSION.'"');
            }
        }

        return $this->descriptors;
    }

    /**
     * {@inheritDoc}
     */
    public function getAccessibleWorkspaceNames()
    {
        $request = $this->getRequest(Request::PROPFIND, $this->server);
        $request->setBody($this->buildPropfindRequest(array('D:workspace')));
        $request->setDepth(1);
        $dom = $request->executeDom();

        $workspaces = array();
        foreach ($dom->getElementsByTagNameNS(self::NS_DAV, 'workspace') as $value) {
            if (!empty($value->nodeValue)) {
                $workspaces[] = substr(trim($value->nodeValue), strlen($this->server), -1);
            }
        }

        return array_unique($workspaces);
    }

    /**
     * {@inheritDoc}
     */
    public function getNode($path)
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

    /**
     * {@inheritDoc}
     */
    public function getNodes($paths, $query = ':include')
    {
        if (count($paths) == 0) {
            return array();
        }

        if (count($paths) == 1) {
            $url = array_shift($paths);
            try {
                return array($url => $this->getNode($url));
            } catch (ItemNotFoundException $e) {
                return array();
            }
        }
        $body = array();

        $url = $this->encodeAndValidatePathForDavex("/").'.'.$this->getFetchDepth().'.json';
        foreach ($paths as $path) {
            $body[] = http_build_query(array($query => $path));
        }
        $body = implode("&",$body);

        $request = $this->getRequest(Request::POST, $url);
        $request->setBody($body);
        $request->setContentType('application/x-www-form-urlencoded');

        try {
            $data = $request->executeJson();

            return $data->nodes;
        } catch (PathNotFoundException $e) {
            throw new ItemNotFoundException($e->getMessage(), $e->getCode(), $e);
        } catch (RepositoryException $e) {
            if ($e->getMessage() == 'HTTP 403: Prefix must not be empty (org.apache.jackrabbit.spi.commons.conversion.IllegalNameException)') {
                throw new UnsupportedRepositoryOperationException("Jackalope currently needs a patched jackrabbit for Session->getNodes() to work. Until our patches make it into the official distribution, see https://github.com/jackalope/jackrabbit/blob/2.2-jackalope/README.jackalope.patches.md for details and downloads.");
            }

            throw $e;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getNodesByIdentifier($identifiers)
    {
        // OPTIMIZE get paths for UUID's via a single query
        // or get the data directly
        // return $this->getNodes($identifiers, ':id');

        $paths = array();
        foreach ($identifiers as $key => $identifier) {
            try {
                $paths[$key] = $this->getNodePathForIdentifier($identifier);
            } catch (ItemNotFoundException $e) {
                // ignore
            }
        }

        return $this->getNodes($paths);
    }

    /**
     * {@inheritDoc}
     */
    public function getNodeByIdentifier($uuid)
    {
        // OPTIMIZE get nodes directly by uuid from backend. needs implementation on jackrabbit
        $path = $this->getNodePathForIdentifier($uuid);
        $data = $this->getNode($path);
        $data->{':jcr:path'} = $path;

        return $data;
    }

    /**
     * {@inheritDoc}
     */
    public function getNodePathForIdentifier($uuid, $workspace = null)
    {
        if (null !== $workspace && $workspace != $this->workspace) {
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
        if ($set->length != 1) {
            throw new RepositoryException('Unexpected answer from server: '.$dom->saveXML());
        }
        $fullPath = $set->item(0)->textContent;
        if (strncmp($this->workspaceUriRoot, $fullPath, strlen($this->workspaceUri))) {
            throw new RepositoryException(
                "Server answered a path that is not in the current workspace: uuid=$uuid, path=$fullPath, workspace=".
                    $this->workspaceUriRoot
            );
        }

        return $this->stripServerRootFromUri(substr(urldecode($fullPath),0,-1));
    }

    /**
     * {@inheritDoc}
     */
    public function getProperty($path)
    {
        throw new NotImplementedException();
        /*
         * TODO: implement
         * jackrabbit: instead of fetching the node, we could make Transport provide it with a
         * GET /server/tests/jcr%3aroot/tests_level1_access_base/multiValueProperty/jcr%3auuid
         * (davex getItem uses json, which is not applicable to properties)
         */
    }

    /**
     * {@inheritDoc}
     */
    public function getBinaryStream($path)
    {
        $path = $this->encodeAndValidatePathForDavex($path);
        $request = $this->getRequest(Request::GET, $path);
        $curl = $request->execute(true);
        switch ($curl->getHeader('Content-Type')) {
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
     * parse the multivalue binary response (a list of base64 encoded values)
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
    private function decodeBinaryDom($xml)
    {
        $dom = new DOMDocument();
        if (! $dom->loadXML($xml)) {
            throw new RepositoryException("Failed to load xml data:\n\n$xml");
        }

        $ret = array();
        foreach ($dom->getElementsByTagNameNS(self::NS_DCR, 'values') as $node) {
            foreach ($node->getElementsByTagNameNS(self::NS_DCR, 'value') as $value) {
                if ($value->getAttributeNS(self::NS_DCR, 'type') != PropertyType::TYPENAME_BINARY) {
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

    /**
     * {@inheritDoc}
     */
    public function getReferences($path, $name = null)
    {
        return $this->getNodeReferences($path, $name);
    }

    /**
     * {@inheritDoc}
     */
    public function getWeakReferences($path, $name = null)
    {
        return $this->getNodeReferences($path, $name, true);
    }

    /**
     * @param string $path           the path for which we need the references
     * @param string $name           the name of the referencing properties or null for all
     * @param bool   $weak_reference whether to get weak or strong references
     *
     * @return array list of paths to nodes that reference $path
     */
    protected function getNodeReferences($path, $name = null, $weak_reference = false)
    {
        $path = $this->encodeAndValidatePathForDavex($path);
        $identifier = $weak_reference ? 'weakreferences' : 'references';
        $request = $this->getRequest(Request::PROPFIND, $path);
        $request->setBody($this->buildPropfindRequest(array('dcr:'.$identifier)));
        $request->setDepth(0);
        $dom = $request->executeDom();

        $references = array();

        foreach ($dom->getElementsByTagNameNS(self::NS_DCR, $identifier) as $node) {
            foreach ($node->getElementsByTagNameNS(self::NS_DAV, 'href') as $ref) {
                $refpath = str_replace($this->workspaceUriRoot, '',  urldecode($ref->textContent));
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
     * jackrabbit is sloppy
     *
     * @param string $path a path with potentially a trailing slash
     *
     * @return string the path guaranteed to not have a trailing slash
     */
    private function removeTrailingSlash($path)
    {
        if (strlen($path) <= 1) {
            return '/';
        }

        if ('/' !== $path[strlen($path) - 1]) {
            // no trailing slash
            return $path;
        }

        return substr($path, 0, strlen($path) - 1);
    }

    // VersioningInterface //

    /**
     * {@inheritDoc}
     */
    public function checkinItem($path)
    {
        $path = $this->encodeAndValidatePathForDavex($path);
        try {
            $request = $this->getRequest(Request::CHECKIN, $path);
            $curl = $request->execute(true);
            if ($curl->getHeader("Location")) {
                return $this->removeTrailingSlash(
                    $this->stripServerRootFromUri(urldecode($curl->getHeader("Location")))
                );
            }
        } catch (HTTPErrorException $e) {
            if ($e->getCode() == 405) {
                throw new UnsupportedRepositoryOperationException();
            }
            throw new RepositoryException($e->getMessage());
        }

        throw new RepositoryException();
    }

    /**
     * {@inheritDoc}
     */
    public function checkoutItem($path)
    {
        $path = $this->encodeAndValidatePathForDavex($path);
        try {
            $request = $this->getRequest(Request::CHECKOUT, $path);
            $request->execute();
        } catch (HTTPErrorException $e) {
            if ($e->getCode() == 405) {
                // TODO: when checking out a non-versionable node, we get here too. in that case the exception is very wrong
                throw new UnsupportedRepositoryOperationException($e->getMessage());
            }
            throw new RepositoryException($e->getMessage());
        }

        return;
    }

    /**
     * {@inheritDoc}
     */
    public function restoreItem($removeExisting, $versionPath, $path)
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

    /**
     * {@inheritDoc}
     */
    public function removeVersion($versionPath, $versionName)
    {
        $path = $this->encodeAndValidatePathForDavex($versionPath . '/' . $versionName);
        $request = $this->getRequest(Request::DELETE, $path);
        $resp = $request->execute();

        return $resp;
    }

    // QueryTransport //

    /**
     * {@inheritDoc}
     */
    public function query(Query $query)
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
        $body ='<D:searchrequest ' . $ns . ' xmlns:D="DAV:"><'.$langElement.'><![CDATA['.$querystring.']]></'.$langElement.'>';

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

        $dom = new DOMDocument();
        $dom->loadXML($rawData);

        $rows = array();
        foreach ($dom->getElementsByTagName('response') as $row) {
            $columns = array();
            foreach ($row->getElementsByTagName('column') as $column) {
                $sets = array();
                foreach ($column->childNodes as $childNode) {
                    if ('dcr:value' == $childNode->tagName) {
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

                if (! isset($sets['dcr:value'])) {
                    $sets['dcr:value'] = null;
                }

                $columns[] = $sets;
            }

            $rows[] = $columns;
        }

        return $rows;
    }

    /**
     * {@inheritDoc}
     */
    public function getSupportedQueryLanguages()
    {
        return array(
            QueryInterface::JCR_SQL2,
            QueryInterface::JCR_JQOM,
            QueryInterface::XPATH,
            QueryInterface::SQL,
        );
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
     * @param DOMElement $node      a dcr:value xml element
     * @param string     $attribute the attribute name
     *
     * @return mixed the node value converted to the specified type.
     */
    public function getDcrValue(DOMElement $node)
    {
        $type = $node->getAttribute('dcr:type');
        if (PropertyType::TYPENAME_BOOLEAN == $type && 'false' == $node->nodeValue) {
            return false;
        }

        return $this->valueConverter->convertType($node->nodeValue, PropertyType::valueFromName($type));
    }


    // WritingInterface //

    /**
     * {@inheritDoc}
     */
    public function deleteNodes(array $operations)
    {
        // Reverse sort the batch; work-around for problem with
        // deleting same-name siblings. Not guaranteed to work
        // across multiple calls to deleteNodes().
        usort($operations, function ($a, $b) {
            $aParts = array();
            $bParts = array();
            $regex = '/^(.+?)(?:\[(\d+)])?$/';

            preg_match($regex, $a->srcPath, $aParts);
            preg_match($regex, $b->srcPath, $bParts);

            $aPath = $aParts[1];
            $bPath = $bParts[1];
            if ($aPath != $bPath) {
                return strcmp($bPath, $aPath);
            } else {
                $aIndex = isset($aParts[2]) ? $aParts[2] : 1;
                $bIndex = isset($bParts[2]) ? $bParts[2] : 1;

                return $bIndex - $aIndex;
            }
        });

        foreach ($operations as $operation) {
            $this->deleteItem($operation->srcPath);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function deleteProperties(array $operations)
    {
        foreach ($operations as $operation) {
            $this->deleteItem($operation->srcPath);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function deleteNodeImmediately($path)
    {
        $this->prepareSave();
        $this->deleteItem($path);
        $this->finishSave();
    }

    /**
     * {@inheritDoc}
     */
    public function deletePropertyImmediately($path)
    {
        $this->prepareSave();
        $this->deleteItem($path);
        $this->finishSave();
    }

    /**
     * Record that we need to delete the item at $path
     *
     * @param string $path path to node or property
     */
    protected function deleteItem($path)
    {
        PathHelper::assertValidAbsolutePath($path);

        $this->setJsopBody("-" . $path . " : ");
    }

    /**
     * {@inheritDoc}
     */
    public function copyNode($srcAbsPath, $dstAbsPath, $srcWorkspace = null)
    {
        /**
         * No JSOP possible, is a workspace method
         */
        $srcAbsPath = $this->encodeAndValidatePathForDavex($srcAbsPath);
        $dstAbsPath = $this->encodeAndValidatePathForDavex($dstAbsPath);

        if ($srcWorkspace) {
            $srcAbsPath = $this->server . $srcAbsPath;
        }

        $request = $this->getRequest(Request::COPY, $srcAbsPath);
        $request->setDepth(Request::INFINITY);
        $request->addHeader('Destination: '.$this->addWorkspacePathToUri($dstAbsPath));
        $request->execute();
    }

    /**
     * {@inheritDoc}
     */
    public function moveNodes(array $operations)
    {
        foreach ($operations as $operation) {
            PathHelper::assertValidAbsolutePath($operation->srcPath);
            PathHelper::assertValidAbsolutePath($operation->dstPath);

            $this->setJsopBody(">" . $operation->srcPath . " : " . $operation->dstPath);
        }
    }
    /**
     * {@inheritDoc}
     */
    public function moveNodeImmediately($srcAbsPath, $dstAbsPath)
    {
        $request = $this->getRequest(Request::MOVE, $srcAbsPath);
        $request->setDepth(Request::INFINITY);
        $request->addHeader('Destination: ' . $this->addWorkspacePathToUri($dstAbsPath));
        $request->execute();
    }

    /**
     * {@inheritDoc}
     */
    public function reorderChildren(Node $node)
    {
        $reorders = $node->getOrderCommands();

        if (count($reorders) == 0) {
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

    /**
     * {@inheritDoc}
     */
    public function cloneFrom($srcWorkspace, $srcAbsPath, $destAbsPath, $removeExisting)
    {
        $srcAbsPath = $this->encodeAndValidatePathForDavex($srcAbsPath);
        $destAbsPath = $this->encodeAndValidatePathForDavex($destAbsPath);

        if ($removeExisting) {
            $this->checkForExistingNode($srcWorkspace, $srcAbsPath, $destAbsPath);
        }

        $body = urlencode(':clone') . '='
            . urlencode($srcWorkspace . ',' . $srcAbsPath . ',' . $destAbsPath . ',' . ($removeExisting ? 'true' : 'false'));

        $request = $this->getRequest(Request::POST, $this->workspaceUri);
        $request->setBody($body);
        $request->setContentType('application/x-www-form-urlencoded');
        $request->execute();
    }

    /**
     * Prevent accidental creation of same name siblings (which are not supported)
     *
     * @param $srcWorkspace
     * @param $srcAbsPath
     * @param $destAbsPath
     *
     * @throws \PHPCR\ItemExistsException
     */
    protected function checkForExistingNode($srcWorkspace, $srcAbsPath, $destAbsPath)
    {
        try {
            $existingNode = $this->getNode($destAbsPath);
        } catch (ItemNotFoundException $exception) {
            return;
        }

        if (empty($existingNode->{'jcr:uuid'})) {
            throw new ItemExistsException('A node already exists at the destination path');
        } else {
            $existingNodeUuid = $existingNode->{'jcr:uuid'};

            try {
                $correspondingPath = $this->getNodePathForIdentifier($existingNodeUuid, $srcWorkspace);
            } catch (ItemNotFoundException $exception) {
                $correspondingPath = null;
            }

            if ($correspondingPath != $srcAbsPath) {
                throw new ItemExistsException(
                    'A node already exists at the destination path that does not correspond to the source node'
                );
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function updateNode(Node $node, $srcWorkspace)
    {
        $path = $this->encodeAndValidatePathForDavex($node->getPath());
        $srcWorkspaceUri = $this->server . $srcWorkspace;

        $body = '
            <D:update xmlns:D="DAV:">
                <D:workspace>
                    ' . $srcWorkspaceUri . '
                    <D:href>
                        ' . $srcWorkspaceUri . '
                    </D:href>
                </D:workspace>
            </D:update>
        ';

        $request = $this->getRequest(Request::UPDATE, $path, true);
        $request->setBody($body);
        $request->execute();
    }

    /**
     * {@inheritDoc}
     */
    public function storeNodes(array $operations)
    {
        /** @var $operation \Jackalope\Transport\AddNodeOperation */
        foreach ($operations as $operation) {
            if ($operation->node->isDeleted()) {
                $properties = $operation->node->getPropertiesForStoreDeletedNode();
            } else {
                $properties = $operation->node->getProperties();
            }
            $this->createNodeJsop($operation->srcPath, $properties);
        }
    }

    /**
     * @param Property $property
     */
    private function storeProperty(Property $property)
    {
        $path = $property->getPath();
        $typeid = $property->getType();
        $nativeValue = $property->getValueForStorage();
        if ($typeid === PropertyType::STRING) {
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
                $this->setJsopBody('^' . $path . ' : []');
            } else {
                $this->setJsopBody('^' . $path . ' : ');
            }
        } else {
            $this->setJsopBody('^' . $path . ' : ' . json_encode($value));
        }
    }

    /**
     * Checks for occurrence of invalid UTF characters, that can not occur in valid XML document.
     * If occurrence is found, returns false, otherwise true.
     * Invalid characters were taken from this list: http://en.wikipedia.org/wiki/Valid_characters_in_XML#XML_1.0
     *
     * Uses regexp mentioned here: http://stackoverflow.com/a/961504
     *
     * @param $string string value
     * @return bool true if string is OK, false otherwise.
     */
    protected function isStringValid($string)
    {
        $regex = '/[^\x{9}\x{a}\x{d}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]+/u';

        return (preg_match($regex, $string, $matches) === 0);
    }

    /**
     * {@inheritDoc}
     */
    public function updateProperties(Node $node)
    {
        foreach ($node->getProperties() as $property) {
            /** @var $property Property */
            if ($property->isModified() || $property->isNew()) {
                $this->storeProperty($property);
            }
        }
    }

    /**
     * create the node markup and a list of value dispatches for multivalue properties
     *
     * @param string $path path to the current node with the last path segment
     *      being the node name
     * @param array $properties of this node
     *
     * @return string the xml for the node
     */
    protected function createNodeJsop($path, $properties)
    {
        $body = '+' . $path . ' : {';
        $binaries = array();
        // first do the main properties, so they are certainly in the beginning
        $nodeCreationProperties = array("jcr:primaryType", "jcr:mixinTypes");
        foreach ($nodeCreationProperties as $name) {
            if (isset($properties[$name])) {
                $body .= json_encode($name) . ':' . json_encode($properties[$name]->getValueForStorage()) . ",";
            }
        }

        foreach ($properties as $name => $property) {
            if (in_array($name, $nodeCreationProperties)) {
                continue;
            }
            $value = $this->propertyToJsopString($property);
            if (!$value) {
                $binaries[] = $property;
            } else {
                $body .= json_encode($name) . ':' . json_encode($value) . ",";
            }
        }

        $body .= "}";
        $this->setJsopBody($body);

        foreach ($binaries as $binary) {
            $this->storeProperty($binary);
        }
    }

    /**
     * This method is used when building a JSOP of the properties
     *
     * @param $value
     * @param $type
     * @return mixed|string
     */
    protected function propertyToJsopString(Property $property)
    {
        switch ($property->getType()) {
            case PropertyType::DECIMAL:
                return null;
            case PropertyType::DOUBLE:
                return $this->valueConverter->convertType($property->getValueForStorage(), PropertyType::DOUBLE);
            case PropertyType::LONG:
                return $this->valueConverter->convertType($property->getValueForStorage(), PropertyType::LONG);
            case PropertyType::DATE:
            case PropertyType::WEAKREFERENCE:
            case PropertyType::REFERENCE:
            case PropertyType::BINARY:
            case PropertyType::PATH:
            case PropertyType::URI:
                return null;
            case PropertyType::NAME:
                if ($property->getName() != 'jcr:primaryType') {
                    return null;
                }
                break;

        }

        return $property->getValueForStorage();
    }

    /**
     * {@inheritDoc}
     */
    public function getNamespaces()
    {
        $request = $this->getRequest(Request::REPORT, $this->workspaceUri);
        $request->setBody($this->buildReportRequest('dcr:registerednamespaces'));
        $dom = $request->executeDom();

        if ($dom->firstChild->localName != 'registerednamespaces-report'
            || $dom->firstChild->namespaceURI != self::NS_DCR
        ) {
            throw new RepositoryException('Error talking to the backend. '.$dom->saveXML());
        }

        $mappings = array();
        $namespaces = $dom->getElementsByTagNameNS(self::NS_DCR, 'namespace');
        foreach ($namespaces as $elem) {
            $mappings[$elem->firstChild->textContent] = $elem->lastChild->textContent;
        }

        return $mappings;
    }

    /**
     * {@inheritDoc}
     *
     * @throws UnsupportedRepositoryOperationException if trying to
     *      overwrite existing prefix to new uri, as jackrabbit can not do this
     */
    public function registerNamespace($prefix, $uri)
    {
        // seems jackrabbit always expects full list of namespaces
        $namespaces = $this->getNamespaces();

        // check if prefix is already mapped
        if (isset($namespaces[$prefix])) {
            if ($namespaces[$prefix] == $uri) {
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

    /**
     * {@inheritDoc}
     */
    public function unregisterNamespace($prefix)
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

    /**
     * {@inheritDoc}
     */
    public function getNodeTypes($nodeTypes = array())
    {
        $request = $this->getRequest(Request::REPORT, $this->workspaceUriRoot);
        $request->setBody($this->buildNodeTypesRequest($nodeTypes));
        $dom = $request->executeDom();

        if ($dom->firstChild->localName != 'nodeTypes') {
            throw new RepositoryException('Error talking to the backend. '.$dom->saveXML());
        }

        if ($this->typeXmlConverter === null) {
            $this->typeXmlConverter = $this->factory->get('NodeType\\NodeTypeXmlConverter');
        }

        return $this->typeXmlConverter->getNodeTypesFromXml($dom);
    }

    // NodeTypeCndManagementInterface //

    /**
     * {@inheritDoc}
     */
    public function registerNodeTypesCnd($cnd, $allowUpdate)
    {
        $request = $this->getRequest(Request::PROPPATCH, $this->workspaceUri);
        $request->setBody($this->buildRegisterNodeTypeRequest($cnd, $allowUpdate));
        $request->execute();
    }

    // PermissionInterface //

    /**
     * {@inheritDoc}
     */
    public function getPermissions($path)
    {
        // TODO: OPTIMIZE - once we have ACL this might be done without any server request
        $body = '<?xml version="1.0" encoding="UTF-8"?>' .
                '<dcr:privileges xmlns:dcr="http://www.day.com/jcr/webdav/1.0">' .
                '<D:href xmlns:D="DAV:">'.$this->addWorkspacePathToUri($path).'</D:href>' .
                '</dcr:privileges>';

        $valid_permissions = array(
            SessionInterface::ACTION_ADD_NODE,
            SessionInterface::ACTION_READ,
            SessionInterface::ACTION_REMOVE,
            SessionInterface::ACTION_SET_PROPERTY);

        $result = array();

        $request = $this->getRequest(Request::REPORT, $this->workspaceUri);
        $request->setBody($body);
        $dom = $request->executeDom();

        foreach ($dom->getElementsByTagNameNS(self::NS_DAV, 'current-user-privilege-set') as $node) {
            foreach ($node->getElementsByTagNameNS(self::NS_DAV, 'privilege') as $privilege) {
                foreach ($privilege->childNodes as $child) {
                    $permission = str_replace('dcr:', '', $child->tagName);
                    if (! in_array($permission, $valid_permissions)) {
                        throw new RepositoryException("Invalid permission '$permission'");
                    }
                    $result[] = $permission;
                }
            }
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function lockNode($absPath, $isDeep, $isSessionScoped, $timeoutHint = PHP_INT_MAX, $ownerInfo = null)
    {
        $timeout = $timeoutHint === PHP_INT_MAX ? 'infinite' : $timeoutHint;
        $ownerInfo = (null === $ownerInfo) ? $this->credentials->getUserID() : (string) $ownerInfo;

        $depth = $isDeep ? Request::INFINITY : 0;

        $lockScope = $isSessionScoped ? '<dcr:exclusive-session-scoped xmlns:dcr="http://www.day.com/jcr/webdav/1.0"/>' : '<D:exclusive/>';

        $request = $this->getRequest(Request::LOCK, $absPath);
        $request->addHeader('Timeout: Second-' . $timeout);
        $request->setDepth($depth);

        $request->setBody('<?xml version="1.0" encoding="utf-8"?>'.
            '<D:lockinfo xmlns:D="' . self::NS_DAV . '">'.
            '  <D:lockscope>' . $lockScope . '</D:lockscope>'.
            '  <D:locktype><D:write/></D:locktype>'.
            '  <D:owner>' . $ownerInfo . '</D:owner>' .
            '</D:lockinfo>');

        try {
            $dom = $request->executeDom();

            return $this->generateLockFromDavResponse($dom, true, $absPath);
        } catch (\PHPCR\RepositoryException $ex) {
            // TODO: can we move that into the request handling code that determines the correct exception to throw?
            // Check if it's a 412 error, otherwise re-throw the same exception
            if (preg_match('/Response \(HTTP 412\):/', $ex->getMessage())) {
                throw new \PHPCR\Lock\LockException("Unable to lock the non-lockable node '$absPath': " . $ex->getMessage(), 412);
            }

            // Any other exception will simply be rethrown
            throw $ex;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function isLocked($absPath)
    {
        $request = $this->getRequest(Request::PROPFIND, $absPath);
        $request->setBody($this->buildPropfindRequest(array('D:lockdiscovery')));
        $request->setDepth(0);
        $dom = $request->executeDom();

        $lockInfo = $this->getRequiredDomElementByTagNameNS($dom, self::NS_DAV, 'lockdiscovery');

        return $lockInfo->childNodes->length > 0;
    }

    /**
     * {@inheritDoc}
     */
    public function unlock($absPath, $lockToken)
    {
        $request = $this->getRequest(Request::UNLOCK, $absPath);
        $request->setLockToken($lockToken);
        $request->execute();
    }

    /**
     * {@inheritDoc}
     */
    public function getEvents($date, EventFilterInterface $filter, SessionInterface $session)
    {
        return $this->factory->get('Jackalope\Transport\Jackrabbit\EventBuffer', array(
            $filter,
            $this,
            $this->nodeTypeManager,
            str_replace('jcr:root', 'jcr%3aroot', $this->workspaceUriRoot),
            $this->fetchEventData($date)
        ));
    }

    /**
     * Internal method to fetch event data.
     *
     * @param $date
     *
     * @return array hashmap with 'data' containing unfiltered DOM of xml atom
     *      feed of events, 'nextMillis' is the next timestamp if there are
     *      more events to be found, false otherwise.
     *
     * @private
     */
    public function fetchEventData($date)
    {
        $path = $this->workspaceUri . self::JCR_JOURNAL_PATH;
        $request = $this->getRequest(Request::GET, $path, false);
        $request->addHeader(sprintf('If-None-Match: "%s"', base_convert($date, 10, 16)));
        $curl = $request->execute(true);
        // create new DOMDocument and load the response text.
        $dom = new DOMDocument();
        $dom->loadXML($curl->getResponse());

        $next = base_convert(trim($curl->getHeader('ETag'), '"'), 16, 10);

        if ($next == $date) {
            // no more events
            $next = false;
        }

        return array(
            'data' => $dom,
            'nextMillis' => $next,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function setUserData($userData)
    {
        $this->userData = $userData;
    }

    /**
     * @return mixed null or string
     */
    public function getUserData()
    {
        return $this->userData;
    }

    /**
     * {@inheritDoc}
     */
    public function createWorkspace($name, $srcWorkspace = null)
    {
        if (null != $srcWorkspace) {
            // https://issues.apache.org/jira/browse/JCR-3144
            throw new UnsupportedRepositoryOperationException('Can not create a workspace from a source workspace as we neither implemented clone nor have native support for this');
        }

        $curl = $this->getCurl();
        $uri = $this->server . $name;

        $request = $this->factory->get('Transport\\Jackrabbit\\Request', array($this, $curl, Request::MKWORKSPACE, $uri));
        $request->setCredentials($this->credentials);
        foreach ($this->defaultHeaders as $header) {
            $request->addHeader($header);
        }

        if (!$this->sendExpect) {
            $request->addHeader("Expect:");
        }

        $request->execute();
    }

    public function deleteWorkspace($name)
    {
        // https://issues.apache.org/jira/browse/JCR-3144
        throw new UnsupportedRepositoryOperationException("Can not delete a workspace as jackrabbit can not do it. Find the jackrabbit folder and look for workspaces/$name and delete that folder");
    }

    // protected helper methods //

    /**
     * Build the xml required to register node types
     *
     * @param  string $cnd the node type definition
     * @return string XML with register request
     *
     * @author david at liip.ch
     */
    protected function buildRegisterNodeTypeRequest($cnd, $allowUpdate)
    {
        $cnd = '<dcr:cnd>'.str_replace(array('<','>'), array('&lt;','&gt;'), $cnd).'</dcr:cnd>';
        $cnd .= '<dcr:allowupdate>'.($allowUpdate ? 'true' : 'false').'</dcr:allowupdate>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="no"?><D:propertyupdate xmlns:D="DAV:"><D:set><D:prop><dcr:nodetypes-cnd xmlns:dcr="http://www.day.com/jcr/webdav/1.0">'.$cnd.'</dcr:nodetypes-cnd></D:prop></D:set></D:propertyupdate>';
    }

    /**
     * Build the xml to update the namespaces
     *
     * You need to repeat all existing node type plus add your new ones
     *
     * @param array $mappings hashmap of prefix => uri for all existing and new namespaces
     */
    protected function buildRegisterNamespaceRequest($mappings)
    {
        $ns = '';
        foreach ($mappings as $prefix => $uri) {
            $ns .= "<dcr:namespace><dcr:prefix>$prefix</dcr:prefix><dcr:uri>$uri</dcr:uri></dcr:namespace>";
        }

        return '<?xml version="1.0" encoding="UTF-8"?><D:propertyupdate xmlns:D="DAV:"><D:set><D:prop><dcr:namespaces xmlns:dcr="http://www.day.com/jcr/webdav/1.0">'.
                $ns .
                '</dcr:namespaces></D:prop></D:set></D:propertyupdate>';
    }

    /**
     * Returns the XML required to request nodetypes
     *
     * @param  array  $nodesType The list of nodetypes you want to request for.
     * @return string XML with the request information.
     */
    protected function buildNodeTypesRequest(array $nodeTypes)
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
        $xml .='</jcr:nodetypes>';

        return $xml;
    }

    /**
     * Build PROPFIND request XML for the specified property names
     *
     * @param  array  $properties names of the properties to search for
     * @return string XML to post in the body
     */
    protected function buildPropfindRequest($properties)
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'.
            '<D:propfind xmlns:D="DAV:" xmlns:dcr="http://www.day.com/jcr/webdav/1.0"><D:prop>';
        if (!is_array($properties)) {
            $properties = array($properties);
        }
        foreach ($properties as $property) {
            $xml .= '<'. $property . '/>';
        }
        $xml .= '</D:prop></D:propfind>';

        return $xml;
    }

    /**
     * Build a REPORT XML request string
     *
     * @param  string $name Name of the resource to be requested.
     * @return string XML string representing the head of the request.
     */
    protected function buildReportRequest($name)
    {
        return '<?xml version="1.0" encoding="UTF-8"?><' .
                $name .
               ' xmlns:dcr="http://www.day.com/jcr/webdav/1.0"/>';
    }

    /**
     * Build REPORT XML request for locating a node path by uuid
     *
     * @param  string $uuid Unique identifier of the node to be asked for.
     * @return string XML sring representing the content of the request.
     */
    protected function buildLocateRequest($uuid)
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'.
               '<dcr:locate-by-uuid xmlns:dcr="http://www.day.com/jcr/webdav/1.0">'.
               '<D:href xmlns:D="DAV:">' .
                $uuid .
               '</D:href></dcr:locate-by-uuid>';
    }

    /**
     * {@inheritDoc}
     */
    public function setNodeTypeManager($nodeTypeManager)
    {
        $this->nodeTypeManager = $nodeTypeManager;
    }

    /**
     * Checks if the path is absolute and valid, and properly urlencodes special characters
     *
     * This is to be used in the Davex headers. The XML requests can cope with unencoded stuff
     *
     * @param string $path to check
     *
     * @return string the cleaned path
     *
     * @throws RepositoryException If path is not absolute or invalid
     */
    protected function encodeAndValidatePathForDavex($path)
    {
        PathHelper::assertValidAbsolutePath($path);

        $path = rawurlencode($path);
        // we encoded the whole path, need to rebuild slashes and parenthesis
        // this will not collide with %2F as % was encoded by rawurlencode
        $path = str_replace(array('%2F', '%5B', '%5D'), array('/', '[', ']'), $path);

        return $path;
    }

    /**
     * remove the server and workspace part from an uri, leaving the absolute
     * path inside the current workspace
     *
     * @param string $uri a full uri including the server path, workspace and jcr%3aroot
     *
     * @return string absolute path in the current work space
     */
    protected function stripServerRootFromUri($uri)
    {
        return substr($uri,strlen($this->workspaceUriRoot));
    }

    /**
     * Prepends the workspace root to the uris that contain an absolute path
     *
     * @param  string              $uri The absolute path in the current workspace or server uri
     * @return string              The server uri with this path
     * @throws RepositoryException If workspaceUri is missing (not logged in)
     */
    protected function addWorkspacePathToUri($uri)
    {
        if (empty($uri) || '/' === $uri[0]) {
            if (empty($this->workspaceUri)) {
                throw new RepositoryException("Implementation error: Please login before accessing content");
            }
            $uri = $this->workspaceUriRoot . $uri;
        }

        return $uri;
    }

    /**
     * Extract the information from a LOCK DAV response and create the
     * corresponding Lock object.
     *
     * @param \DOMElement $response
     * @param bool        $sessionOwning whether the current session is owning the lock (aka
     *      we created it in this request)
     * @param string $path the owning node path, if we created this node
     *
     * @return \Jackalope\Lock\Lock
     */
    protected function generateLockFromDavResponse($response, $sessionOwning = false, $path = null)
    {
        $lock = new Lock();
        $lockDom = $this->getRequiredDomElementByTagNameNS($response, self::NS_DAV, 'activelock', "No lock received");

        //var_dump($response->saveXML($lockDom));

        // Check this is not a transaction lock
        $type = $this->getRequiredDomElementByTagNameNS($lockDom, self::NS_DAV, 'locktype', 'No lock type received');
        if (!$type->childNodes->length) {
            $tagName = $type->childNodes->item(0)->localName;
            if ($tagName !== 'write') {
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
            throw new RepositoryException('Invalid lock scope received: ' . $response->saveHTML($scopeDom));
        }

        // Extract the depth
        $depthDom = $this->getRequiredDomElementByTagNameNS($lockDom, self::NS_DAV, 'depth', 'No depth in the received lock');
        $lock->setIsDeep($depthDom->textContent === 'infinity');

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
     * @throws \PHPCR\RepositoryException When the element is not found and an $errorMessage is set
     *
     * @param  \DOMNode      $dom          The DOM element which content should be searched
     * @param  string        $namespace    The namespace of the searched element
     * @param  string        $element      The name of the searched element
     * @param  string        $errorMessage The error message in case the element is not found
     * @return bool|\DOMNode
     */
    protected function getRequiredDomElementByTagNameNS($dom, $namespace, $element, $errorMessage = '')
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
     * @param string $timeoutValue The timeout in seconds or PHP_INT_MAX for infinite
     *
     * @return int the expire timestamp to be used with Lock::setExpireTime,
     *      that is when this lock expires in seconds since 1970 or null for inifinite
     *
     * @throws InvalidArgumentException if the timeout value can not be parsed
     */
    protected function parseTimeout($timeoutValue)
    {
        if (self::JCR_INFINITE == $timeoutValue) {
            return null;
        }

        if (! preg_match('/Second\-([\d]+)/', $timeoutValue, $matches)) {
            throw new RepositoryException("Unexpected response on lock from the backend, could not parse seconds out of '$timeoutValue'");
        }
        $time = $matches[1];

        // keep this hack for jackrabbit 2.3.7 for now. it reported a bogous value for the timeout
        if (self::JCR_INFINITE_LOCK_TIMEOUT == $time || self::JCR_INFINITE_LOCK_TIMEOUT - 1 == $time) {
            // prevent glitches due to second boundary during request
            return null;
        }

        return time() + $time;
    }

    protected function setJsopBody($value, $key = ":diff", $type = null)
    {
        if ($type) {
             $this->jsopBody[$key] = array($value,$type);
        } else {
            if (!isset($this->jsopBody[$key])) {
                $this->jsopBody[$key] = "";
            } else {
                $this->jsopBody[$key] .= "\r";
            }
            $this->jsopBody[$key] .= $value;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function prepareSave()
    {
    }

    /**
     * {@inheritDoc}
     */
    public function finishSave()
    {
        if (count($this->jsopBody) > 0) {
            $request = $this->getRequest(Request::POST, "/");
            $body = '';

            if (count($this->jsopBody) > 1 || !isset($this->jsopBody[':diff'])) {
                $mime_boundary = md5(mt_rand());
                //do the diffs at last
                $diff = null;
                if (isset($this->jsopBody[':diff'])) {
                    $diff = $this->jsopBody[':diff'];
                    unset($this->jsopBody[':diff']);
                }

                foreach ($this->jsopBody as $n => $v) {
                    $body .= $this->getMimePart($n, $v, $mime_boundary);
                }

                if ($diff) {
                    $body .= $this->getMimePart(":diff", $diff, $mime_boundary);
                }
                $body .= "--" . $mime_boundary . "--". "\r\n\r\n" ; // finish with two eol's!!


                $request->setContentType("multipart/form-data; boundary=$mime_boundary");
            } else {
                $body = urlencode(":diff")."=". urlencode($this->jsopBody[':diff']);
                $request->setContentType("application/x-www-form-urlencoded; charset=utf-8");
            }

            try {
                $request->setBody($body);
                $request->execute();
            } catch (HTTPErrorException $e) {
                // TODO: can we throw any other more specific errors here?
                throw new RepositoryException('Something went wrong while saving nodes', $e->getCode(), $e);
            }
        }

        $this->jsopBody = array();
    }

    /**
     * {@inheritDoc}
     */
    public function rollbackSave()
    {
    }

    protected function getMimePart($name, $value, $mime_boundary)
    {
        $data = '';

        $eol = "\r\n";
        $data .= '--' . $mime_boundary . $eol ;
        if (is_array($value)) {
            if (is_array($value[0])) {
                foreach ($value[0] as $v) {
                    $data .= $this->getMimePart($name, array($v,$value[1]), $mime_boundary);
                }

                return $data;
            }

            if (is_resource(($value[0]))) {
                $data .= 'Content-Disposition: form-data; name="' . $name . '"; filename="' . $name . '"' . $eol;
                $data .= 'Content-Type: jcr-value/'. strtolower(PropertyType::nameFromValue($value[1])) .'; charset=UTF-8'. $eol;
                $data .= 'Content-Transfer-Encoding: binary'. $eol.$eol;
                $data .= stream_get_contents($value[0]) . $eol;
                fclose($value[0]);
            } else {
                $data .= 'Content-Disposition: form-data; name="' . $name . '"' . $eol;
                $data .= 'Content-Type: jcr-value/'. strtolower(PropertyType::nameFromValue($value[1])) .'; charset=UTF-8'. $eol;
                $data .= 'Content-Transfer-Encoding: 8bit'. $eol.$eol;
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
                    $data .= $this->getMimePart($name,$v,$mime_boundary);
                }

                return $data;
            }
            $data .= 'Content-Disposition: form-data; name="'.$name.'"'. $eol;
            $data .= 'Content-Type: text/plain; charset=UTF-8'. $eol;
            $data .= 'Content-Transfer-Encoding: 8bit'. $eol. $eol;
            //$data .= '--' . $mime_boundary . $eol;
            $data .= $value . $eol;

        }

        return $data;
    }
}
