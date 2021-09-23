<?php

namespace Jackalope\Transport\Jackrabbit;

use Jackalope\Factory;
use Jackalope\Test\JackrabbitTestCase;
use PHPCR\SimpleCredentials;
use PHPUnit\Framework\MockObject\MockObject;

class RequestTest extends JackrabbitTestCase
{
    protected function getCurlFixture(string $fixture = null, int $httpCode = 200, int $errno = null): curl
    {
        $curl = $this->createMock(curl::class);

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

    public function getRequest(string $fixture = null, int $httpCode = 200, int $errno = null)
    {
        $factory = new Factory();

        return new RequestMock($factory, $this->getClientMock(), $this->getCurlFixture($fixture, $httpCode, $errno), 'GET', 'http://foo/');
    }

    public function testExecuteDom(): void
    {
        $factory = new Factory();
        $request = $this
                    ->getMockBuilder(Request::class)
                    ->setMethods(['execute'])
                    ->setConstructorArgs([$factory, $this->getClientMock(), $this->getCurlFixture(), 'GET', null])
                    ->getMock();
        $request->expects($this->once())
            ->method('execute')
            ->willReturn('<xml/>');

        $this->assertInstanceOf(\DOMDocument::class, $request->executeDom());
    }

    /**
     * @covers \Jackalope\Transport\Jackrabbit\Request::execute
     */
    public function testPrepareRequestWithCredentials(): void
    {
        $request = $this->getRequest('fixtures/empty.xml');
        $request->setCredentials(new SimpleCredentials('foo', 'bar'));
        $passwordParam = false;
        $passwordCorrect = false;
        $request->getCurl()
            ->method('setopt')
            ->with(
                $this->callback(static function ($name) use (&$passwordParam): bool {
                    if (CURLOPT_USERPWD === $name) {
                        $passwordParam = true;
                    }

                    return true;
                }),
                $this->callback(static function ($value) use (&$passwordCorrect): bool {
                    if ('foo:bar' === $value) {
                        $passwordCorrect = true;
                    }

                    return true;
                })
            )
        ;
        $request->execute();
        $this->assertTrue($passwordParam);
        $this->assertTrue($passwordCorrect);
    }
}

class RequestMock extends Request
{
    /**
     * @return curl|MockObject
     */
    public function getCurl()
    {
        return $this->curl;
    }
}
