<?php

namespace Jackalope\Transport\Jackrabbit;

use DOMDocument;

use PHPCR\CredentialsInterface;
use PHPCR\SimpleCredentials;
use PHPCR\RepositoryException;
use PHPCR\NoSuchWorkspaceException;
use PHPCR\ItemNotFoundException;
use PHPCR\PathNotFoundException;
use PHPCR\ReferentialIntegrityException;
use PHPCR\NodeType\ConstraintViolationException;
use PHPCR\NodeType\NoSuchNodeTypeException;

use Jackalope\FactoryInterface;

/**
 * Request class for the Davex protocol
 *
 * @license http://www.apache.org/licenses/LICENSE-2.0  Apache License Version 2.0, January 2004
 *
 * @author Christian Stocker <chregu@liip.ch>
 * @author David Buchmann <david@liip.ch>
 * @author Roland Schilter <roland.schilter@liip.ch>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Lukas Kahwe Smith <smith@pooteeweet.org>
 */
class Request
{
    /**
     * Name of the user agent to be exposed to a client.
     * @var string
     */
    const USER_AGENT = 'jackalope-php/1.0';

    /**
     * Identifier of the 'GET' http request method.
     * @var string
     */
    const GET = 'GET';

    /**
     * Identifier of the 'POST' http request method.
     * @var string
     */
    const POST = 'POST';
    /**
     * Identifier of the 'PUT' http request method.
     * @var string
     */
    const PUT = 'PUT';

    /**
     * Identifier of the 'MKCOL' http request method.
     * @var string
     */
    const MKCOL = 'MKCOL';

    /**
     * Identifier of the 'DELETE' http request method.
     * @var string
     */
    const DELETE = 'DELETE';

    /**
     * Identifier of the 'REPORT' http request method.
     * @var string
     */
    const REPORT = 'REPORT';

    /**
     * Identifier of the 'SEARCH' http request method.
     * @var string
     */
    const SEARCH = 'SEARCH';

    /**
     * Identifier of the 'PROPFIND' http request method.
     * @var string
     */
    const PROPFIND = 'PROPFIND';

    /**
     * Identifier of the 'PROPPATCH' http request method.
     * @var string
     */
    const PROPPATCH = 'PROPPATCH';

    /**
     * Identifier of the 'LOCK' http request method
     * @var string
     */
    const LOCK = 'LOCK';


    /**
     * Identifier of the 'UNLOCK' http request method
     * @var string
     */
    const UNLOCK = 'UNLOCK';

    /**
     * Identifier of the 'MKWORKSPACE' http request method to make a new workspace
     * @var string
     */
    const MKWORKSPACE = 'MKWORKSPACE';

    /**
     * Identifier of the 'COPY' http request method.
     * @var string
     */
    const COPY = 'COPY';

    /**
     * Identifier of the 'MOVE' http request method.
     * @var string
     */
    const MOVE = 'MOVE';

    /**
     * Identifier of the 'CHECKIN' http request method.
     * @var string
     */
    const CHECKIN = 'CHECKIN';

    /**
     * Identifier of the 'CHECKOUT' http request method.
     * @var string
     */
    const CHECKOUT = 'CHECKOUT';

    /**
     * Identifier of the 'UPDATE' http request method.
     * @var string
     */
    const UPDATE = 'UPDATE';

    /** @var string     Possible argument for {@link setDepth()} */
    const INFINITY = 'infinity';

    /**
     * @var Client
     */
    protected $client;

    /**
     * @var curl
     */
    protected $curl;

    /**
     * Name of the request method to be used.
     * @var string
     */
    protected $method;

    /**
     * Url(s) to get/post/..
     * @var array
     */
    protected $uri;

    /**
     * Set of credentials necessary to connect to the server or else.
     * @var CredentialsInterface
     */
    protected $credentials;

    /**
     * Request content-type
     * @var string
     */
    protected $contentType = 'text/xml; charset=utf-8';

