<?php

use Jackalope\RepositoryFactoryJackrabbit;
use Jackalope\Session;
use Jackalope\Transport\Logging\Psr3Logger;
use PHPCR\SimpleCredentials;
use PHPCR\Test\AbstractLoader;
use Psr\Log\NullLogger;

/**
 * Implementation loader for jackalope-jackrabbit.
 */
class ImplementationLoader extends AbstractLoader
{
    private static $instance;

    private $necessaryConfigValues = ['jackrabbit.uri', 'phpcr.user', 'phpcr.pass', 'phpcr.workspace', 'phpcr.additionalWorkspace', 'phpcr.defaultWorkspace'];

    /**
     * Jackrabbit oak does not support multiple workspaces.
     *
     * @var bool
     */
    private $multiWorkspaceSupported = true;

    protected function __construct()
    {
        // Make sure we have the necessary config
        foreach ($this->necessaryConfigValues as $val) {
            if (!isset($GLOBALS[$val])) {
                exit('Please set '.$val.' in your phpunit.xml.'."\n");
            }
        }

        parent::__construct(RepositoryFactoryJackrabbit::class, $GLOBALS['phpcr.workspace'], $GLOBALS['phpcr.additionalWorkspace']);

        // ensure workspaces exist
        $workspace = $this->getRepository()->login($this->getCredentials())->getWorkspace();
        if (!in_array($GLOBALS['phpcr.workspace'], $workspace->getAccessibleWorkspaceNames(), true)) {
            $workspace->createWorkspace($GLOBALS['phpcr.workspace']);
        }
        if (!$GLOBALS['phpcr.additionalWorkspace']) {
            $this->multiWorkspaceSupported = false;
        } elseif (!in_array($GLOBALS['phpcr.additionalWorkspace'], $workspace->getAccessibleWorkspaceNames(), true)) {
            $workspace->createWorkspace($GLOBALS['phpcr.additionalWorkspace']);
        }

        $this->unsupportedChapters = [
            'PermissionsAndCapabilities',
            'ShareableNodes',
            'AccessControlManagement',
            'LifecycleManagement',
            'RetentionAndHold',
            'Transactions',
        ];
        if (!$this->multiWorkspaceSupported) {
            $this->unsupportedChapters[] = 'WorkspaceManagement';
        }

        $this->unsupportedCases = [
            'Versioning\\SimpleVersionTest',
        ];
        if (!$this->multiWorkspaceSupported) {
            $this->unsupportedCases[] = 'Writing\\CloneMethodsTest';
        }

        $this->unsupportedTests = [
            'Reading\\SessionReadMethodsTest::testImpersonate', // TODO: Check if that's implemented in newer jackrabbit versions.
            'Reading\\SessionNamespaceRemappingTest::testSetNamespacePrefix',
            'Reading\\NodeReadMethodsTest::testGetSharedSetUnreferenced', // TODO: should this be moved to 14_ShareableNodes?
            'Reading\\BinaryReadMethodsTest::testReadEmptyBinaryMultivalue', // bug in jackrabbit import: 0 values loses type
            'Reading\\BinaryReadMethodsTest::testReadSingleBinaryMultivalue', // bug in jackrabbit import: 1 value ignores multivalue

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
        ];
        if (!$this->multiWorkspaceSupported) {
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
        if (!$this->multiWorkspaceSupported) {
            return null;
        }

        return parent::getAdditionalSession($credentials);
    }

    public function getRepositoryFactoryParameters()
    {
        return [
            'jackalope.jackrabbit_uri' => $GLOBALS['jackrabbit.uri'],
            Session::OPTION_AUTO_LASTMODIFIED => false,
            'jackalope.logger' => new Psr3Logger(new NullLogger()),
        ];
    }

    public function getSessionWithLastModified()
    {
        /** @var $session Session */
        $session = $this->getSession();
        $session->setSessionOption(Session::OPTION_AUTO_LASTMODIFIED, true);

        return $session;
    }

    public function getCredentials()
    {
        return new SimpleCredentials($GLOBALS['phpcr.user'], $GLOBALS['phpcr.pass']);
    }

    public function getInvalidCredentials()
    {
        return new SimpleCredentials('nonexistinguser', '');
    }

    public function getRestrictedCredentials()
    {
        return new SimpleCredentials('anonymous', 'abc');
    }

    public function prepareAnonymousLogin()
    {
        return false;
    }

    public function getUserId()
    {
        return $GLOBALS['phpcr.user'];
    }

    public function getFixtureLoader(): JackrabbitFixtureLoader
    {
        return new JackrabbitFixtureLoader(__DIR__.'/../vendor/phpcr/phpcr-api-tests/fixtures/', $GLOBALS['jackrabbit.jar'] ?? null);
    }
}
