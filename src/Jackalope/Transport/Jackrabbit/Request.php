<?php

namespace Jackalope\Transport\Jackrabbit;

use DOMDocument;
use Jackalope\FactoryInterface;
use PHPCR\CredentialsInterface;
use PHPCR\ItemNotFoundException;
use PHPCR\Lock\LockException;
use PHPCR\LoginException;
use PHPCR\NodeType\ConstraintViolationException;
use PHPCR\NodeType\NoSuchNodeTypeException;
use PHPCR\NoSuchWorkspaceException;
use PHPCR\PathNotFoundException;
use PHPCR\ReferentialIntegrityException;
use PHPCR\RepositoryException;
use PHPCR\SimpleCredentials;
use PHPCR\UnsupportedRepositoryOperationException;

/**
 * Request class for the Davex protocol.
 *
 * @license http://www.apache.org/licenses Apache License Version 2.0, January 2004
 * @license http://opensource.org/licenses/MIT MIT License
 * @author Christian Stocker <chregu@liip.ch>
 * @author David Buchmann <david@liip.ch>
 * @author Roland Schilter <roland.schilter@liip.ch>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Lukas Kahwe Smith <smith@pooteeweet.org>
 * @author Markus Schmucker <markus.sr@gmx.net>
 */
class Request
{
    /**
     * Name of the user agent to be exposed to a client.
     */
    public const USER_AGENT = 'jackalope-php/2.0';

    /**
     * Identifier of the 'GET' http request method.
     */
    public const GET = 'GET';

    /**
     * Identifier of the 'POST' http request method.
     */
    public const POST = 'POST';
    /**
     * Identifier of the 'PUT' http request method.
     */
    public const PUT = 'PUT';

    /**
     * Identifier of the 'MKCOL' http request method.
     */
    public const MKCOL = 'MKCOL';

    /**
     * Identifier of the 'DELETE' http request method.
     */
    public const DELETE = 'DELETE';

    /**
     * Identifier of the 'REPORT' http request method.
     */
    public const REPORT = 'REPORT';

    /**
     * Identifier of the 'SEARCH' http request method.
     */
    public const SEARCH = 'SEARCH';

    /**
     * Identifier of the 'PROPFIND' http request method.
     */
    public const PROPFIND = 'PROPFIND';

    /**
     * Identifier of the 'PROPPATCH' http request method.
     */
    public const PROPPATCH = 'PROPPATCH';

    /**
     * Identifier of the 'LOCK' http request method.
     */
    public const LOCK = 'LOCK';

    /**
     * Identifier of the 'UNLOCK' http request method.
     */
    public const UNLOCK = 'UNLOCK';

    /**
     * Identifier of the 'MKWORKSPACE' http request method to make a new workspace.
     */
    public const MKWORKSPACE = 'MKWORKSPACE';

    /**
     * Identifier of the 'COPY' http request method.
     */
    public const COPY = 'COPY';

    /**
     * Identifier of the 'MOVE' http request method.
     */
    public const MOVE = 'MOVE';

    /**
     * Identifier of the 'CHECKIN' http request method.
     */
    public const CHECKIN = 'CHECKIN';

    /**
     * Identifier of the 'CHECKOUT' http request method.
     */
    public const CHECKOUT = 'CHECKOUT';

    /**
     * Identifier of the 'UPDATE' http request method.
     */
    public const UPDATE = 'UPDATE';

    /**
     * Identifier of the 'LABEL' http request method.
     */
    public const LABEL = 'LABEL';

    /**
     * Possible argument for {@link setDepth()}.
     */
    public const INFINITY = -1;

    /**
     * Jackrabbit uses the word infinity for infinite depth.
     *
     * In the PHP code we want to be type consistent and therefore use -1 to represent infinity.
     */
    private const JACKRABBIT_INFINITY = 'infinity';

    private Client $client;

    protected curl $curl;

    /**
     * Name of the HTTP request method to be used.
     */
    private string $method;

