<?php

namespace Jackalope\Transport\Jackrabbit;

use Jackalope\Factory;

use DOMDocument;

class RequestTest extends JackrabbitTestCase
{
    protected function getCurlFixture($fixture = null, $httpCode = 200, $errno = null)
    {
        $curl =  $this->getMock('Jackalope\\Transport\\Jackrabbit\\curl', array('exec', 'getinfo', 'errno', 'setopt'));

        if ($fixture) {
            if (is_file($fixture)) {
                $fixture = file_get_contents($fixture);
            }
            $curl
                ->expects($this->any())
                ->method('exec')
                ->will($this->returnValue($fixture));

            $curl
                ->expects($this->any())
                ->method('getinfo')
                ->with($this->equalTo(CURLINFO_HTTP_CODE))
                ->will($this->returnValue($httpCode));
        }

        if (null !== $errno) {
            $curl
                ->expects($this->any())
                ->method('errno')
                ->will($this->returnValue($errno));
        }

        return $curl;
    }

    public function getClientMock()
    {
        return $this->getMockBuilder('Jackalope\\Transport\\Jackrabbit\\Client')
            ->disableOriginalConstructor()
            ->getMock()
        ;
    }

    public function getRequest($fixture = null, $httpCode = 200, $errno = null)
    {
        $factory = new Factory;

        return new RequestMock($factory, $this->getClientMock(), $this->getCurlFixture($fixture, $httpCode, $errno), 'GET', 'http://foo/');
    }

    public function testExecuteDom()
    {
        $factory = new Factory;
        $request = $this->getMock('Jackalope\\Transport\\Jackrabbit\\Request', array('execute'), array($factory, $this->getClientMock(), $this->getCurlFixture(),null, null));
        $request->expects($this->once())
            ->method('execute')
            ->will($this->returnValue('<xml/>'));

        $this->assertInstanceOf('DOMDocument', $request->executeDom());
    }

    /**
     * @covers \Jackalope\Transport\Jackrabbit\Request::execute
     */
    public function testPrepareRequestWithCredentials()
    {
        $request = $this->getRequest('fixtures/empty.xml');
        $request->setCredentials(new \PHPCR\SimpleCredentials('foo', 'bar'));
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
