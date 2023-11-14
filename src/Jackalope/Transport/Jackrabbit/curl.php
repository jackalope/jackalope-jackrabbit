<?php

namespace Jackalope\Transport\Jackrabbit;

/**
 * Capsulate curl as an object.
 *
 * Wrapper class to abstract the curl* PHP userland functions.
 *
 * @todo: TODO: Write phpunit tests
 *
 * @license http://www.apache.org/licenses Apache License Version 2.0, January 2004
 * @license http://opensource.org/licenses/MIT MIT License
 */
class curl
{
    /**
     * Contains a connection resource to a curl session.
     *
     * @var resource
     */
    private $curl;

    /**
     * Contains header of a response, if needed.
     */
    private array $headers = [];

    /**
     * Response body as a string.
     */
    private string $response = '';

    /**
     * Handles the initialization of a curl session.
     *
     * @param string|null $url If provided, sets the CURLOPT_URL
     *
     * @see curl_init
     */
    public function __construct(string $url = null)
    {
        $this->curl = curl_init($url);
        $this->setopt(CURLINFO_HEADER_OUT, true);
    }

    /**
     * Sets the options to be used for the request.
     *
     * @see curl_setopt
     */
    public function setopt(int $option, $value): bool
    {
        return curl_setopt($this->curl, $option, $value);
    }

    /**
     * Sets multiple options to be used for a request.
     *
     * @see curl_setopt_array
     */
    public function setopt_array(array $options): bool
    {
        return curl_setopt_array($this->curl, $options);
    }

    /**
     * Performs a cUrl session.
     *
     * @return bool|string false on failure otherwise true or string (if CURLOPT_RETURNTRANSFER option is set)
     *
     * @see curl_exec
     */
    public function exec()
    {
        return curl_exec($this->curl);
    }

    /**
     * Gets the last error for the current cUrl session.
     *
     * @see curl_error
     */
    public function error(): string
    {
        return curl_error($this->curl);
    }

    /**
     * Gets the number representation of the last error for the current cUrl session.
     *
     * @see curl_errno
     */
    public function errno(): int
    {
        return curl_errno($this->curl);
    }

    /**
     * Gets information regarding a specific transfer.
     *
     * @param int|null $option {@link http://ch.php.net/manual/en/function.curl-getinfo.php} to find a list of possible options.
     *
     * @return string|array Returns a string if options is given otherwise associative array
     *
     * @see curl_getinfo
     */
    public function getinfo(int $option = null)
    {
        if (null === $option) {
            return curl_getinfo($this->curl);
        }

        return curl_getinfo($this->curl, $option);
    }

    /**
     * Closes the current cUrl session.
     *
     * @see curl_close
     */
    public function close(): void
    {
        // This test is to avoid "not a valid cURL handle resource" warnings
        if (is_resource($this->curl)) {
            curl_close($this->curl);
        }
    }

    public function readHeader($ch, $header): int
    {
        if (false !== strpos($header, ':')) {
            list($key, $value) = explode(':', $header, 2);
            $this->headers[$key] = trim($value);
        }

        return strlen($header);
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHeader(string $key): ?string
    {
        if (isset($this->headers[$key])) {
            return $this->headers[$key];
        }

        return null;
    }

    public function parseResponseHeaders(): void
    {
        $this->setopt(CURLOPT_HEADER, false);
        $this->setopt(CURLOPT_HEADERFUNCTION, [&$this, 'readHeader']);
    }

    public function setResponse(string $r): void
    {
        $this->response = $r;
    }

    public function getResponse(): string
    {
        return $this->response;
    }

    public function getCurl()
    {
        return $this->curl;
    }
}