    /**
     * Url(s) to get/post/..
     *
     * @var string[]
     */
    private array $uri;

    /**
     * Set of credentials necessary to connect to the server or else.
     */
    private ?CredentialsInterface $credentials = null;

    private string $contentType = 'text/xml; charset=utf-8';

    /**
     * How far the request should go.
     */
    private int $depth = 0;

    /**
     * HTTP request body for methods that require it.
     */
    private string $body = '';

    /**
     * A list of additional HTTP headers to be sent.
     *
     * @var string[]
     */
    private array $additionalHeaders = [];

    /**
     * The lock token active for this request otherwise FALSE for no locking.
     *
     * @var string|false
     */
    private $lockToken = false;

    /**
     * Whether we already did a version check in handling an error.
     * Doing this once per php process is enough.
     */
    private static bool $versionChecked = false;

    /**
     * Whether we are in error handling mode to prevent infinite recursion.
     */
    private bool $errorHandlingMode = false;

    /**
     * Global curl-options used in this request.
     */
    private array $curlOptions = [];

    /**
     * Initiates the NodeTypes request object.
     *
     * @param FactoryInterface $factory Ignored for now, as this class does not create objects
     * @param Client           $client  The jackrabbit client instance
     * @param curl             $curl    The cURL object to use in this request
     * @param string           $method  the HTTP method to use, one of the class constants
     * @param string|array     $uri     the remote url for this request, including protocol,
     *                                  host name, workspace and path to the object to manipulate. May be an array of uri
     */
    public function __construct(FactoryInterface $factory, Client $client, curl $curl, string $method, $uri)
    {
        $this->client = $client;
        $this->curl = $curl;
        $this->setMethod($method);
        $this->setUri($uri);
    }

    /**
     * Add curl-options for this request.
     */
    public function addCurlOptions(array $options): void
    {
        $this->curlOptions += $options;
    }

    /**
     * Set the credentials for the request. Setting them to null will make a
     * request without authentication header.
     */
    public function setCredentials(?CredentialsInterface $creds = null): void
    {
        $this->credentials = $creds;
    }

    /**
     * Set a different content type for this request. The default is text/xml in utf-8.
     */
    public function setContentType(string $contentType): void
    {
        $this->contentType = $contentType;
    }

    /**
     * Set the depth to which nodes should be fetched.
     *
     * To support more than 0, we need to implement more logic in parsing
     * the response too.
     */
    public function setDepth(int $depth): void
    {
        $this->depth = $depth;
    }

    /**
     * Set the request body.
     */
    public function setBody(string $body): void
    {
        $this->body = (string) $body;
    }

    /**
     * Set or update the HTTP method to be used in this request.
     */
    public function setMethod(string $method): void
    {
        $this->method = $method;
    }

    /**
     * @param string|array $uri the request target
     */
    public function setUri($uri): void
    {
        $this->uri = (array) $uri;
    }

    /**
     * Add an additional http header.
     */
    public function addHeader(string $header): void
    {
        $this->additionalHeaders[] = $header;
    }

    /**
     * Add the user data header.
     */
    public function addUserData(string $userData): void
    {
        $userDataHeader = 'Link: <data:,'.urlencode($userData).'>; rel="http://www.day.com/jcr/webdav/1.0/user-data"';
        $this->addHeader($userDataHeader);
    }

    /**
     * Set the transaction lock token to be used with this request.
     */
    public function setLockToken(string $lockToken): void
    {
        $this->lockToken = $lockToken;
    }

