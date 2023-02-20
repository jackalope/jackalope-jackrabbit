<?php

namespace Jackalope\Transport\Jackrabbit;

use Jackalope\Factory;
use Jackalope\FactoryInterface;
use Jackalope\Node;
use Jackalope\NodeType\NodeType;
use Jackalope\NodeType\NodeTypeManager;
use Jackalope\ObjectManager;
use Jackalope\Repository;
use Jackalope\Session;
use Jackalope\Test\JackrabbitTestCase;
use Jackalope\Transport\RemoveNodeOperation;
use Jackalope\Workspace;
use PHPCR\CredentialsInterface;
use PHPCR\LoginException;
use PHPCR\NoSuchWorkspaceException;
use PHPCR\RepositoryException;
use PHPCR\RepositoryInterface;
use PHPCR\SimpleCredentials;
use PHPCR\ValueFormatException;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * TODO: this unit test contains some functional tests. we should separate functional and unit tests.
 */
class ClientTest extends JackrabbitTestCase
{
    /**
     * @return MockObject&ClientMock
     */
    private function buildTransportMock($args = 'testuri', $changeMethods = [])
    {
        $factory = new Factory();
        // Array XOR
        $defaultMockMethods = ['getRequest', '__destruct', '__construct'];
        $mockMethods = array_merge(array_diff($defaultMockMethods, $changeMethods), array_diff($changeMethods, $defaultMockMethods));

        return $this
            ->getMockBuilder(ClientMock::class)
            ->setMethods($mockMethods)
            ->setConstructorArgs([$factory, $args])
            ->getMock()
        ;
    }

    /**
     * @param mixed    $response
     * @param string[] $changeMethods
     *
     * @return Request&MockObject
     */
    private function buildRequestMock($response, array $changeMethods)
    {
        $mockMethods = array_unique(array_merge(['execute', 'executeDom', 'executeJson'], $changeMethods));
        $request = $this->getMockBuilder(Request::class)
            ->disableOriginalConstructor()
            ->getMock($mockMethods)
        ;

        $request
            ->method('execute')
            ->willReturn($response)
        ;

        return $request;
    }

    /**
     * @param string[] $changeMethods
     *
     * @return Request&MockObject
     */
    private function buildJsonRequestMock(array $changeMethods = [])
    {
        $response = new \stdClass();
        $request = $this->buildRequestMock($response, $changeMethods);

        $request
            ->expects($this->never())
            ->method('executeDom')
        ;

        $request
            ->method('executeJson')
            ->willReturn($response)
        ;

        return $request;
    }

    /**
     * @param string[] $changeMethods
     *
     * @return Request&MockObject
     */
    private function buildXmlRequestMock(\DOMDocument $response, array $changeMethods = [])
    {
        $request = $this->buildRequestMock($response, $changeMethods);

        $request
            ->method('executeDom')
            ->willReturn($response)
        ;

        $request
            ->expects($this->never())
            ->method('executeJson')
        ;

        return $request;
    }

    /**
     * @covers \Jackalope\Transport\Jackrabbit\Client::__construct
     */
    public function testConstructor(): void
    {
        $factory = new Factory();
        $transport = new ClientMock($factory, 'testuri');
        $this->assertSame('testuri/', $transport->server);
    }

    /**
     * @covers \Jackalope\Transport\Jackrabbit\Client::__destruct
     */
    public function testDestructor(): void
    {
        $factory = new Factory();
        $transport = new ClientMock($factory, 'testuri');
        $transport->__destruct();
        $this->assertFalse($transport->curl);
    }

    /**
     * @covers \Jackalope\Transport\Jackrabbit\Client::getRequest
     */
    public function testGetRequestDoesntReinitCurl(): void
    {
        $t = $this->buildTransportMock();
        $t->curl = 'test';
        $t->getRequestMock('GET', '/foo');
        $this->assertSame('test', $t->curl);
    }

    /**
     * @covers \Jackalope\Transport\Jackrabbit\Client::buildReportRequest
     */
    public function testBuildReportRequest(): void
    {
        $this->assertSame(
            '<?xml version="1.0" encoding="UTF-8"?><foo xmlns:dcr="http://www.day.com/jcr/webdav/1.0"/>',
            $this->buildTransportMock()->buildReportRequestMock('foo')
        );
    }

