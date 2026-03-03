<?php

namespace DreamFactory\Core\McpServer\Models;

use DreamFactory\Core\Models\BaseModel;
use Illuminate\Database\Eloquent\SoftDeletes;

class McpCustomTool extends BaseModel
{
    use SoftDeletes;

    protected $table = 'mcp_custom_tools';

    protected $fillable = [
        'service_id',
        'name',
        'description',
        'http_method',
        'url',
        'parameters',
        'headers',
        'enabled',
    ];

    protected $casts = [
        'service_id' => 'integer',
        'parameters' => 'array',
        'headers' => 'array',
        'enabled' => 'boolean',
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
        return static::where('service_id', $serviceId)
            ->get()
            ->toArray();
    }

    /**
     * Sync custom tools for a service from an array of tool data.
     * Creates new tools, updates existing ones, and deletes removed ones.
     */
    public static function syncToolsForService(int $serviceId, array $tools): void
    {
        $existingIds = static::where('service_id', $serviceId)->pluck('id')->all();
        $receivedIds = [];

        foreach ($tools as $toolData) {
            $toolData['service_id'] = $serviceId;

            if (!empty($toolData['id'])) {
                $tool = static::find($toolData['id']);
                if ($tool && $tool->service_id === $serviceId) {
                    $tool->update($toolData);
                    $receivedIds[] = $tool->id;
                }
            } else {
                $tool = static::create($toolData);
                $receivedIds[] = $tool->id;
            }
        }

        // Soft-delete tools not in the received list
        $toDelete = array_diff($existingIds, $receivedIds);
        if (!empty($toDelete)) {
            static::whereIn('id', $toDelete)->delete();
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
            'http_method' => $this->http_method,
            'url' => $this->url,
            'parameters' => $this->parameters ?? [],
            'headers' => $this->headers ?? (object)[],
        ];
    }
}
