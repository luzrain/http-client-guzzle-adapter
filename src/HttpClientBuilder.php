<?php

declare(strict_types=1);

namespace Amp\Http\Client\GuzzleAdapter;

use Amp\Dns\DnsRecord;
use Amp\Http\Client\ApplicationInterceptor;
use Amp\Http\Client\Connection\DefaultConnectionFactory;
use Amp\Http\Client\Connection\UnlimitedConnectionPool;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\InterceptedHttpClient;
use Amp\Http\Client\PooledHttpClient;
use Amp\Http\Client\Request as AmpRequest;
use Amp\Http\Tunnel\Http1TunnelConnector;
use Amp\Http\Tunnel\Https1TunnelConnector;
use Amp\Socket;
use Amp\Socket\Certificate;
use Amp\Socket\ClientTlsContext;
use Amp\Socket\ConnectContext;
use Amp\Socket\SocketConnector;
use Amp\Socket\Socks5SocketConnector;
use GuzzleHttp\Psr7\Uri as GuzzleUri;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Utils;

final class HttpClientBuilder
{
    /** @var array<string, DelegateHttpClient> */
    private array $cachedClients = [];

    /**
     * @param array<ApplicationInterceptor> $interceptors
     */
    public function __construct(private SocketConnector|null $connector = null, private array $interceptors = [])
    {
        $this->connector ??= Socket\socketConnector();
    }

    public function getClient(AmpRequest $request, array $options): DelegateHttpClient
    {
        $requestScheme = $request->getUri()->getScheme();
        $requestHost = $request->getUri()->getHost();
        $proxyOption = $options[RequestOptions::PROXY] ?? null;

        if (\is_string($proxyOption)) {
            $proxy = $proxyOption;
        } elseif (\is_array($proxyOption) && isset($proxyOption[$requestScheme]) && (!isset($proxyOption['no']) || !Utils::isHostInNoProxy($requestHost, $proxyOption['no']))) {
            $proxy = $proxyOption[$requestScheme];
        } else {
            $proxy = null;
        }

        $cacheKey = $this->createKeyFromOptions($options, $proxy);

        if (isset($this->cachedClients[$cacheKey])) {
            return $this->cachedClients[$cacheKey];
        }

        $connectContext = (new ConnectContext())->withTlsContext($this->getTlsContext($options));

        if (isset($options[RequestOptions::FORCE_IP_RESOLVE])) {
            $connectContext->withDnsTypeRestriction(match ($options[RequestOptions::FORCE_IP_RESOLVE]) {
                'v4' => DnsRecord::A,
                'v6' => DnsRecord::AAAA,
                default => throw new \ValueError(\sprintf('Invalid value for request option "%s": %s', RequestOptions::FORCE_IP_RESOLVE, $options[RequestOptions::FORCE_IP_RESOLVE])),
            });
        }

        $client = new PooledHttpClient(
            connectionPool: new UnlimitedConnectionPool(
                connectionFactory: new DefaultConnectionFactory(
                    connector: $proxy === null ? $this->connector : $this->getProxyConnector($this->connector, $proxy),
                    connectContext: $connectContext,
                )
            )
        );

        if (isset($options[RequestOptions::DECODE_CONTENT]) && $options[RequestOptions::DECODE_CONTENT] !== false) {
            $client = $client->intercept(new DecompressResponseInterceptor());
        }

        foreach (\array_reverse($this->interceptors) as $applicationInterceptor) {
            $client = new InterceptedHttpClient($client, $applicationInterceptor, []);
        }

        $this->cachedClients[$cacheKey] = $client;

        return $client;
    }

    private function createKeyFromOptions(array $options, string|null $proxy): string
    {
        if (isset($options[RequestOptions::CERT])
            || isset($options[RequestOptions::PROXY])
            || (isset($options[RequestOptions::VERIFY]) && $options[RequestOptions::VERIFY] !== true)
            || (isset($options[RequestOptions::DECODE_CONTENT]) && $options[RequestOptions::DECODE_CONTENT] !== true)
            || isset($options[RequestOptions::FORCE_IP_RESOLVE])
        ) {
            $cacheKey = ['proxy_uri' => $proxy];
            foreach ([
                RequestOptions::CERT,
                RequestOptions::PROXY,
                RequestOptions::VERIFY,
                RequestOptions::DECODE_CONTENT,
                RequestOptions::FORCE_IP_RESOLVE,
            ] as $k) {
                $cacheKey[$k] = $options[$k] ?? null;
            }

            return \hash('xxh3', \json_encode($cacheKey));
        }

        return '0000000000000000';
    }

    private function getProxyConnector(SocketConnector $connector, string $proxy): SocketConnector
    {
        $uri = new GuzzleUri($proxy);
        $scheme = $uri->getScheme();
        $host = $uri->getHost();
        $port = $uri->getPort();
        $userInfo = \urldecode($uri->getUserInfo());

        if ($scheme === 'socks5') {
            $user = null;
            $password = null;
            if ($userInfo !== '') {
                [$user, $password] = \explode(':', $userInfo, 2) + [null, null];
            }

            return new Socks5SocketConnector(
                proxyAddress: "$host:$port",
                username: $user,
                password: $password,
                socketConnector: $connector,
            );
        }

        $headers = [];
        if ($userInfo !== '') {
            $headers = ['Proxy-Authorization' => 'Basic ' . \base64_encode($userInfo)];
        }

        if ($scheme === 'http') {
            if (!\class_exists(Http1TunnelConnector::class)) {
                throw new \RuntimeException('Please require amphp/http-tunnel to use the http proxy option!');
            }

            return new Http1TunnelConnector(
                proxyAddress: "$host:$port",
                customHeaders: $headers,
                socketConnector: $connector,
            );
        }

        if ($scheme === 'https') {
            if (!\class_exists(Https1TunnelConnector::class)) {
                throw new \RuntimeException('Please require amphp/http-tunnel to use the https proxy option!');
            }

            return new Https1TunnelConnector(
                proxyAddress: "$host:$port",
                proxyTlsContext: new ClientTlsContext($host),
                customHeaders: $headers,
                socketConnector: $connector,
            );
        }

        throw new \RuntimeException(\sprintf('Unsupported protocol in proxy option: %s', $scheme));
    }

    private function getTlsContext(array $options): ClientTlsContext|null
    {
        $tlsContext = null;

        if (isset($options[RequestOptions::CERT])) {
            $tlsContext = new ClientTlsContext();
            if (\is_string($options[RequestOptions::CERT])) {
                $tlsContext = $tlsContext->withCertificate(new Certificate(
                    $options[RequestOptions::CERT],
                    $options[RequestOptions::SSL_KEY] ?? null,
                ));
            } else {
                $tlsContext = $tlsContext->withCertificate(new Certificate(
                    $options[RequestOptions::CERT][0],
                    $options[RequestOptions::SSL_KEY] ?? null,
                    $options[RequestOptions::CERT][1],
                ));
            }
        }

        if (isset($options[RequestOptions::VERIFY])) {
            $tlsContext ??= new ClientTlsContext();
            if ($options[RequestOptions::VERIFY] === false) {
                $tlsContext = $tlsContext->withoutPeerVerification();
            } elseif (\is_string($options[RequestOptions::VERIFY])) {
                $tlsContext = $tlsContext->withCaFile($options[RequestOptions::VERIFY]);
            }
        }

        return $tlsContext;
    }
}