    /**
     * How far the request should go, default is 0
     * @var int
     */
    protected $depth = 0;

    /**
     * Posted content for methods that require it
     * @var string
     */
    protected $body = '';

    /** @var array[]string  A list of additional HTTP headers to be sent */
    protected $additionalHeaders = array();

    /**
     * The lock token active for this request otherwise FALSE for no locking
     * @var string|FALSE
     */
    protected $lockToken = false;

    /**
     * Whether we already did a version check in handling an error.
     * Doing this once per php process is enough.
     *
     * @var bool
     */
    static protected $versionChecked = false;

    /**
     * Initiaties the NodeTypes request object.
     *
     * @param FactoryInterface $factory Ignored for now, as this class does not create objects
     * @param Client $client The jackrabbit client instance
     * @param curl $curl The cURL object to use in this request
     * @param string $method the HTTP method to use, one of the class constants
     * @param string|array $uri the remote url for this request, including protocol,
     *      host name, workspace and path to the object to manipulate. May be an array of uri
     */
    public function __construct(FactoryInterface $factory, Client $client, curl $curl, $method, $uri)
    {
        $this->client = $client;
        $this->curl = $curl;
        $this->setMethod($method);
        $this->setUri($uri);
    }

    /**
     * Set the credentials for the request. Setting them to null will make a
     * request without authentication header.
     *
     * @param CredentialsInterface $creds the credentials to use in the request.
     */
    public function setCredentials(CredentialsInterface $creds = null)
    {
        $this->credentials = $creds;
    }

    /**
     * Set a different content type for this request. The default is text/xml in utf-8
     *
     * @param string $contentType
     */
    public function setContentType($contentType)
    {
        $this->contentType = (string) $contentType;
    }

    /**
     * Set the depth to which nodes should be fetched.
     *
     * To support more than 0, we need to implement more logic in parsing
     * the response too.
     *
     * @param int|string $depth
     */
    public function setDepth($depth)
    {
        $this->depth = $depth;
    }

    /**
     * Set the request body
     *
     * @param string $body
     */
    public function setBody($body)
    {
        $this->body = (string) $body;
    }

    /**
     * Set or update the HTTP method to be used in this request.
     *
     * @param string $method the HTTP method to use, one of the class constants
     */
    public function setMethod($method)
    {
        $this->method = $method;
    }

    /**
     * @param string|array $uri the request target
     */
    public function setUri($uri)
    {
        if (!is_array($uri)) {
            $this->uri = array($uri => $uri);
        } else {
            $this->uri = $uri;
        }
    }

    /**
     * add an additional http header
     *
     * @param string $header HTTP header
     */
    public function addHeader($header)
    {
        $this->additionalHeaders[] = $header;
    }

    /**
     * Add the user data header
     * @param string $userData
     */
    public function addUserData($userData)
    {
        $userDataHeader = 'Link: <data:,' . urlencode($userData) . '>; rel="http://www.day.com/jcr/webdav/1.0/user-data"';
        $this->addHeader($userDataHeader);
    }

    /**
     * Set the transaction lock token to be used with this request
     *
     * @param string $lockToken the transaction lock
     */
    public function setLockToken($lockToken)
    {
        $this->lockToken = (string) $lockToken;
    }

    /**
     * used by multiCurl with fresh curl instances
     *
     * @param curl $curl
     * @param bool $getCurlObject whether to return the curl object instead of the response
     */
    protected function prepareCurl(curl $curl, $getCurlObject)
    {
        if ($this->credentials instanceof SimpleCredentials) {
            $curl->setopt(CURLOPT_USERPWD, $this->credentials->getUserID().':'.$this->credentials->getPassword());
        }
        // otherwise leave this alone, the new curl instance has no USERPWD yet

        $headers = array(
            'Depth: ' . $this->depth,
            'Content-Type: '.$this->contentType,
            'User-Agent: '.self::USER_AGENT
        );
        $headers = array_merge($headers, $this->additionalHeaders);

        if ($this->lockToken) {
            $headers[] = 'Lock-Token: <'.$this->lockToken.'>';
        }

        $curl->setopt(CURLOPT_RETURNTRANSFER, true);
        $curl->setopt(CURLOPT_CUSTOMREQUEST, $this->method);

        $curl->setopt(CURLOPT_HTTPHEADER, $headers);
        $curl->setopt(CURLOPT_POSTFIELDS, $this->body);
        if ($getCurlObject) {
            $curl->parseResponseHeaders();
        }
        return $curl;
    }

