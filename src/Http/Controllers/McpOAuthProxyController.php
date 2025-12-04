<?php

namespace DreamFactory\Core\McpServer\Http\Controllers;

use DreamFactory\Core\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Proxies OAuth endpoints to the MCP daemon
 */
class McpOAuthProxyController extends Controller
{
    private string $daemonUrl;

    public function __construct()
    {
        $this->daemonUrl = config('mcp.daemon.url', 'http://127.0.0.1:8006');
    }

    /**
     * Proxy any request to daemon (GET, POST, etc.)
     * Extracts path from request and forwards to daemon
     */
    public function proxy(Request $request, string $mcpService)
    {
        $path = '/' . $request->path();
        return $this->proxyToDaemon($request, $path);
    }

    /**
     * Aliases for backwards compatibility with routes/middleware
     */
    public function proxyWellKnown(Request $request, string $mcpService)
    {
        return $this->proxy($request, $mcpService);
    }

    public function proxyGet(Request $request, string $mcpService)
    {
        return $this->proxy($request, $mcpService);
    }

    public function proxyPost(Request $request, string $mcpService)
    {
        return $this->proxy($request, $mcpService);
    }

    /**
     * Handle CORS preflight
     */
    public function handleOptions(Request $request, string $mcpService)
    {
        return response('', 204)->withHeaders($this->corsHeaders());
    }

    /**
     * Proxy request to daemon
     */
    private function proxyToDaemon(Request $request, string $path)
    {
        try {
            $client = new \GuzzleHttp\Client([
                'timeout' => 30,
                'allow_redirects' => false, // Let redirects come back to client
            ]);

            // Prepare headers
            $headers = [
                'Accept' => $request->header('Accept', 'application/json'),
            ];

            // Copy relevant headers
            foreach ($request->headers->all() as $key => $values) {
                $lowerKey = strtolower($key);
                if (in_array($lowerKey, ['content-type', 'authorization'])) {
                    $headers[$key] = $values[0] ?? '';
                }
            }

            $options = ['headers' => $headers];

            // Include body for POST requests
            if ($request->method() === 'POST') {
                $contentType = $request->header('Content-Type', '');
                if (str_contains($contentType, 'application/json')) {
                    $options['json'] = $request->json()->all();
                } elseif (str_contains($contentType, 'application/x-www-form-urlencoded')) {
                    $options['form_params'] = $request->all();
                } else {
                    $options['body'] = $request->getContent();
                }
            }

            // Include query params for GET requests
            if ($request->method() === 'GET' && !empty($request->query())) {
                $options['query'] = $request->query();
            }

            $response = $client->request($request->method(), $this->daemonUrl . $path, $options);

            return $this->buildResponse($response);

        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $response = $e->getResponse();
            return $this->buildResponse($response);

        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            Log::error('Failed to connect to MCP daemon for OAuth', [
                'daemonUrl' => $this->daemonUrl,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'server_error',
                'error_description' => 'MCP daemon is not running',
            ], 503)->withHeaders($this->corsHeaders());

        } catch (\Throwable $e) {
            Log::error('OAuth proxy error', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'server_error',
                'error_description' => $e->getMessage(),
            ], 500)->withHeaders($this->corsHeaders());
        }
    }

    /**
     * Build response from Guzzle response
     */
    private function buildResponse($response)
    {
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $contentType = $response->getHeaderLine('Content-Type') ?: 'application/json';

        $resp = response($body, $status)
            ->header('Content-Type', $contentType);

        // Add CORS headers
        foreach ($this->corsHeaders() as $name => $value) {
            $resp->header($name, $value);
        }

        // Copy specific headers from daemon
        $headersToCopy = ['location', 'www-authenticate', 'set-cookie'];
        foreach ($response->getHeaders() as $name => $values) {
            $lowerName = strtolower($name);
            if (in_array($lowerName, $headersToCopy)) {
                $resp->header($name, implode(', ', $values));
            }
        }

        return $resp;
    }

    private function corsHeaders(): array
    {
        return [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
        ];
    }
}