    /**
     * @covers \Jackalope\Transport\Jackrabbit\Client::getRepositoryDescriptors
     */
    public function testGetRepositoryDescriptorsEmptyBackendResponse(): void
    {
        $dom = new \DOMDocument();
        $dom->load(__DIR__.'/../../../fixtures/empty.xml');
        $t = $this->buildTransportMock();
        $request = $this->buildXmlRequestMock($dom, ['setBody']);
        $t->expects($this->once())
            ->method('getRequest')
            ->willReturn($request);
        $this->expectException(RepositoryException::class);
        $t->getRepositoryDescriptors();
    }

    /**
     * @covers \Jackalope\Transport\Jackrabbit\Client::getRepositoryDescriptors
     */
    public function testGetRepositoryDescriptors(): void
    {
        $reportRequest = $this->buildTransportMock()->buildReportRequestMock('dcr:repositorydescriptors');
        $dom = new \DOMDocument();
        $dom->load(__DIR__.'/../../../fixtures/repositoryDescriptors.xml');
        $t = $this->buildTransportMock();
        $request = $this->buildXmlRequestMock($dom, ['setBody']);
        $t->expects($this->once())
            ->method('getRequest')
            ->with(Request::REPORT, 'testuri/')
            ->willReturn($request);
        $request->expects($this->once())
            ->method('setBody')
            ->with($reportRequest);

        $desc = $t->getRepositoryDescriptors();
        $this->assertIsArray($desc);
        $this->assertIsString($desc['identifier.stability']);
        $this->assertSame('identifier.stability.indefinite.duration', $desc['identifier.stability']);
        $this->assertIsArray($desc['node.type.management.property.types']);
        $this->assertIsString($desc['node.type.management.property.types'][0]);
        $this->assertSame('2', $desc['node.type.management.property.types'][0]);
    }

    /**
     * @covers \Jackalope\Transport\Jackrabbit\Client::getRequest
     */
    public function testExceptionIfNotLoggedIn(): void
    {
        $factory = new Factory();
        $t = new ClientMock($factory, 'http://localhost:1/server');
        $this->expectException(RepositoryException::class);
        $t->getNodeTypes();
    }

    /**
     * @covers \Jackalope\Transport\Jackrabbit\Client::getRepositoryDescriptors
     */
    public function testGetRepositoryDescriptorsNoserver(): void
    {
        $factory = new Factory();
        $t = new Client($factory, 'http://localhost:1/server');
        $this->expectException(RepositoryException::class);
        $t->getRepositoryDescriptors();
    }

    /**
     * @covers \Jackalope\Transport\Jackrabbit\Client::buildPropfindRequest
     */
    public function testBuildPropfindRequestSingle(): void
    {
        $xmlStr = '<?xml version="1.0" encoding="UTF-8"?><D:propfind xmlns:D="DAV:" xmlns:dcr="http://www.day.com/jcr/webdav/1.0"><D:prop>';
        $xmlStr .= '<foo/>';
        $xmlStr .= '</D:prop></D:propfind>';
        $this->assertSame($xmlStr, $this->buildTransportMock()->buildPropfindRequestMock(['foo']));
    }

    /**
     * @covers \Jackalope\Transport\Jackrabbit\Client::buildPropfindRequest
     */
    public function testBuildPropfindRequestArray(): void
    {
        $xmlStr = '<?xml version="1.0" encoding="UTF-8"?><D:propfind xmlns:D="DAV:" xmlns:dcr="http://www.day.com/jcr/webdav/1.0"><D:prop>';
        $xmlStr .= '<foo/><bar/>';
        $xmlStr .= '</D:prop></D:propfind>';
        $this->assertSame($xmlStr, $this->buildTransportMock()->buildPropfindRequestMock(['foo', 'bar']));
    }

    /**
     * @covers \Jackalope\Transport\Jackrabbit\Client::login
     */
    public function testLoginAlreadyLoggedin(): void
    {
        $t = $this->buildTransportMock();
        $t->setCredentials(new SimpleCredentials('test', 'pass'));
        $this->expectException(RepositoryException::class);
        $t->login($this->credentials, $this->config['workspace']);
    }

    /**
     * @covers \Jackalope\Transport\Jackrabbit\Client::login
     */
    public function testLoginUnsportedCredentials(): void
    {
        $t = $this->buildTransportMock();
        $this->expectException(LoginException::class);
        $t->login(new falseCredentialsMock(), $this->config['workspace']);
    }

