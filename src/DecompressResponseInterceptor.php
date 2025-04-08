<?php

declare(strict_types=1);

namespace Amp\Http\Client\GuzzleAdapter;

use Amp\ByteStream\Compression\DecompressingReadableStream;
use Amp\Cancellation;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Http\Client\Connection\Stream;
use Amp\Http\Client\NetworkInterceptor;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;

final class DecompressResponseInterceptor implements NetworkInterceptor
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct()
    {
        if (!\extension_loaded('zlib')) {
            throw new \RuntimeException(\sprintf('You cannot use the "%s" as the "zlib" extension is not installed.', __CLASS__));
        }
    }

    public function requestViaNetwork(Request $request, Cancellation $cancellation, Stream $stream): Response
    {
        $request->interceptPush(function (Request $request, Response $response): Response {
            return $this->decompressResponse($response);
        });

        return $this->decompressResponse($stream->request($request, $cancellation));
    }

    private function decompressResponse(Response $response): Response
    {
        $encoding = match (\strtolower(\trim((string) $response->getHeader('content-encoding')))) {
            'gzip' => \ZLIB_ENCODING_GZIP,
            'deflate' => \ZLIB_ENCODING_DEFLATE,
            default => 0,
        };

        if ($encoding !== 0) {
            $response->setBody(new DecompressingReadableStream($response->getBody(), $encoding));
            $response->removeHeader('content-encoding');
        }

        return $response;
    }
}
