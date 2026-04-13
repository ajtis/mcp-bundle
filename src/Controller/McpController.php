<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\McpBundle\Controller;

use Mcp\Server;
use Mcp\Server\Session\SessionStoreInterface;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\PsrHttpMessage\HttpFoundationFactoryInterface;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class McpController
{
    /** Namespace UUID for deriving deterministic session IDs from Bearer tokens */
    private const SESSION_NAMESPACE = '3b6a27bc-d4b2-4b2e-8f3e-1a2b3c4d5e6f';

    public function __construct(
        private readonly Server $server,
        private readonly HttpMessageFactoryInterface $httpMessageFactory,
        private readonly HttpFoundationFactoryInterface $httpFoundationFactory,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?SessionStoreInterface $sessionStore = null,
    ) {
    }

    public function handle(Request $request): Response
    {
        $psrRequest = $this->httpMessageFactory->createRequest($request);

        // Auto-create a session for Bearer token requests that skip the initialize handshake.
        // Skip for "initialize" requests — the MCP spec forbids a session ID on initialize;
        // the SDK will create the session itself during the handshake.
        $isInitialize = false;
        $body = $psrRequest->getBody()->getContents();
        if ('' !== $body) {
            $decoded = json_decode($body, true);
            $isInitialize = \is_array($decoded) && 'initialize' === ($decoded['method'] ?? null);
            // Rewind so the transport can read the body again
            $psrRequest->getBody()->rewind();
        }

        if (!$isInitialize
            && $this->sessionStore !== null
            && '' === $psrRequest->getHeaderLine('Mcp-Session-Id')
            && str_starts_with($psrRequest->getHeaderLine('Authorization'), 'Bearer ')
        ) {
            $token = substr($psrRequest->getHeaderLine('Authorization'), 7);
            $sessionId = Uuid::v5(Uuid::fromString(self::SESSION_NAMESPACE), $token);

            if (!$this->sessionStore->exists($sessionId)) {
                $this->sessionStore->write($sessionId, '{}');
            }

            $psrRequest = $psrRequest->withHeader('Mcp-Session-Id', $sessionId->toRfc4122());
        }

        $transport = new StreamableHttpTransport(
            $psrRequest,
            $this->responseFactory,
            $this->streamFactory,
            logger: $this->logger,
        );

        $psrResponse = $this->server->run($transport);
        $streamed = 'text/event-stream' === $psrResponse->getHeaderLine('Content-Type');

        return $this->httpFoundationFactory->createResponse($psrResponse, $streamed);
    }
}
