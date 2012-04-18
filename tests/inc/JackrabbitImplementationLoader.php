<?php

require_once __DIR__.'/../../vendor/phpcr/phpcr-api-tests/inc/AbstractLoader.php';

/**
 * Implementation loader for jackalope-jackrabbit
 */
class ImplementationLoader extends \PHPCR\Test\AbstractLoader
{
    private static $instance = null;

    private $necessaryConfigValues = array('jackrabbit.uri', 'phpcr.user', 'phpcr.pass', 'phpcr.workspace');

    protected function __construct()
    {
        // Make sure we have the necessary config
        foreach ($this->necessaryConfigValues as $val) {
            if (empty($GLOBALS[$val])) {
                die('Please set '.$val.' in your phpunit.xml.' . "\n");
            }
        }
        parent::__construct('Jackalope\RepositoryFactoryJackrabbit', $GLOBALS['phpcr.workspace']);

        $this->unsupportedChapters = array(
            'PermissionsAndCapabilities',
            'ShareableNodes',
            'AccessControlManagement',
            'LifecycleManagement',
            'RetentionAndHold',
            'SameNameSiblings',
            'Transactions'
        );

        $this->unsupportedCases = array(
        );

        $this->unsupportedTests = array(
            'Connecting\\RepositoryTest::testLoginException', //TODO: figure out what would be invalid credentials
            'Connecting\\RepositoryTest::testNoLogin',
            'Connecting\\RepositoryTest::testNoLoginAndWorkspace',

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

            'Writing\\SetPropertyMethodsTest::testSetPropertyNewExistingNode', // see http://www.mail-archive.com/dev@jackrabbit.apache.org/msg28035.html
            'Writing\\NamespaceRegistryTest::testRegisterUnregisterNamespace',
            'Writing\\CopyMethodsTest::testCopyUpdateOnCopy',
            'Writing\\MoveMethodsTest::testSessionDeleteMoved', // TODO: enable and look at the exception you get as starting point
            'Writing\\MoveMethodsTest::testSessionMoveReplace',
            'Writing\\CombinedManipulationsTest::testAddAndChildAddAndMove',

            'WorkspaceManagement\\WorkspaceManagementTest::testCreateWorkspaceWithSource',
            'WorkspaceManagement\\WorkspaceManagementTest::testCreateWorkspaceWithInvalidSource',
            'WorkspaceManagement\\WorkspaceManagementTest::testDeleteWorkspace',

            // Implemented bug jackrabbit bug prevents saving https://issues.apache.org/jira/browse/JCR-3279
            'Import\\ImportRepositoryContentTest::testImportXMLUuidRemoveExistingSession',
            'Import\\ImportRepositoryContentTest::testImportXMLUuidRemoveExistingWorkspace',
            'Import\\ImportRepositoryContentTest::testImportXMLUuidReplaceExistingSession',
            'Import\\ImportRepositoryContentTest::testImportXMLUuidReplaceExistingWorkspace',
        );
    }

    public static function getInstance()
    {
        if (null === self::$instance) {
            self::$instance = new ImplementationLoader();
        }
        return self::$instance;
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

    public function getUserId()
    {
        return $GLOBALS['phpcr.user'];
    }

    function getFixtureLoader()
    {
        require_once "JackrabbitFixtureLoader.php";
        return new JackrabbitFixtureLoader(__DIR__.'/../../vendor/phpcr/phpcr-api-tests/fixtures/', (isset($GLOBALS['jackrabbit.jar']) ? $GLOBALS['jackrabbit.jar'] : null));
    }
}