    /**
     * Requests the data to be identified by a formerly prepared request.
     *
     * Prepares the curl object, executes it and checks
     * for transport level errors, throwing the appropriate exceptions.
     *
     * @param bool $getCurlObject wheter to return the curl object instead of the response
     * @param bool $forceMultiple whether to force parallel requests or not
     *
     * @return string|array of XML representation of the response.
     */
    public function execute($getCurlObject = false, $forceMultiple = false)
    {
        if (!$forceMultiple && count($this->uri) === 1) {
            return $this->singleRequest($getCurlObject);
        }
        return $this->multiRequest($getCurlObject);
    }

    /**
     * Requests the data for multiple requests
     *
     * @param bool $getCurlObject whether to return the curl object instead of the response
     *
     * @return array of XML representations of responses or curl objects.
     */
    protected function multiRequest($getCurlObject = false)
    {
        $mh = curl_multi_init();

        $curls = array();
        foreach ($this->uri as $absPath => $uri) {
            $tempCurl = new curl($uri);
            $tempCurl = $this->prepareCurl($tempCurl, $getCurlObject);
            $curls[$absPath] = $tempCurl;
            curl_multi_add_handle($mh, $tempCurl->getCurl());
        }

        $active = null;

        do {
            $mrc = curl_multi_exec($mh, $active);
        } while ($active || $mrc == CURLM_CALL_MULTI_PERFORM);

        while ($active && CURLM_OK == $mrc) {
            if (-1 != curl_multi_select($mh)) {
                do {
                    $mrc = curl_multi_exec($mh, $active);
                } while (CURLM_CALL_MULTI_PERFORM == $mrc);
            }
        }

        $responses = array();
        foreach ($curls as $key => $curl) {
            if (empty($failed)) {
                $httpCode = $curl->getinfo(CURLINFO_HTTP_CODE);
                if ($httpCode >= 200 && $httpCode < 300) {
                    if ($getCurlObject) {
                        $responses[$key] = $curl;
                    } else {
                        $responses[$key] = curl_multi_getcontent($curl->getCurl());
                    }
                }
            }
            curl_multi_remove_handle($mh, $curl->getCurl());
        }
        curl_multi_close($mh);
        return $responses;
    }

    /**
     * Requests the data for a single requests
     *
     * @param bool $getCurlObject whether to return the curl object instead of the response
     *
     * @return string XML representation of a response or curl object.
     */
    protected function singleRequest($getCurlObject)
    {
        if ($this->credentials instanceof SimpleCredentials) {
            $this->curl->setopt(CURLOPT_USERPWD, $this->credentials->getUserID().':'.$this->credentials->getPassword());
            $curl = $this->curl;
        } else {
            // we seem to be unable to remove the Authorization header
            // setting to null produces a bogus Authorization: Basic Og==
            $curl = new curl;
        }

        $headers = array(
            'Depth: ' . $this->depth,
            'Content-Type: '.$this->contentType,
            'User-Agent: '.self::USER_AGENT
        );
        $headers = array_merge($headers, $this->additionalHeaders);

        if ($this->lockToken) {
            $headers[] = 'Lock-Token: <'.$this->lockToken.'>';
        }

        $curl->setopt(CURLOPT_RETURNTRANSFER, true);
        $curl->setopt(CURLOPT_CUSTOMREQUEST, $this->method);
        $curl->setopt(CURLOPT_URL, reset($this->uri));
        $curl->setopt(CURLOPT_HTTPHEADER, $headers);
        $curl->setopt(CURLOPT_POSTFIELDS, $this->body);
        // TODO: uncomment next line to get verbose information from CURL
        //$curl->setopt(CURLOPT_VERBOSE, 1);
        if ($getCurlObject) {
            $curl->parseResponseHeaders();
        }

        $response = $curl->exec();
        $curl->setResponse($response);

        $httpCode = $curl->getinfo(CURLINFO_HTTP_CODE);
        if ($httpCode >= 200 && $httpCode < 300) {
            if ($getCurlObject) {
                return $curl;
            }
            return $response;
        }
        $this->handleError($curl, $response, $httpCode);
    }