    /**
     * @covers \Jackalope\Transport\Jackrabbit\Client::login
     */
    public function testLoginEmptyBackendResponse(): void
    {
        $dom = new \DOMDocument();
        $dom->load(__DIR__.'/../../../fixtures/empty.xml');
        $t = $this->buildTransportMock();
        $request = $this->buildXmlRequestMock($dom, ['setBody']);
        $t->expects($this->once())
            ->method('getRequest')
            ->willReturn($request);
        $this->expectException(RepositoryException::class);
        $t->login($this->credentials, 'tests');
    }

    /**
     * @covers \Jackalope\Transport\Jackrabbit\Client::login
     */
    public function testLoginWrongWorkspace(): void
    {
        $dom = new \DOMDocument();
        $dom->load(__DIR__.'/../../../fixtures/wrongWorkspace.xml');
        $t = $this->buildTransportMock();
        $request = $this->buildXmlRequestMock($dom, ['setBody']);
        $t->expects($this->once())
            ->method('getRequest')
            ->willReturn($request);
        $this->expectException(RepositoryException::class);
        $t->login($this->credentials, 'tests');
    }

    /**
     * @covers \Jackalope\Transport\Jackrabbit\Client::login
     */
    public function testLogin(): void
    {
        $propfindRequest = $this->buildTransportMock()->buildPropfindRequestMock(['D:workspace', 'dcr:workspaceName']);
        $dom = new \DOMDocument();
        $dom->load(__DIR__.'/../../../fixtures/loginResponse.xml');
        $t = $this->buildTransportMock();

        $request = $this->buildXmlRequestMock($dom, ['setBody']);
        $t->expects($this->once())
            ->method('getRequest')
            ->with(Request::PROPFIND, 'testuri/tests')
            ->willReturn($request);

        $request->expects($this->once())
            ->method('setBody')
            ->with($propfindRequest);

        $x = $t->login($this->credentials, 'tests');
        $this->assertSame('tests', $x);
        $this->assertSame('tests', $t->workspace);
        $this->assertSame('testuri/tests/jcr:root', $t->workspaceUriRoot);
    }

    /**
     * @covers \Jackalope\Transport\Jackrabbit\Client::login
     */
    public function testLoginNoServer(): void
    {
        $factory = new Factory();
        $t = new Client($factory, 'http://localhost:1/server');
        $this->expectException(NoSuchWorkspaceException::class);
        $t->login($this->credentials, $this->config['workspace']);
    }

    /**
     * @covers \Jackalope\Transport\Jackrabbit\Client::login
     */
    public function testLoginNoSuchWorkspace(): void
    {
        $factory = new Factory();
        $t = new Client($factory, $this->config['url']);
        $this->expectException(NoSuchWorkspaceException::class);
        $t->login($this->credentials, 'not-an-existing-workspace');
    }

    /**
     * @covers \Jackalope\Transport\Jackrabbit\Client::getNode
     */
    public function testGetNodeWithoutAbsPath(): void
    {
        $t = $this->buildTransportMock();
        $this->expectException(RepositoryException::class);
        $t->getNode('foo');
    }

    /**
     * @covers \Jackalope\Transport\Jackrabbit\Client::getNode
     */
    public function testGetNode(): void
    {
        $t = $this->buildTransportMock($this->config['url']);

        $request = $this->buildJsonRequestMock();
        $t->expects($this->once())
            ->method('getRequest')
            ->with(Request::GET, '/foobar.0.json')
            ->willReturn($request)
        ;

        $t->getNode('/foobar');
    }

    /**
     * @covers \Jackalope\Transport\Jackrabbit\Client::buildLocateRequest
     */
    public function testBuildLocateRequestMock(): void
    {
        $xmlstr = '<?xml version="1.0" encoding="UTF-8"?><dcr:locate-by-uuid xmlns:dcr="http://www.day.com/jcr/webdav/1.0"><D:href xmlns:D="DAV:">test</D:href></dcr:locate-by-uuid>';
        $this->assertSame($xmlstr, $this->buildTransportMock()->buildLocateRequestMock('test'));
    }

    /**
     * @covers \Jackalope\Transport\Jackrabbit\Client::getNamespaces
     */
    public function testGetNamespacesEmptyResponse(): void
    {
        $dom = new \DOMDocument();
        $dom->load(__DIR__.'/../../../fixtures/empty.xml');

        $t = $this->buildTransportMock($this->config['url']);
        $request = $this->buildXmlRequestMock($dom, ['setBody']);
        $t->expects($this->once())
            ->method('getRequest')
            ->willReturn($request);

        $this->expectException(RepositoryException::class);
        $t->getNamespaces();
    }

