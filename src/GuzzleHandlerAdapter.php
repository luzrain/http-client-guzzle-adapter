<?php

declare(strict_types=1);

namespace Amp\Http\Client\GuzzleAdapter;

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\ReadableResourceStream;
use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\StreamException;
use Amp\ByteStream\WritableResourceStream;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Dns\DnsException;
use Amp\Http\Client\Psr7\PsrAdapter;
use Amp\Http\Client\Psr7\PsrHttpClientException;
use Amp\Http\Client\Response;
use Amp\Http\Client\TimeoutException;
use Amp\Socket\SocketConnector;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\RequestFactoryInterface as PsrRequestFactory;
use Psr\Http\Message\RequestInterface as PsrRequest;
use Psr\Http\Message\ResponseFactoryInterface as PsrResponseFactory;
use Psr\Http\Message\ResponseInterface as PsrResponse;
use Psr\Http\Message\StreamInterface as PsrStream;
use function Amp\async;
use function Amp\ByteStream\pipe;
use function Amp\delay;
use Amp\Socket;

/**
 * Handler for guzzle which uses amphp/http-client.
 */
final class GuzzleHandlerAdapter
{
    private readonly HttpClientBuilder $httpClientBuilder;

    /** @var \WeakMap<PsrStream, DeferredCancellation> */
    private \WeakMap $deferredCancellations;

    private PsrAdapter $psrAdapter;

    public function __construct(?SocketConnector $connector = null)
    {
        if (!\interface_exists(PromiseInterface::class)) {
            throw new \RuntimeException("Please require guzzlehttp/guzzle to use the Guzzle adapter!");
        }

        $this->httpClientBuilder = new HttpClientBuilder($connector ?? Socket\socketConnector());

        /** @var \WeakMap<PsrStream, DeferredCancellation> */
        $this->deferredCancellations = new \WeakMap();

        $this->psrAdapter = new PsrAdapter(
            new class implements PsrRequestFactory {
                public function createRequest(string $method, $uri): GuzzleRequest
                {
                    return new GuzzleRequest($method, $uri);
                }
            },
            new class implements PsrResponseFactory {
                public function createResponse(int $code = 200, string $reasonPhrase = ''): GuzzleResponse
                {
                    return new GuzzleResponse($code, reason: $reasonPhrase);
                }
            },
        );
    }

    public function __invoke(PsrRequest $request, array $options): PromiseInterface
    {
        if (isset($options['curl'])) {
            throw new \RuntimeException("Cannot provide curl options when using AMP backend!");
        }

        $deferredCancellation = new DeferredCancellation();
        $cancellation = $deferredCancellation->getCancellation();
        $future = async(function () use ($request, $options, $cancellation): PsrResponse {
            if (isset($options[RequestOptions::DELAY])) {
                delay($options[RequestOptions::DELAY] / 1000.0, cancellation: $cancellation);
            }

            $ampRequest = $this->psrAdapter->fromPsrRequest($request);
            $ampRequest->setTransferTimeout((float) ($options[RequestOptions::TIMEOUT] ?? 0));
            $ampRequest->setInactivityTimeout((float) ($options[RequestOptions::TIMEOUT] ?? 0));
            $ampRequest->setTcpConnectTimeout((float) ($options[RequestOptions::CONNECT_TIMEOUT] ?? 60));

            $client = $this->httpClientBuilder->getClient($ampRequest, $options);

            if (isset($options['amp']['protocols'])) {
                $ampRequest->setProtocolVersions($options['amp']['protocols']);
            }

            $response = $client->request($ampRequest, $cancellation);

            if (isset($options[RequestOptions::SINK])) {
                $file = $options[RequestOptions::SINK];
                if (!\is_string($file) && !(\is_resource($file) && \get_resource_type($file) === 'stream') && !$file instanceof WritableResourceStream) {
                    throw new \RuntimeException("Only file name, resource or WritableResourceStream can be provided as sink!");
                }

                try {
                    $fileStream = $this->pipeResponseToFile($response, $file, $cancellation);
                } catch (ClosedException|StreamException $exception) {
                    throw new PsrHttpClientException(\sprintf(
                        'Failed streaming body to file "%s": %s',
                        $file,
                        $exception->getMessage(),
                    ), request: $request, previous: $exception);
                }

                $response->setBody($fileStream);
            }

            return $this->psrAdapter->toPsrResponse($response);
        });

        $future->ignore();

        /** @psalm-suppress UndefinedVariable Using $promise reference in definition expression. */
        $promise = new Promise(
            function () use (&$promise, $future, $cancellation, $deferredCancellation, $request): void {
                if ($deferredCancellation->isCancelled()) {
                    return;
                }

                try {
                    /** @var PsrResponse $response */
                    $response = $future->await();

                    // Prevent destruction of the DeferredCancellation until the response body is destroyed.
                    $this->deferredCancellations[$response->getBody()] = $deferredCancellation;

                    $promise->resolve($response);
                } catch (CancelledException $e) {
                    if (!$cancellation->isRequested()) {
                        $promise->reject($e);
                    }
                } catch (\Throwable $e) {
                    if ($e instanceof TimeoutException) {
                        $e = new ConnectException($e->getMessage(), $request, $e);
                    } elseif ($e->getPrevious()?->getPrevious() instanceof DnsException) {
                        // Wrap DNS resolution exception to ConnectException
                        $e = new ConnectException($e->getPrevious()->getMessage(), $request, $e);
                    } else {
                        $e = RequestException::wrapException($request, $e);
                    }

                    $promise->reject($e);
                }
            },
            $deferredCancellation->cancel(...),
        );

        return $promise;
    }

    /**
     * @param string|resource|WritableResourceStream $file
     * @throws ClosedException
     * @throws StreamException
     */
    private function pipeResponseToFile(Response $response, mixed $file, Cancellation $cancellation): ReadableStream
    {
        $fileResource = match (true) {
            \is_string($file) => new WritableResourceStream(\fopen($file, 'w+be')),
            \is_resource($file) => new WritableResourceStream($file),
            default => $file,
        };

        pipe($response->getBody(), $fileResource, $cancellation);
        $resource = $fileResource->getResource();
        \rewind($resource);

        return new ReadableResourceStream($resource);
    }
}
