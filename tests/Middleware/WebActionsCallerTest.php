<?php

namespace Yiisoft\Yii\Web\Middleware;

use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Di\Container;

class WebActionsCallerTest extends TestCase
{
    /** @var ServerRequestInterface  */
    private $request;

    /** @var RequestHandlerInterface  */
    private $handler;

    /** @var ContainerInterface  */
    private $container;

    protected function setUp(): void
    {
        $this->request = $this->createMock(ServerRequestInterface::class);
        $this->handler = $this->createMock(RequestHandlerInterface::class);
        $this->container = new Container([self::class => $this]);
    }

    public function testProcess(): void
    {
        $this->request
            ->method('getAttribute')
            ->with($this->equalTo('action'))
            ->willReturn('process');

        $response = (new WebActionsCaller(self::class, $this->container))->process($this->request, $this->handler);
        $this->assertEquals(204, $response->getStatusCode());
    }

    public function testExceptionOnNullAction(): void
    {
        $this->request
            ->method('getAttribute')
            ->with($this->equalTo('action'))
            ->willReturn(null);

        $this->expectException(\RuntimeException::class);
        (new WebActionsCaller(self::class, $this->container))->process($this->request, $this->handler);
    }

    public function testHandlerInvocation(): void
    {
        $this->request
            ->method('getAttribute')
            ->with($this->equalTo('action'))
            ->willReturn('notExistant');

        $this->handler
            ->expects($this->once())
            ->method('handle');

        (new WebActionsCaller(self::class, $this->container))->process($this->request, $this->handler);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->assertSame($this->request, $request);
        $this->assertSame($this->handler, $handler);

        return new Response(204);
    }
}
