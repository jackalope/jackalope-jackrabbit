<?php

namespace Jackalope\Transport\Jackrabbit;

use Jackalope\Transport\LockingInterface;
use Jackalope\Transport\NodeTypeCndManagementInterface;
use Jackalope\Transport\ObservationInterface;
use Jackalope\Transport\PermissionInterface;
use Jackalope\Transport\QueryInterface as QueryTransport;
use Jackalope\Transport\VersioningInterface;
use Jackalope\Transport\WorkspaceManagementInterface;
use Jackalope\Transport\WritingInterface;

/**
 * Collect all interfaces the jackrabbit client implements and define the additional jackrabbit specific methods.
 *
 * @internal
 */
interface JackrabbitClientInterface extends QueryTransport, PermissionInterface, WritingInterface, VersioningInterface, NodeTypeCndManagementInterface, LockingInterface, ObservationInterface, WorkspaceManagementInterface
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
    public function addDefaultHeader($header);

    /**
     * If you want to send the "Expect: 100-continue" header on larger
     * PUT and POST requests, set this to true.
     *
     * This is a Jackrabbit Davex specific option.
     *
     * @param bool $send Whether to send the header or not
     */
    public function sendExpect($send = true);

    /**
     * Set to true to force HTTP version 1.0.
     *
     * @param bool
     *
     * @deprecated use addCurlOptions([CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_0]) instead
     */
    public function forceHttpVersion10($forceHttpVersion10 = true);

    /**
     * Add global curl-options.
     *
     * This options will be used foreach curl-request.
     *
     * @return array all curl-options
     */
    public function addCurlOptions(array $options);

    /**
     * Return the URL to the workspace determined during login.
     *
     * @return string|null
     */
    public function getWorkspaceUri();

    /**
     * Configure whether to check if we are logged in before doing a request.
     *
     * Will improve error reporting at the cost of some round trips.
     */
    public function setCheckLoginOnServer($bool);

    /**
     * Internal method to fetch event data.
     *
     * @return array hashmap with 'data' containing unfiltered DOM of xml atom
     *               feed of events, 'nextMillis' is the next timestamp if there are
     *               more events to be found, false otherwise
     *
     * @private
     */
    public function fetchEventData($date);

    /**
     * @return mixed null or string
     */
    public function getUserData();
}
