<?php

require_once __DIR__.'/../../vendor/phpcr/phpcr-api-tests/inc/AbstractLoader.php';

/**
 * Implementation loader for jackalope-jackrabbit
 */
class ImplementationLoader extends \PHPCR\Test\AbstractLoader
{
    private static $instance = null;

    private $necessaryConfigValues = array('jackrabbit.uri', 'phpcr.user', 'phpcr.pass', 'phpcr.workspace', 'phpcr.additionalWorkspace', 'phpcr.defaultWorkspace');

    /**
     * Jackrabbit oak does not support multiple workspaces
     * @var bool
     */
    private $multiWorkspaceSupported = true;

    protected function __construct()
    {
        // Make sure we have the necessary config
        foreach ($this->necessaryConfigValues as $val) {
            if (! isset($GLOBALS[$val])) {
                die('Please set '.$val.' in your phpunit.xml.' . "\n");
            }
        }

        parent::__construct('Jackalope\RepositoryFactoryJackrabbit', $GLOBALS['phpcr.workspace'], $GLOBALS['phpcr.additionalWorkspace']);

        // ensure workspaces exist
        $workspace = $this->getRepository()->login($this->getCredentials())->getWorkspace();
        if (! in_array($GLOBALS['phpcr.workspace'], $workspace->getAccessibleWorkspaceNames())) {
            $workspace->createWorkspace($GLOBALS['phpcr.workspace']);
        }
        if (false == $GLOBALS['phpcr.additionalWorkspace']) {
            $this->multiWorkspaceSupported = false;
        } elseif (! in_array($GLOBALS['phpcr.additionalWorkspace'], $workspace->getAccessibleWorkspaceNames())) {
            $workspace->createWorkspace($GLOBALS['phpcr.additionalWorkspace']);
        }

        $this->unsupportedChapters = array(
            'PermissionsAndCapabilities',
            'ShareableNodes',
            'AccessControlManagement',
            'LifecycleManagement',
            'RetentionAndHold',
            'Transactions',
        );
        if (! $this->multiWorkspaceSupported) {
            $this->unsupportedChapters[] = 'WorkspaceManagement';
        }

        $this->unsupportedCases = array(
        );
        if (! $this->multiWorkspaceSupported) {
            $this->unsupportedCases[] = 'Writing\\CloneMethodsTest';
        }

        $this->unsupportedTests = array(
            'Reading\\SessionReadMethodsTest::testImpersonate', //TODO: Check if that's implemented in newer jackrabbit versions.
            'Reading\\SessionNamespaceRemappingTest::testSetNamespacePrefix',
            'Reading\\NodeReadMethodsTest::testGetSharedSetUnreferenced', // TODO: should this be moved to 14_ShareableNodes?

            'Query\\QueryManagerTest::testGetQuery',
            'Query\\QueryManagerTest::testGetQueryInvalid',
            'Query\\QueryObjectSql2Test::testGetStoredQueryPath',
            // this seems a bug in php with arrayiterator - and jackalope is using
            // arrayiterator for the search result
            // https://github.com/phpcr/phpcr-api-tests/issues/22
            'Query\\NodeViewTest::testSeekable',
            'Query\\CharacterTest::testPropertyWithQuotes',

            'Writing\\SetPropertyMethodsTest::testSetPropertyNewExistingNode', // see http://www.mail-archive.com/dev@jackrabbit.apache.org/msg28035.html
            'Writing\\NamespaceRegistryTest::testRegisterUnregisterNamespace',
            'Writing\\CopyMethodsTest::testCopyUpdateOnCopy',
            'Writing\\CombinedManipulationsTest::testRemoveAndAddAndRemoveToplevelNode', // jackrabbit bug specific to top level node https://issues.apache.org/jira/browse/JCR-3508

            'WorkspaceManagement\\WorkspaceManagementTest::testCreateWorkspaceWithSource',
            'WorkspaceManagement\\WorkspaceManagementTest::testCreateWorkspaceWithInvalidSource',
            'WorkspaceManagement\\WorkspaceManagementTest::testDeleteWorkspace',
        );
        if (! $this->multiWorkspaceSupported) {
            $this->unsupportedTests[] = 'Connecting\\RepositoryTest::testLoginNoSuchWorkspace';
            $this->unsupportedTests[] = 'Writing\\CopyMethodsTest::testCopyNoSuchWorkspace';
        }
    }

    public static function getInstance()
    {
        if (null === self::$instance) {
            self::$instance = new ImplementationLoader();
        }

        return self::$instance;
    }

    public function getDefaultWorkspaceName()
    {
        return $GLOBALS['phpcr.defaultWorkspace'];
    }

    public function getAdditionalSession($credentials = false)
    {
        if (! $this->multiWorkspaceSupported) {
            return null;
        }

        return parent::getAdditionalSession($credentials);
    }
    public function getRepositoryFactoryParameters()
    {
        return array('jackalope.jackrabbit_uri' => $GLOBALS['jackrabbit.uri']);
    }

    public function getCredentials()
    {
        return new \PHPCR\SimpleCredentials($GLOBALS['phpcr.user'], $GLOBALS['phpcr.pass']);
    }

    public function getInvalidCredentials()
    {
        return new \PHPCR\SimpleCredentials('nonexistinguser', '');
    }

    public function getRestrictedCredentials()
    {
        return new \PHPCR\SimpleCredentials('anonymous', 'abc');
    }

    public function prepareAnonymousLogin()
    {
        return false;
    }

    public function getUserId()
    {
        return $GLOBALS['phpcr.user'];
    }

    public function getFixtureLoader()
    {
        require_once 'JackrabbitFixtureLoader.php';

        return new JackrabbitFixtureLoader(__DIR__.'/../../vendor/phpcr/phpcr-api-tests/fixtures/', (isset($GLOBALS['jackrabbit.jar']) ? $GLOBALS['jackrabbit.jar'] : null));
    }
}