    /**
     * used by multiCurl with fresh curl instances.
     *
     * @param bool $getCurlObject whether to return the curl object instead of the response
     */
    private function prepareCurl(curl $curl, bool $getCurlObject): curl
    {
        if ($this->credentials instanceof SimpleCredentials) {
            $curl->setopt(CURLOPT_USERPWD, $this->credentials->getUserID().':'.$this->credentials->getPassword());
        }
        // otherwise leave this alone, the new curl instance has no USERPWD yet

        $headers = [
            'Depth: '.(self::INFINITY === $this->depth ? self::JACKRABBIT_INFINITY : $this->depth),
            'Content-Type: '.$this->contentType,
            'User-Agent: '.self::USER_AGENT,
        ];
        $headers = array_merge($headers, $this->additionalHeaders);

        if ($this->lockToken) {
            $headers[] = 'Lock-Token: <'.$this->lockToken.'>';
        }

        if (self::POST === $this->method) {
            /*
               Jackrabbit's CSRF protection affects any write request that could come from an HTML form
               The simplest possible fix probably is to include a Referer header field (referencing the server itself)
               see https://github.com/jackalope/jackalope-jackrabbit/issues/138
            */
            $headers[] = 'Referer: '.$this->client->getWorkspaceUri();
        }

        foreach ($this->curlOptions as $option => $optionValue) {
            $curl->setopt($option, $optionValue);
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
     * @param bool $getCurlObject whether to return the curl object instead of the response
     * @param bool $forceMultiple whether to force parallel requests or not
     *
     * @return string|curl|array response string or the curl object
     */
    public function execute(bool $getCurlObject = false, bool $forceMultiple = false)
    {
        if (!$forceMultiple && 1 === count($this->uri)) {
            return $this->singleRequest($getCurlObject);
        }

        return $this->multiRequest($getCurlObject);
    }

    /**
     * Requests the data for multiple requests.
     *
     * @param bool $getCurlObject whether to return the curl object instead of the response
     *
     * @return array of XML representations of responses or curl objects
     */
    private function multiRequest(bool $getCurlObject = false): array
    {
        $mh = curl_multi_init();

        $curls = [];
        foreach ($this->uri as $absPath => $uri) {
            $tempCurl = new curl($uri);
            $tempCurl = $this->prepareCurl($tempCurl, $getCurlObject);
            $curls[$absPath] = $tempCurl;
            curl_multi_add_handle($mh, $tempCurl->getCurl());
        }

        $active = null;

        do {
            $mrc = curl_multi_exec($mh, $active);
        } while ($active || CURLM_CALL_MULTI_PERFORM === $mrc);

        while ($active && CURLM_OK === $mrc) {
            if (-1 !== curl_multi_select($mh)) {
                do {
                    $mrc = curl_multi_exec($mh, $active);
                } while (CURLM_CALL_MULTI_PERFORM === $mrc);
            }
        }

        $responses = [];
        foreach ($curls as $key => $curl) {
            $httpCode = $curl->getinfo(CURLINFO_HTTP_CODE);
            if ($httpCode >= 200 && $httpCode < 300) {
                if ($getCurlObject) {
                    $responses[$key] = $curl;
                } else {
                    $responses[$key] = curl_multi_getcontent($curl->getCurl());
                }
            }
            curl_multi_remove_handle($mh, $curl->getCurl());
        }
        curl_multi_close($mh);

        return $responses;
    }

    /**
     * Requests the data for a single requests.
     *
     * @param bool $getCurlObject whether to return the curl object instead of the response
     *
     * @return string|curl XML representation of a response or curl object
     */
    private function singleRequest(bool $getCurlObject)
    {
        if ($this->credentials instanceof SimpleCredentials) {
            $this->curl->setopt(CURLOPT_USERPWD, $this->credentials->getUserID().':'.$this->credentials->getPassword());
            $curl = $this->curl;
        } else {
            // we seem to be unable to remove the Authorization header
            // setting to null produces a bogus Authorization: Basic Og==
            $curl = new curl();
        }

        $headers = [
            'Depth: '.(self::INFINITY === $this->depth ? self::JACKRABBIT_INFINITY : $this->depth),
            'Content-Type: '.$this->contentType,
            'User-Agent: '.self::USER_AGENT,
        ];
        $headers = array_merge($headers, $this->additionalHeaders);

        if ($this->lockToken) {
            $headers[] = 'Lock-Token: <'.$this->lockToken.'>';
        }

        /*
           Jackrabbit's CSRF protection affects any write request that could come from an HTML form
           The simplest possible fix probably is to include a Referer header field (referencing the server itself)
           see https://github.com/jackalope/jackalope-jackrabbit/issues/138
        */
        if ($workspaceUri = $this->client->getWorkspaceUri()) {
            $headers[] = 'Referer: '.$workspaceUri;
        }

        foreach ($this->curlOptions as $option => $optionValue) {
            $curl->setopt($option, $optionValue);
        }

        $curl->setopt(CURLOPT_RETURNTRANSFER, true);
        $curl->setopt(CURLOPT_CUSTOMREQUEST, $this->method);
        $curl->setopt(CURLOPT_URL, reset($this->uri));
        $curl->setopt(CURLOPT_HTTPHEADER, $headers);
        $curl->setopt(CURLOPT_POSTFIELDS, $this->body);

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
        throw new RepositoryException('handleError should always throw an exception');
    }

    /**
     * Handles errors caused by singleRequest and multiRequest.
     *
     * For transport level errors, tries to figure out what went wrong to
     * throw the most appropriate exception.
     *
     * @throws NoSuchWorkspaceException if it was not possible to reach the server (resolve host or connect)
     * @throws ItemNotFoundException    if the object was not found
     * @throws RepositoryException      on any other error
     * @throws PathNotFoundException    if the path was not found (server returned 404 without xml response)
     */
    private function handleError(curl $curl, string $responseBody, int $httpCode): void
    {
        // first: check if the backend is too old for us
        if (!self::$versionChecked) {
            // avoid endless loops.
            self::$versionChecked = true;
            try {
                // getting the descriptors triggers a version check
                $this->client->getRepositoryDescriptors();
            } catch (\Exception $e) {
                if ($e instanceof UnsupportedRepositoryOperationException) {
                    throw $e;
                }
                // otherwise ignore exception here as to not confuse what happened
            }
        }

        switch ($curl->errno()) {
            case CURLE_COULDNT_RESOLVE_HOST:
            case CURLE_COULDNT_CONNECT:
                $info = $curl->getinfo();
                throw new NoSuchWorkspaceException($curl->error().' "'.$info['url'].'"');
            case CURLE_RECV_ERROR:
                throw new RepositoryException(sprintf(
                    'CURLE_RECV_ERROR (errno 56) encountered. This has been known to happen intermittently with '.
                    'some versions of libcurl (see https://github.com/jackalope/jackalope-jackrabbit/issues/89). '.
                    'You can use the "jackalope.jackrabbit_force_http_version_10" option to force HTTP 1.0 as a workaround'
                ));
        }

        // use XML error response if it's there
        if ('<?' === substr($responseBody, 0, 2)) {
            $dom = new \DOMDocument();
            $dom->loadXML($responseBody);
            $err = $dom->getElementsByTagNameNS(Client::NS_DCR, 'exception');
            if ($err->length > 0) {
                $err = $err->item(0);
                $errClass = $err->getElementsByTagNameNS(Client::NS_DCR, 'class')->item(0)->textContent;
                $errMsg = $err->getElementsByTagNameNS(Client::NS_DCR, 'message')->item(0)->textContent;

                $exceptionMsg = 'HTTP '.$httpCode.': '.$errMsg;
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
                        // TODO: Two more errors needed for Transactions. How does the corresponding Jackrabbit response look like?
                        // javax.transaction.RollbackException => \PHPCR\Transaction\RollbackException
                        // java.lang.SecurityException => \PHPCR\AccessDeniedException

                        // TODO: map more errors here?
                    default:
                        // try to generically "guess" the right exception class name
                        $class = substr($errClass, strlen('javax.jcr.'));
                        $class = explode('.', $class);
                        array_walk($class, static function (&$ns) {
                            $ns = ucfirst(str_replace('nodetype', 'NodeType', $ns));
                        });
                        $class = '\\PHPCR\\'.implode('\\', $class);

                        if (class_exists($class)) {
                            throw new $class($exceptionMsg);
                        }
                        throw new RepositoryException($exceptionMsg." ($errClass)");
                }
            }
        }
        if (401 === $httpCode) {
            throw new LoginException("HTTP 401 Unauthorized\n".$this->getShortErrorString());
        }
        if (404 === $httpCode) {
            throw new PathNotFoundException("HTTP 404 Path Not Found: {$this->method} \n".$this->getShortErrorString());
        }
        if (405 === $httpCode) {
            throw new HTTPErrorException("HTTP 405 Method Not Allowed: {$this->method} \n".$this->getShortErrorString(), 405);
        }
        if (412 === $httpCode) {
            throw new LockException("Unable to lock the non-lockable node '".reset($this->uri)."\n".$this->getShortErrorString());
        }
        if ($httpCode >= 500) {
            $msg = "HTTP $httpCode Error from backend on: {$this->method} \n".$this->getLongErrorString($curl, $responseBody);
            try {
                $workspaceUri = [$this->client->getWorkSpaceUri()];
                if (!$this->errorHandlingMode
                    && ($workspaceUri !== $this->uri || self::GET !== $this->method)
                ) {
                    $this->errorHandlingMode = true;
                    $this->setUri($workspaceUri);
                    $this->setMethod(self::GET);
                    $this->executeDom();
                }
            } catch (PathNotFoundException $e) {
                $msg = "Error likely caused by incorrect server URL configuration '".reset($this->uri)."' resulted in:\n$msg";
            }

            $this->errorHandlingMode = false;
            throw new RepositoryException($msg);
        }

        $curlError = $curl->error();

        $msg = "Unexpected error: \nCURL Error: $curlError \nResponse (HTTP $httpCode): {$this->method} \n".$this->getLongErrorString($curl, $responseBody);
        throw new RepositoryException($msg);
    }

    /**
     * returns a shorter error string to be used in exceptions.
     *
     * It returns a "nicely" formatted URI of the request
     */
    private function getShortErrorString(): string
    {
        return "--uri: --\n".var_export($this->uri, true)."\n";
    }

    /**
     * returns a longer error string to be used in generic exceptions.
     *
     * It returns a "nicely" formatted URI of the request
     * plus the output of curl_getinfo
     * plus the response body including its size
     */
    private function getLongErrorString(curl $curl, string $responseBody): string
    {
        $string = $this->getShortErrorString();
        $string .= "--curl getinfo: --\n".var_export($curl->getinfo(), true)."\n";
        $string .= '--request body (size: '.strlen($this->body)." bytes): --\n";
        if (strlen($this->body) > 2000) {
            $string .= substr($this->body, 0, 2000);
            $string .= "\n (truncated)\n";
        } else {
            $string .= $this->body."\n";
        }
        $string .= '--response body (size: '.strlen($responseBody)." bytes): --\n$responseBody\n--end response body--\n";

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
     * @return \DOMDocument the loaded XML response text
     */
    public function executeDom(bool $forceMultiple = false): \DOMDocument
    {
        $xml = $this->execute(false, $forceMultiple);
        // create new DOMDocument and load the response text.
        $dom = new \DOMDocument();
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
     * @return \stdClass|\stdClass[]
     *
     * @throws RepositoryException if the json response is not valid
     */
    public function executeJson(bool $forceMultiple = false)
    {
        $responses = $this->execute(false, $forceMultiple);
        if (!is_array($responses)) {
            $responses = [$responses];
            $reset = true;
        }

        $json = [];
        foreach ($responses as $key => $response) {
            $json[$key] = json_decode($response, false);
            if (null === $json[$key] && 'null' !== strtolower($response)) {
                $uri = reset($this->uri);
                throw new RepositoryException("Not a valid json object: \nRequest: {$this->method} $uri \nResponse: \n$response");
            }
        }
        // TODO: are there error responses in json format? if so, handle them
        if (isset($reset)) {
            return reset($json);
        }

        return $json;
    }
}
