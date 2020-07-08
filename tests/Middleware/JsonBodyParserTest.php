<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Web\Tests\Middleware;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Http\Header;
use Yiisoft\Yii\Web\Middleware\JsonBodyParser;

final class JsonBodyParserTest extends TestCase
{
    public function testProcess(): void
    {
        $parser = (new JsonBodyParser());

        $handler = $this->createHandler();
        $parser->process(
            $this->createMockRequest('{"test":"value"}'),
            $handler
        );

        $this->assertSame(['test' => 'value'], $handler->getRequestParsedBody());
    }

    public function testScalarDataType()
    {
        $parser = (new JsonBodyParser());

        $handler = $this->createHandler();
        $parser->process($this->createMockRequest('true'), $handler);

        $this->assertNull($handler->getRequestParsedBody());
    }

    public function testWithoutAssoc(): void
    {
        $object = new \stdClass();
        $object->test = 'value';

        $parser = (new JsonBodyParser(false));

        $handler = $this->createHandler();
        $parser->process(
            $this->createMockRequest(json_encode($object)),
            $handler
        );

        $this->assertEquals($object, $handler->getRequestParsedBody());
    }

    public function testThrownException(): void
    {
        $this->expectException(\JsonException::class);

        $parser = new JsonBodyParser();
        $parser->process(
            $this->createMockRequest('{"test": invalid json}'),
            $this->createHandler()
        );
    }

    public function testWithoutThrownException(): void
    {
        $parser = (new JsonBodyParser(true, 512, JSON_INVALID_UTF8_IGNORE));

        $handler = $this->createHandler();
        $parser->process(
            $this->createMockRequest('{"test": invalid json}'),
            $handler
        );

        $this->assertNull($handler->getRequestParsedBody());
    }

    public function testIgnoreInvalidUTF8(): void
    {
        $parser = (new JsonBodyParser());

        $handler = $this->createHandler();
        $parser->process(
            $this->createMockRequest('{"test":"value","invalid":"' . chr(193) . '"}'),
            $handler
        );

        $this->assertSame(['test' => 'value', 'invalid' => ''], $handler->getRequestParsedBody());
    }

    private function createMockRequest(string $rawBody): ServerRequestInterface
    {
        $body = $this->createMock(StreamInterface::class);

        $body
            ->expects($this->once())
            ->method('getContents')
            ->willReturn($rawBody);

        return new ServerRequest('POST', '/', [Header::CONTENT_TYPE => 'application/json'], $body);
    }

    private function createHandler(): RequestHandlerInterface
    {
        $mockResponse = $this->createMock(ResponseInterface::class);
        return new class($mockResponse) implements RequestHandlerInterface {
            private $requestParsedBody;
            private ResponseInterface $mockResponse;

            public function __construct(ResponseInterface $mockResponse)
            {
                $this->mockResponse = $mockResponse;
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->requestParsedBody = $request->getParsedBody();
                return $this->mockResponse;
            }

            /**
             * @return array|object|null
             */
            public function getRequestParsedBody()
            {
                return $this->requestParsedBody;
            }
        };
    }
}
