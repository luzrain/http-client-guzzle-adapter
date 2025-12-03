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
    public function __construct(private SocketConnector $connector, private array $interceptors = [])
    {
    }

    public function getClient(AmpRequest $request, array $options): DelegateHttpClient
    {
        $cacheKey = $this->createKeyFromOptions($options);

        if (isset($this->cachedClients[$cacheKey])) {
            return $this->cachedClients[$cacheKey];
        }

        $connectContext = (new ConnectContext())->withTlsContext($this->getTlsContext($options));
        $decodeContent = $options[RequestOptions::DECODE_CONTENT] !== false;

        if (isset($options[RequestOptions::FORCE_IP_RESOLVE])) {
            $connectContext->withDnsTypeRestriction(match ($options[RequestOptions::FORCE_IP_RESOLVE]) {
                'v4' => DnsRecord::A,
                'v6' => DnsRecord::AAAA,
                default => throw new \ValueError(\sprintf('Invalid value for request option "%s": %s', RequestOptions::FORCE_IP_RESOLVE, $options[RequestOptions::FORCE_IP_RESOLVE])),
            });
        }

        return $this->cachedClients[$cacheKey] = $this->buildAmpHttpClient(
            connector: $this->getConnector($request->getUri()->getScheme(), $request->getUri()->getHost(), $options),
            connectContext: $connectContext,
            compression: $decodeContent,
        );
    }

    private function createKeyFromOptions(array $options): string
    {
        if (isset($options[RequestOptions::CERT])
            || isset($options[RequestOptions::PROXY])
            || (isset($options[RequestOptions::VERIFY]) && $options[RequestOptions::VERIFY] !== true)
            || (isset($options[RequestOptions::DECODE_CONTENT]) && $options[RequestOptions::DECODE_CONTENT] !== true)
            || isset($options[RequestOptions::FORCE_IP_RESOLVE])
        ) {
            $cacheKey = [];
            foreach ([
                RequestOptions::CERT,
                RequestOptions::PROXY,
                RequestOptions::VERIFY,
                RequestOptions::DECODE_CONTENT,
                RequestOptions::FORCE_IP_RESOLVE,
            ] as $k) {
                $cacheKey[$k] = $options[$k] ?? null;
            }

            return \hash('xxh64', \json_encode($cacheKey));
        }

        return '0000000000000000';
    }

    private function getConnector(string $scheme, string $host, array $options): SocketConnector
    {
        if (!isset($options[RequestOptions::PROXY])) {
            return $this->connector;
        }

        if (!\is_array($options[RequestOptions::PROXY])) {
            $proxy = $options[RequestOptions::PROXY];
        } elseif (isset($options[RequestOptions::PROXY][$scheme]) && (!isset($options[RequestOptions::PROXY]['no']) || !Utils::isHostInNoProxy($host, $options[RequestOptions::PROXY]['no']))) {
            $proxy = $options[RequestOptions::PROXY][$scheme];
        } else {
            return $this->connector;
        }

        if (!\class_exists(Https1TunnelConnector::class)) {
            throw new \RuntimeException("Please require amphp/http-tunnel to use the proxy option!");
        }

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
                proxyAddress: $host . ':' . $port,
                username: $user,
                password: $password,
                socketConnector: $this->connector,
            );
        }

        $headers = [];
        if ($userInfo !== '') {
            $headers = ['Proxy-Authorization' => 'Basic ' . \base64_encode($userInfo)];
        }

        if ($scheme === 'http') {
            return new Http1TunnelConnector(
                proxyAddress: $host . ':' . $port,
                customHeaders: $headers,
                socketConnector: $this->connector,
            );
        }

        if ($scheme === 'https') {
            return new Https1TunnelConnector(
                proxyAddress: $host . ':' . $port,
                proxyTlsContext: new ClientTlsContext($host),
                customHeaders: $headers,
                socketConnector: $this->connector,
            );
        }

        throw new \ValueError('Unsupported protocol in proxy option: ' . $scheme);
    }

    private function getTlsContext(array $options): ?ClientTlsContext
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

    private function buildAmpHttpClient(?SocketConnector $connector = null, ?ConnectContext $connectContext = null, bool $compression = false): DelegateHttpClient
    {
        $client = new PooledHttpClient(
            connectionPool: new UnlimitedConnectionPool(
                connectionFactory: new DefaultConnectionFactory(
                    connector: $connector,
                    connectContext: $connectContext,
                )
            )
        );

        if ($compression) {
            $client = $client->intercept(new DecompressResponseInterceptor());
        }

        foreach (\array_reverse($this->interceptors) as $applicationInterceptor) {
            $client = new InterceptedHttpClient($client, $applicationInterceptor, []);
        }

        return $client;
    }
}
