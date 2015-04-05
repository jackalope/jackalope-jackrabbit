<?php

namespace Jackalope;

use PHPCR\ConfigurationException;
use PHPCR\RepositoryFactoryInterface;

/**
 * This factory creates repositories with the jackrabbit transport
 *
 * Use repository factory based on parameters (the parameters below are examples):
 *
 * <pre>
 *    $parameters = array('jackalope.jackrabbit_uri' => 'http://localhost:8080/server/');
 *    $factory = new \Jackalope\RepositoryFactoryJackrabbit;
 *    $repo = $factory->getRepository($parameters);
 * </pre>
 *
 * @license http://www.apache.org/licenses Apache License Version 2.0, January 2004
 * @license http://opensource.org/licenses/MIT MIT License
 *
 * @api
 */
class RepositoryFactoryJackrabbit implements RepositoryFactoryInterface
{
    /**
     * list of required parameters for jackrabbit
     * @var array
     */
    private static $required = array(
        'jackalope.jackrabbit_uri' => 'string (required): Path to the jackrabbit server',
    );

    /**
     * list of optional parameters for jackrabbit
     * @var array
     */
    private static $optional = array(
        'jackalope.factory' => 'string or object: Use a custom factory class for Jackalope objects',
        'jackalope.default_header' => 'string: Set a default header to send on each request to the backend (i.e. for load balancers to identify sessions)',
        'jackalope.jackrabbit_expect' => 'boolean: Send the "Expect: 100-continue" header on larger PUT and POST requests. Disabled by default to avoid issues with proxies and load balancers.',
        'jackalope.check_login_on_server' => 'boolean: if set to empty or false, skip initial check whether repository exists. Enabled by default, disable to gain a few milliseconds off each repository instantiation.',
        'jackalope.disable_stream_wrapper' => 'boolean: if set and not empty, stream wrapper is disabled, otherwise the stream wrapper is enabled and streams are only fetched when reading from for the first time. If your code always uses all binary properties it reads, you can disable this for a small performance gain.',
        'jackalope.logger' => 'Psr\Log\LoggerInterface: Use the LoggingClient to wrap the default transport Client',
        Session::OPTION_AUTO_LASTMODIFIED => 'boolean: Whether to automatically update nodes having mix:lastModified. Defaults to true.',
        'jackalope.jackrabbit_force_http_version_10' => 'boolean: Force HTTP version 1.0, this can in solving problems with curl such as https://github.com/jackalope/jackalope-jackrabbit/issues/89',
    );

    /**
     * Get a repository connected to the jackrabbit backend specified in the
     * parameters.
     *
     * {@inheritDoc}
     *
     * Jackrabbit repositories have no default repository, passing null as
     * parameters will always return null.
     *
     * @api
     */
    public function getRepository(array $parameters = null)
    {
        if (null === $parameters) {
            throw new ConfigurationException('Jackalope-jackrabbit needs parameters');
        }

        // check if we have all required parameters
        if (count(array_diff_key(self::$required, $parameters))) {
            throw new ConfigurationException('A required parameter is missing: ' . implode(', ', array_keys(array_diff_key(self::$required, $parameters))));
        }
        // check if we have any unknown parameters
        if (count(array_diff_key($parameters, self::$required, self::$optional))) {
            throw new ConfigurationException('Additional unknown parameters found: ' . implode(', ', array_keys(array_diff_key($parameters, self::$required, self::$optional))));
        }

        if (isset($parameters['jackalope.factory'])) {
            $factory = $parameters['jackalope.factory'] instanceof FactoryInterface
                ? $parameters['jackalope.factory'] : new $parameters['jackalope.factory'];
        } else {
            $factory = new Jackrabbit\Factory();
        }

        $uri = $parameters['jackalope.jackrabbit_uri'];
        if ('/' !== substr($uri, -1, 1)) {
            $uri .= '/';
        }

        $transport = $factory->get('Transport\Jackrabbit\Client', array($uri));
        if (isset($parameters['jackalope.default_header'])) {
            $transport->addDefaultHeader($parameters['jackalope.default_header']);
        }
        if (isset($parameters['jackalope.jackrabbit_expect'])) {
            $transport->sendExpect($parameters['jackalope.jackrabbit_expect']);
        }
        if (isset($parameters['jackalope.check_login_on_server'])) {
            $transport->setCheckLoginOnServer($parameters['jackalope.check_login_on_server']);
        }
        if (isset($parameters['jackalope.jackrabbit_force_http_version_10'])) {
            $transport->forceHttpVersion10($parameters['jackalope.jackrabbit_force_http_version_10']);
        }
        if (isset($parameters['jackalope.logger'])) {
            $transport = $factory->get('Transport\Jackrabbit\LoggingClient', array($transport, $parameters['jackalope.logger']));
        }

        $options['stream_wrapper'] = empty($parameters['jackalope.disable_stream_wrapper']);
        if (isset($parameters[Session::OPTION_AUTO_LASTMODIFIED])) {
            $options[Session::OPTION_AUTO_LASTMODIFIED] = $parameters[Session::OPTION_AUTO_LASTMODIFIED];
        }

        return new Repository($factory, $transport, $options);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function getConfigurationKeys()
    {
        return array_merge(self::$required, self::$optional);
    }
}