    /**
     * Handles errors caused by singleRequest and multiRequest
     *
     * For transport level errors, tries to figure out what went wrong to
     * throw the most appropriate exception.
     *
     * @param curl $curl
     * @param string $response the response body
     * @param int $httpCode the http response code
     *
     * @throws NoSuchWorkspaceException if it was not possible to reach the server (resolve host or connect)
     * @throws ItemNotFoundException if the object was not found
     * @throws RepositoryExceptions if on any other error.
     * @throws PathNotFoundException if the path was not found (server returned 404 without xml response)
     *
     */
    protected function handleError(curl $curl, $response, $httpCode)
    {
        // first: check if the backend is too old for us
        if (! self::$versionChecked) {
            // avoid endless loops.
            self::$versionChecked = true;
            try {
                // getting the descriptors triggers a version check
                $this->client->getRepositoryDescriptors();
            } catch (\Exception $e) {
                if ($e instanceof \PHPCR\UnsupportedRepositoryOperationException) {
                    throw $e;
                }
                //otherwise ignore exception here as to not confuse what happened
            }
        }

        switch ($curl->errno()) {
            case CURLE_COULDNT_RESOLVE_HOST:
            case CURLE_COULDNT_CONNECT:
                throw new NoSuchWorkspaceException($curl->error());
        }

        // TODO extract HTTP status string from response, more descriptive about error

        // use XML error response if it's there
        if (substr($response, 0, 1) === '<') {
            $dom = new DOMDocument();
            $dom->loadXML($response);
            $err = $dom->getElementsByTagNameNS(Client::NS_DCR, 'exception');
            if ($err->length > 0) {
                $err = $err->item(0);
                $errClass = $err->getElementsByTagNameNS(Client::NS_DCR, 'class')->item(0)->textContent;
                $errMsg = $err->getElementsByTagNameNS(Client::NS_DCR, 'message')->item(0)->textContent;

                $exceptionMsg = 'HTTP ' . $httpCode . ': ' . $errMsg;
                switch ($errClass) {
                    case 'javax.jcr.NoSuchWorkspaceException':
                        throw new NoSuchWorkspaceException($exceptionMsg);
                    case 'javax.jcr.nodetype.NoSuchNodeTypeException':
                        throw new NoSuchNodeTypeException($exceptionMsg);
                    case 'javax.jcr.ItemNotFoundException':
                        throw new ItemNotFoundException($exceptionMsg);
                    case 'javax.jcr.nodetype.ConstraintViolationException':
                        throw new ConstraintViolationException($exceptionMsg);
                    case 'javax.jcr.ReferentialIntegrityException':
                        throw new ReferentialIntegrityException($exceptionMsg);
                    //TODO: Two more errors needed for Transactions. How does the corresponding Jackrabbit response look like?
                    // javax.transaction.RollbackException => \PHPCR\Transaction\RollbackException
                    // java.lang.SecurityException => \PHPCR\AccessDeniedException

                    //TODO: map more errors here?
                    default:

                        // try to generically "guess" the right exception class name
                        $class = substr($errClass, strlen('javax.jcr.'));
                        $class = explode('.', $class);
                        array_walk($class, function(&$ns) { $ns = ucfirst(str_replace('nodetype', 'NodeType', $ns)); });
                        $class = '\\PHPCR\\'.implode('\\', $class);

                        if (class_exists($class)) {
                            throw new $class($exceptionMsg);
                        }
                        throw new RepositoryException($exceptionMsg . " ($errClass)");
                }
            }
        }
        if (404 === $httpCode) {
            throw new PathNotFoundException("HTTP 404 Path Not Found: {$this->method} \n" . $this->getShortErrorString());
        } elseif (405 == $httpCode) {
            throw new HTTPErrorException("HTTP 405 Method Not Allowed: {$this->method} \n" . $this->getShortErrorString(), 405);
        } elseif ($httpCode >= 500) {
            throw new RepositoryException("HTTP $httpCode Error from backend on: {$this->method} \n" . $this->getLongErrorString($curl,$response));
        }

        $curlError = $curl->error();

        $msg = "Unexpected error: \nCURL Error: $curlError \nResponse (HTTP $httpCode): {$this->method} \n" . $this->getLongErrorString($curl,$response);
        throw new RepositoryException($msg);
    }

