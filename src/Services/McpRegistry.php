<?php

namespace DreamFactory\Core\McpServer\Services;

use DreamFactory\Core\McpServer\Models\McpServer;
use Illuminate\Support\Facades\Cache;

class McpRegistry
{
    private string $cacheKey = 'mcp:servers:map';

    public function all(): array
    {
        return Cache::remember($this->cacheKey, 300, function () {
            return McpServer::all()->keyBy('api_name')
                ->map(fn($m) => [
                    'api_key' => $m->api_key,
                    'role' => $m->role,
                ])->toArray();
        });
    }

    public function get(string $apiName): ?array
    {
        return $this->all()[$apiName] ?? null;
    }

    public function upsert(string $apiName, string $apiKey, ?string $role = null): array
    {
        $server = McpServer::updateOrCreate(
            ['api_name' => $apiName],
            ['api_key' => $apiKey, 'role' => $role]
        );
        Cache::forget($this->cacheKey);
        
        // Return only the fields that match the model's fillable
        return [
            'api_name' => $server->api_name,
            'api_key' => $server->api_key,
            'role' => $server->role,
        ];
    }

    public function delete(string $apiName): bool
    {
        $deleted = McpServer::where('api_name', $apiName)->delete();
        if ($deleted) Cache::forget($this->cacheKey);
        return $deleted > 0;
    }
}

