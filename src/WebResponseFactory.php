<?php

namespace Yiisoft\Yii\Web;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Http\Status;

class WebResponseFactory implements WebResponseFactoryInterface
{
    protected ResponseFactoryInterface $responseFactory;

    public function __construct(ResponseFactoryInterface $responseFactory)
    {
        $this->responseFactory = $responseFactory;
    }

    public function createResponse($data = null, int $code = Status::OK, string $reasonPhrase = ''): ResponseInterface
    {
        return new WebResponse($data, $code, $this->responseFactory);
    }
}
