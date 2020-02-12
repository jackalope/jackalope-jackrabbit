<?php

namespace Jackalope\Transport\Jackrabbit;

use Jackalope\Factory;
use Jackalope\Test\JackrabbitTestCase;
use DOMDocument;
use Jackalope\Transport\Jackrabbit\Client;
use Jackalope\Transport\Jackrabbit\Request;
use PHPCR\SimpleCredentials;

class RequestTest extends JackrabbitTestCase
{
    protected function getCurlFixture($fixture = null, $httpCode = 200, $errno = null)
    {
        $curl =  $this
                    ->getMockBuilder('Jackalope\\Transport\\Jackrabbit\\curl')
                    ->setMethods(array('exec', 'getinfo', 'errno', 'setopt'))
                    ->getMock();

        if ($fixture) {
            if (is_file($fixture)) {
                $fixture = file_get_contents($fixture);
            }
            $curl
                ->method('exec')
                ->willReturn($fixture);

            $curl
                ->method('getinfo')
                ->with($this->equalTo(CURLINFO_HTTP_CODE))
                ->willReturn($httpCode);
        }

        if (null !== $errno) {
            $curl
                ->method('errno')
                ->willReturn($errno);
        }

        return $curl;
    }

    public function getClientMock()
    {
        return $this->createMock(Client::class);
    }

    public function getRequest($fixture = null, $httpCode = 200, $errno = null)
    {
        $factory = new Factory;

        return new RequestMock($factory, $this->getClientMock(), $this->getCurlFixture($fixture, $httpCode, $errno), 'GET', 'http://foo/');
    }

    public function testExecuteDom()
    {
        $factory = new Factory;
        $request = $this
                    ->getMockBuilder(Request::class)
                    ->setMethods(array('execute'))
                    ->setConstructorArgs(array($factory, $this->getClientMock(), $this->getCurlFixture(), null,null))
                    ->getMock();
        $request->expects($this->once())
            ->method('execute')
            ->willReturn('<xml/>');

        $this->assertInstanceOf(DOMDocument::class, $request->executeDom());
    }

    /**
     * @covers \Jackalope\Transport\Jackrabbit\Request::execute
     */
    public function testPrepareRequestWithCredentials()
    {
        $request = $this->getRequest('fixtures/empty.xml');
        $request->setCredentials(new SimpleCredentials('foo', 'bar'));
        $request->getCurl()->expects($this->at(0))
            ->method('setopt')
            ->with(CURLOPT_USERPWD, 'foo:bar');
        $request->execute();
    }
}

class RequestMock extends Request
{
    public function getCurl()
    {
        return $this->curl;
    }
}
