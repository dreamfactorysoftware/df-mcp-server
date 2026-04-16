<?php

namespace DreamFactory\Core\McpServer\Models;

use DreamFactory\Core\Enums\ServiceTypeGroups;
use DreamFactory\Core\Enums\Verbs;
use DreamFactory\Core\Models\BaseModel;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class McpCustomTool extends BaseModel
{
    use SoftDeletes;

    protected $table = 'mcp_custom_tools';

    protected $fillable = [
        'service_id',
        'tool_type',
        'name',
        'description',
        'http_method',
        'url',
        'parameters',
        'headers',
        'function',
        'enabled',
        'storage_service_id',
        'scm_repository',
        'scm_reference',
        'storage_path',
    ];

    protected $casts = [
        'service_id' => 'integer',
        'storage_service_id' => 'integer',
        'parameters' => 'array',
        'headers' => 'array',
        'enabled' => 'boolean',
    ];

    protected $attributes = [
        'tool_type' => 'api',
    ];

    /**
     * Get enabled custom tools for a given service as definition arrays.
     */
    public static function getToolsForService(int $serviceId): array
    {
        return static::where('service_id', $serviceId)
            ->where('enabled', true)
            ->get()
            ->map(fn (self $tool) => $tool->toToolDefinition())
            ->all();
    }

    /**
     * Get all custom tools for a given service (for admin UI).
     */
    public static function getAllForService(int $serviceId): array
    {
        $rows = static::where('service_id', $serviceId)
            ->get()
            ->toArray();

        foreach ($rows as &$row) {
            // PHP encodes an empty array as JSON [], but the admin UI expects
            // an empty headers map to serialize as {}. Force empty maps to
            // objects so JSON emits the right shape.
            if (empty($row['headers'])) {
                $row['headers'] = (object) [];
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * Sync custom tools for a service from an array of tool data.
     * Creates new tools, updates existing ones, and deletes removed ones.
     */
    public static function syncToolsForService(int $serviceId, array $tools): void
    {
        $existing = static::where('service_id', $serviceId)->get()->keyBy('id');
        $receivedIds = [];

        foreach ($tools as $toolData) {
            $toolData['service_id'] = $serviceId;

            // Normalize: accept 'type' as alias for 'tool_type'
            if (!isset($toolData['tool_type']) && isset($toolData['type'])) {
                $toolData['tool_type'] = $toolData['type'];
            }

            // Auto-detect tool_type from content when not explicitly set
            if (empty($toolData['tool_type'])) {
                if (!empty($toolData['function']) && empty($toolData['url'])) {
                    $toolData['tool_type'] = 'function';
                } else {
                    $toolData['tool_type'] = 'api';
                }
            }

            if (!empty($toolData['id']) && $existing->has($toolData['id'])) {
                $tool = $existing->get($toolData['id']);
                $tool->update($toolData);
                if ($tool->wasChanged(['storage_service_id', 'storage_path', 'scm_repository', 'scm_reference'])) {
                    Cache::forget(self::buildScmCacheKey(
                        $tool->storage_service_id,
                        $tool->scm_repository,
                        $tool->storage_path,
                        $tool->scm_reference
                    ));
                }
                $receivedIds[] = $tool->id;
            } else {
                $tool = static::create($toolData);
                $receivedIds[] = $tool->id;
            }
        }

        // Force-delete tools not in the received list (avoids soft-delete + unique constraint conflict)
        $toDelete = $existing->keys()->diff($receivedIds)->all();
        if (!empty($toDelete)) {
            static::whereIn('id', $toDelete)->forceDelete();
        }
    }

    /**
     * Convert model to a tool definition array for the daemon.
     */
    public function toToolDefinition(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'tool_type' => $this->tool_type ?? 'api',
            'http_method' => $this->http_method,
            'url' => $this->url,
            'parameters' => $this->parameters ?? [],
            'headers' => !empty($this->headers) ? $this->headers : (object) [],
            'function' => $this->getAttribute('function'),
            'storage_service_id' => $this->storage_service_id,
            'scm_repository' => $this->scm_repository,
            'scm_reference' => $this->scm_reference,
            'storage_path' => $this->storage_path,
        ];
    }

    /**
     * Resolve a function body from a linked SCM service (GitHub/GitLab/Bitbucket).
     *
     * Uses TTL-based caching to avoid hitting the SCM API on every request.
     * Returns null if no SCM link is configured or if the fetch fails.
     */
    public static function resolveScmFunctionBody(
        ?int $storageServiceId,
        ?string $scmRepository,
        ?string $scmReference,
        ?string $storagePath
    ): ?string {
        if (empty($storageServiceId) || empty($storagePath)) {
            return null;
        }

        $cacheKey = self::buildScmCacheKey($storageServiceId, $scmRepository, $storagePath, $scmReference);
        $ttl = config('mcp.scm_cache_ttl', 300);

        return Cache::remember($cacheKey, $ttl, function () use ($storageServiceId, $scmRepository, $scmReference, $storagePath) {
            try {
                $service = \ServiceManager::getServiceById($storageServiceId);
                $serviceName = $service->getName();
                $typeGroup = $service->getServiceTypeInfo()->getGroup();
                $storagePath = trim($storagePath, '/');
                $scmRef = !empty($scmReference) ? $scmReference : null;

                if ($typeGroup === ServiceTypeGroups::SCM) {
                    $result = \ServiceManager::handleRequest(
                        $serviceName,
                        Verbs::GET,
                        '_repo/' . $scmRepository,
                        ['path' => $storagePath, 'branch' => $scmRef, 'content' => 1]
                    );

                    return $result->getContent();
                }

                Log::warning('MCP custom tool storage_service_id does not point to an SCM service.', [
                    'storage_service_id' => $storageServiceId,
                    'type_group' => $typeGroup,
                ]);

                return null;
            } catch (\Exception $e) {
                Log::warning('Failed to fetch MCP custom tool function from SCM.', [
                    'storage_service_id' => $storageServiceId,
                    'scm_repository' => $scmRepository,
                    'storage_path' => $storagePath,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        });
    }

    /**
     * Build a consistent cache key for SCM-sourced function bodies.
     */
    private static function buildScmCacheKey(
        ?int $storageServiceId,
        ?string $scmRepository,
        ?string $storagePath,
        ?string $scmReference
    ): string {
        return 'mcp_scm_function:' . implode(':', [
            $storageServiceId ?? '',
            $scmRepository ?? '',
            $storagePath ?? '',
            $scmReference ?? '',
        ]);
    }
}
