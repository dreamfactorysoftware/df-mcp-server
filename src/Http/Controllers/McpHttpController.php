<?php



namespace DreamFactory\Core\McpServer\Http\Controllers;

use DreamFactory\Core\McpServer\Services\McpServerFactory;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

final class McpHttpController
{
    private McpServerFactory $factory;
    private Psr17Factory $psr17Factory;
    private PsrHttpFactory $psrHttpFactory;
    private HttpFoundationFactory $httpFoundationFactory;

    public function __construct(McpServerFactory $factory)
    {
        $this->factory = $factory;
        $this->psr17Factory = new Psr17Factory();
        $this->psrHttpFactory = new PsrHttpFactory(
            $this->psr17Factory,
            $this->psr17Factory,
            $this->psr17Factory,
            $this->psr17Factory
        );
        $this->httpFoundationFactory = new HttpFoundationFactory();
    }

    public function handle(Request $request): Response
    {
        $apiName = $request->route('apiName');

        try {
            $server = $this->factory->forApi($apiName);
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => 'Session not found',
                'session' => $apiName,
                'timestamp' => now()->toIso8601String(),
            ], 404);
        }

        // Convert Laravel Request to PSR-7
        $psrRequest = $this->psrHttpFactory->createRequest($request);

        // Add mcp-session-id header (withHeader returns a new instance)
        $psrRequestWithHeader = $psrRequest->withHeader('mcp-session-id', $apiName);

        // Create transport with modified request
        $transport = new StreamableHttpTransport(
            $psrRequestWithHeader,
            $this->psr17Factory,
            $this->psr17Factory
        );

        // Run server
        $psrResponse = $server->run($transport);

        // Convert PSR-7 Response to Symfony Response
        return $this->httpFoundationFactory->createResponse($psrResponse);
    }
}