    /**
     * @covers \Jackalope\Transport\Jackrabbit\Client::getNamespaces
     */
    public function testGetNamespaces(): void
    {
        $reportRequest = $this->buildTransportMock()->buildReportRequestMock('dcr:registerednamespaces');
        $dom = new \DOMDocument();
        $dom->load(__DIR__.'/../../../fixtures/registeredNamespaces.xml');

        $t = $this->buildTransportMock($this->config['url']);
        $request = $this->buildXmlRequestMock($dom, ['setBody']);
        $t->expects($this->once())
            ->method('getRequest')
            ->with(Request::REPORT, 'testWorkspaceUri')
            ->willReturn($request);
        $request->expects($this->once())
            ->method('setBody')
            ->with($reportRequest);

        $ns = $t->getNamespaces();
        $this->assertIsArray($ns);
        foreach ($ns as $prefix => $uri) {
            $this->assertIsString($prefix);
            $this->assertIsString($uri);
        }
    }

    /** START TESTING NODE TYPES **/
    protected function setUpNodeTypeMock($params, $fixture)
    {
        $dom = new \DOMDocument();
        $dom->load($fixture);

        $requestStr = $this->buildTransportMock()->buildNodeTypesRequestMock($params);

        $t = $this->buildTransportMock();
        $request = $this->buildXmlRequestMock($dom, ['setBody']);
        $t->expects($this->once())
            ->method('getRequest')
            ->with(Request::REPORT, 'testWorkspaceUriRoot')
            ->willReturn($request);
        $request->expects($this->once())
            ->method('setBody')
            ->with($requestStr);

        return $t;
    }

    /**
     * @covers \Jackalope\Transport\Jackrabbit\Client::buildNodeTypesRequest
     */
    public function testGetAllNodeTypesRequest(): void
    {
        $xmlStr = '<?xml version="1.0" encoding="utf-8" ?><jcr:nodetypes xmlns:jcr="http://www.day.com/jcr/webdav/1.0"><jcr:all-nodetypes/></jcr:nodetypes>';
        $this->assertSame($xmlStr, $this->buildTransportMock()->buildNodeTypesRequestMock([]));
    }

    /**
     * @covers \Jackalope\Transport\Jackrabbit\Client::buildNodeTypesRequest
     */
    public function testSpecificNodeTypesRequest(): void
    {
        $xmlStr = '<?xml version="1.0" encoding="utf-8" ?><jcr:nodetypes xmlns:jcr="http://www.day.com/jcr/webdav/1.0"><jcr:nodetype><jcr:nodetypename>foo</jcr:nodetypename></jcr:nodetype><jcr:nodetype><jcr:nodetypename>bar</jcr:nodetypename></jcr:nodetype><jcr:nodetype><jcr:nodetypename>foobar</jcr:nodetypename></jcr:nodetype></jcr:nodetypes>';
        $this->assertSame($xmlStr, $this->buildTransportMock()->buildNodeTypesRequestMock(['foo', 'bar', 'foobar']));
    }

    /**
     * @covers \Jackalope\Transport\Jackrabbit\Client::getNodeTypes
     */
    public function testGetNodeTypes(): void
    {
        $t = $this->setUpNodeTypeMock([], __DIR__.'/../../../fixtures/nodetypes.xml');

        $nt = $t->getNodeTypes();
        $this->assertIsArray($nt);
        $this->assertSame('mix:created', $nt[0]['name']);
    }

    /**
     * @covers \Jackalope\Transport\Jackrabbit\Client::getNodeTypes
     */
    public function testSpecificGetNodeTypes(): void
    {
        $t = $this->setUpNodeTypeMock(['nt:folder', 'nt:file'], __DIR__.'/../../../fixtures/small_nodetypes.xml');

        $nt = $t->getNodeTypes(['nt:folder', 'nt:file']);
        $this->assertIsArray($nt);
        $this->assertCount(2, $nt);
        $this->assertSame('nt:folder', $nt[0]['name']);
        $this->assertSame('nt:file', $nt[1]['name']);
    }