    /**
     * returns a shorter error string to be used in exceptions
     *
     * It returns a "nicely" formatted URI of the request
     *
     * @return string the error message
     */

    protected function getShortErrorString()
    {
        return "--uri: --\n" . var_export($this->uri, true) . "\n";
    }

    /**
     * returns a longer error string to be used in generic exceptions
     *
     * It returns a "nicely" formatted URI of the request
     * plus the output of curl_getinfo
     * plus the response body including its size
     *
     * @param curl $curl The curl object
     * @param string $response the response body
     * @return string the error message
     */

    protected function getLongErrorString($curl, $response)
    {
        $string = $this->getShortErrorString();
        $string .= "--curl getinfo: --\n" . var_export($curl->getinfo(),true) . "\n" ;
        $string .= "--request body (size: " . strlen($this->body) . " bytes): --\n";
        if (strlen($this->body) > 2000) {
            $string .= substr($this->body,0,2000);
            $string .= "\n (truncated)\n";
        } else {
            $string .= $this->body . "\n";
        }
        $string .= "--response body (size: " . strlen($response) . " bytes): --\n$response\n--end response body--\n";
        return $string;
    }

    /**
     * Loads the response into an DOMDocument.
     *
     * Returns a DOMDocument from the backend or throws exception.
     * Does error handling for both connection errors and dcr:exception response
     *
     * @param bool $forceMultiple whether to force parallel requests or not
     *
     * @return DOMDocument The loaded XML response text.
     */
    public function executeDom($forceMultiple = false)
    {
        $xml = $this->execute(null, $forceMultiple);

        // create new DOMDocument and load the response text.
        $dom = new DOMDocument();
        $dom->loadXML($xml);

        return $dom;
    }

    /**
     * Loads the server response as a json string.
     *
     * Returns a decoded json string from the backend or throws exception
     *
     * @param bool $forceMultiple whether to force parallel requests or not
     *
     * @return mixed
     *
     * @throws RepositoryException if the json response is not valid
     */
    public function executeJson($forceMultiple = false)
    {
        $responses = $this->execute(null, $forceMultiple);
        if (!is_array($responses)) {
            $responses = array($responses);
            $reset = true;
        }

        $json = array();
        foreach ($responses as $key => $response) {
            $json[$key] = json_decode($response);
            if (null === $json[$key] && 'null' !== strtolower($response)) {
                $uri = reset($this->uri); // FIXME was $this->uri[$key]. at which point did we lose the right key?
                throw new RepositoryException("Not a valid json object: \nRequest: {$this->method} $uri \nResponse: \n$response");
            }
        }
        //TODO: are there error responses in json format? if so, handle them
        if (isset($reset)) {
            return reset($json);
        }
        return $json;
    }
}