    /**
     * @covers \Jackalope\Transport\Jackrabbit\Client::getNodeTypes
     */
    public function testEmptyGetNodeTypes(): void
    {
        $t = $this->setUpNodeTypeMock([], __DIR__.'/../../../fixtures/empty.xml');

        $this->expectException('\PHPCR\RepositoryException');
        $nt = $t->getNodeTypes();
    }

    /** END TESTING NODE TYPES **/

    /**
     * @covers \Jackalope\Transport\Jackrabbit\Client::getAccessibleWorkspaceNames
     */
    public function testGetAccessibleWorkspaceNames(): void
    {
        $dom = new \DOMDocument();
        $dom->load(__DIR__.'/../../../fixtures/accessibleWorkspaces.xml');

        $t = $this->buildTransportMock('testuri');
        $request = $this->buildXmlRequestMock($dom, ['setBody', 'setDepth']);
        $t->expects($this->once())
            ->method('getRequest')
            ->with(Request::PROPFIND, 'testuri/')
            ->willReturn($request);
        $request->expects($this->once())
            ->method('setBody')
            ->with($this->buildTransportMock()->buildPropfindRequestMock(['D:workspace']));
        $request->expects($this->once())
            ->method('setDepth')
            ->with(1);

        $names = $t->getAccessibleWorkspaceNames();
        $this->assertSame(['default', 'tests', 'security'], $names);
    }

    /**
     * @covers \Jackalope\Transport\Jackrabbit\Client::addWorkspacePathToUri
     */
    public function testAddWorkspacePathToUri(): void
    {
        $factory = new Factory();
        $transport = new ClientMock($factory, '');

        $this->assertEquals('foo/bar', $transport->addWorkspacePathToUriMock('foo/bar'), 'Relative uri was prepended with workspaceUriRoot');
        $this->assertEquals('testWorkspaceUriRoot/foo/bar', $transport->addWorkspacePathToUriMock('/foo/bar'), 'Absolute uri was not prepended with workspaceUriRoot');
        $this->assertEquals('foo', $transport->addWorkspacePathToUriMock('foo'), 'Relative uri was prepended with workspaceUriRoot');
        $this->assertEquals('testWorkspaceUriRoot/foo', $transport->addWorkspacePathToUriMock('/foo'), 'Absolute uri was not prepended with workspaceUriRoot');
    }

    /**
     * @dataProvider deleteNodesProvider
     */
    public function testDeleteNodes($nodePaths, $expectedJsopString): void
    {
        $t = $this->buildTransportMock();

        $nodes = [];
        foreach ($nodePaths as $nodePath) {
            $node = $this->createMock(RemoveNodeOperation::class);
            $node->srcPath = $nodePath;
            $nodes[] = $node;
        }

        $t->deleteNodes($nodes);

        $jsopBody = $t->getJsopBody();
        $jsopBody[':diff'] = preg_replace('/\s+/', ' ', $jsopBody[':diff']);

        $this->assertEquals($expectedJsopString, $jsopBody[':diff']);
    }

    public function deleteNodesProvider(): array
    {
        return [
            [
                [
                    '/a/b',
                    '/z/y',
                ],
                '-/z/y : -/a/b : ',
            ],
            [
                [
                    '/a/b',
                    '/a/b[2]',
                    '/a/b[3]',
                ],
                '-/a/b[3] : -/a/b[2] : -/a/b : ',
            ],
            [
                [
                    '/a/node[2]/node[3]',
                    '/a/node[2]',
                ],
                '-/a/node[2]/node[3] : -/a/node[2] : ',
            ],
            [
                [
                    '/a/b/c',
                    '/a/b[2]/c',
                    '/a/b[2]/c[2]',
                    '/a/b[2]/c[3]',
                    '/a/b[2]',
                    '/a/b',
                ],
                '-/a/b[2]/c[3] : -/a/b[2]/c[2] : -/a/b[2]/c : -/a/b/c : -/a/b[2] : -/a/b : ',
            ],
        ];
    }

    /**
     * @dataProvider provideTestOutOfRangeCharacters
     */
    public function testOutOfRangeCharacterOccurrence(string $string, ?string $version, bool $isValid): void
    {
        if (!$isValid) {
            $this->expectException(ValueFormatException::class);
            $this->expectExceptionMessage('Invalid character found in property "test". Are you passing a valid string?');
        }

        $t = $this->buildTransportMock();
        $t->setVersion($version);

        $factory = new Factory();
        $session = $this->createMock(Session::class);
        $workspace = $this->createMock(Workspace::class);
        $session
            ->method('getWorkspace')
            ->with()
            ->willReturn($workspace);
        $repository = $this->createMock(RepositoryInterface::class);
        $repository->method('getDescriptor')
            ->willReturnCallback(function ($key) {
                switch ($key) {
                    case Repository::OPTION_TRANSACTIONS_SUPPORTED:
                    case Repository::JACKALOPE_OPTION_STREAM_WRAPPER:
                        return true;
                    case RepositoryInterface::OPTION_LOCKING_SUPPORTED:
                        return false;
                }

                throw new \Exception('todo: '.$key);
            })
        ;
        $session
            ->method('getRepository')
            ->with()
            ->willReturn($repository);
        $ntm = $this->createMock(NodeTypeManager::class);
        $workspace
            ->method('getNodeTypeManager')
            ->with()
            ->willReturn($ntm);
        $nt = $this->createMock(NodeType::class);
        $ntm
            ->method('getNodeType')
            ->with()
            ->willReturn($nt);
        $objectManager = $this->createMock(ObjectManager::class);
        $article = new Node($factory, [], '/jcr:root', $session, $objectManager, true);
        $article->setProperty('test', $string);
        $t->updateProperties($article);
        if ($isValid) {
            $this->addToAssertionCount(1);
        }
    }

    public function provideTestOutOfRangeCharacters(): array
    {
        // use http://rishida.net/tools/conversion/ to convert problematic utf-16 strings to code points
        return [
            ['This is valid too!'.$this->translateCharFromCode('\u0009'), null, true],
            ['This is valid', null, true],
            [$this->translateCharFromCode('\uD7FF'), null, true],
            ['This is on the edge, but valid too.'.$this->translateCharFromCode('\uFFFD'), null, true],
            [$this->translateCharFromCode('\u10000'), null, true],
            [$this->translateCharFromCode('\u10FFFF'), null, true],
            [$this->translateCharFromCode('\u0001'), null, false],
            [$this->translateCharFromCode('\u0002'), null, false],
            [$this->translateCharFromCode('\u0003'), null, false],
            [$this->translateCharFromCode('\u0008'), null, false],
            [$this->translateCharFromCode('\uFFFF'), null, false],
            [$this->translateCharFromCode('Sporty Spice at Sporty spice @ work \uD83D\uDCAA\uD83D\uDCAA\uD83D\uDCAA'), null, false],
            [$this->translateCharFromCode('Sporty Spice at Sporty spice @ work \uD83D\uDCAA\uD83D\uDCAA\uD83D\uDCAA'), '2.8.0', false],
            [$this->translateCharFromCode('Sporty Spice at Sporty spice @ work \uD83D\uDCAA\uD83D\uDCAA\uD83D\uDCAA'), '2.18.1', true],
        ];
    }

    private function translateCharFromCode($char)
    {
        return json_decode('"'.$char.'"');
    }
}

class falseCredentialsMock implements CredentialsInterface
{
}

class ClientMock extends Client
{
    public $curl;
    public string $server = 'testserver';
    public ?string $workspace = 'testWorkspace';
    public ?string $workspaceUri = 'testWorkspaceUri';
    public ?string $workspaceUriRoot = 'testWorkspaceUriRoot';

    /**
     * overwrite client constructor which checks backend version.
     */
    public function __construct(FactoryInterface $factory, string $serverUri)
    {
        $this->factory = $factory;
        // append a slash if not there
        if ('/' !== substr($serverUri, -1)) {
            $serverUri .= '/';
        }
        $this->server = $serverUri;
    }

    public function buildNodeTypesRequestMock(array $params): string
    {
        return $this->buildNodeTypesRequest($params);
    }

    public function buildReportRequestMock(string $name = ''): string
    {
        return $this->buildReportRequest($name);
    }

    public function buildPropfindRequestMock(array $args = []): string
    {
        return $this->buildPropfindRequest($args);
    }

    public function buildLocateRequestMock(string $arg = ''): string
    {
        return $this->buildLocateRequest($arg);
    }

    public function setCredentials(SimpleCredentials $credentials): void
    {
        $this->credentials = $credentials;
    }

    public function getRequestMock(string $method, string $uri): Request
    {
        return $this->getRequest($method, $uri);
    }

    public function addWorkspacePathToUriMock(string $uri): string
    {
        return $this->addWorkspacePathToUri($uri);
    }

    public function getJsopBody(): array
    {
        return $this->jsopBody;
    }
}
