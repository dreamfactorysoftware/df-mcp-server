<?php

namespace DreamFactory\Core\McpServer\Services;

final class DreamFactoryService
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct(?string $baseUrl = null, ?string $apiKey = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? (string) getenv('DREAMFACTORY_URL'), '/');
        $this->apiKey = $apiKey;

        if ($this->baseUrl === '' || $this->apiKey === '') {
            throw new \RuntimeException('DreamFactory configuration missing.');
        }
    }

    public function getTables(): array
    {
        return $this->request('GET', '/_schema');
    }

    public function getTableSchema(string $tableName): array
    {
        return $this->request('GET', '/_schema/' . rawurlencode($tableName));
    }

    public function getTableData(
        string $tableName,
        ?array $fields = null,
        ?string $filter = null,
        ?int $offset = null,
        ?int $limit = null,
        ?string $order = null,
        ?string $group = null,
        ?bool $continueProcessing = null,
        ?string $related = null,
        ?bool $countOnly = null,
        ?bool $includeCount = null,
        ?bool $includeSchema = null,
        ?array $ids = null
    ): array {
        $params = [];
        if ($fields) { $params['fields'] = implode(',', $fields); }
        if ($filter) { $params['filter'] = $filter; }
        if ($offset !== null) { $params['offset'] = (string) $offset; }
        if ($limit !== null) { $params['limit'] = (string) $limit; }
        if ($order) { $params['order'] = $order; }
        if ($group) { $params['group'] = $group; }
        if ($continueProcessing !== null) { $params['continue'] = $continueProcessing ? 'true' : 'false'; }
        if ($related) { $params['related'] = $related; }
        if ($countOnly !== null) { $params['count_only'] = $countOnly ? 'true' : 'false'; }
        if ($includeCount !== null) { $params['include_count'] = $includeCount ? 'true' : 'false'; }
        if ($includeSchema !== null) { $params['include_schema'] = $includeSchema ? 'true' : 'false'; }
        if ($ids) { $params['ids'] = implode(',', $ids); }

        $path = '/_table/' . rawurlencode($tableName);
        return $this->request('GET', $path, $params);
    }

    public function createRecords(
        string $tableName,
        array $records,
        ?array $fields = null,
        ?string $related = null,
        ?bool $continueProcessing = null,
        ?bool $rollback = null
    ): array {
        $params = [];
        if ($fields) { $params['fields'] = implode(',', $fields); }
        if ($related) { $params['related'] = $related; }
        if ($continueProcessing !== null) { $params['continue'] = $continueProcessing ? 'true' : 'false'; }
        if ($rollback !== null) { $params['rollback'] = $rollback ? 'true' : 'false'; }

        $path = '/_table/' . rawurlencode($tableName);
        return $this->request('POST', $path, $params, ['resource' => $records]);
    }

    public function updateRecords(
        string $tableName,
        array $records,
        ?array $fields = null,
        ?string $related = null,
        ?array $ids = null,
        ?string $filter = null,
        ?bool $continueProcessing = null,
        ?bool $rollback = null
    ): array {
        $params = [];
        if ($fields) { $params['fields'] = implode(',', $fields); }
        if ($related) { $params['related'] = $related; }
        if ($ids) { $params['ids'] = implode(',', $ids); }
        if ($filter) { $params['filter'] = $filter; }
        if ($continueProcessing !== null) { $params['continue'] = $continueProcessing ? 'true' : 'false'; }
        if ($rollback !== null) { $params['rollback'] = $rollback ? 'true' : 'false'; }

        $path = '/_table/' . rawurlencode($tableName);
        return $this->request('PATCH', $path, $params, ['resource' => $records]);
    }

    public function deleteRecords(
        string $tableName,
        ?array $ids = null,
        ?string $filter = null,
        ?bool $force = null,
        ?array $fields = null,
        ?string $related = null,
        ?bool $continueProcessing = null,
        ?bool $rollback = null
    ): array {
        $params = [];
        if ($ids) { $params['ids'] = implode(',', $ids); }
        if ($filter) { $params['filter'] = $filter; }
        if ($force !== null) { $params['force'] = $force ? 'true' : 'false'; }
        if ($fields) { $params['fields'] = implode(',', $fields); }
        if ($related) { $params['related'] = $related; }
        if ($continueProcessing !== null) { $params['continue'] = $continueProcessing ? 'true' : 'false'; }
        if ($rollback !== null) { $params['rollback'] = $rollback ? 'true' : 'false'; }

        $path = '/_table/' . rawurlencode($tableName);
        return $this->request('DELETE', $path, $params);
    }

    public function getTableFields(string $tableName, ?bool $refresh = null): array
    {
        $params = [];
        if ($refresh !== null) { $params['refresh'] = $refresh ? 'true' : 'false'; }
        return $this->request('GET', '/_schema/' . rawurlencode($tableName) . '/_field', $params);
    }

    public function getTableRelationships(string $tableName, ?bool $refresh = null): array
    {
        $params = [];
        if ($refresh !== null) { $params['refresh'] = $refresh ? 'true' : 'false'; }
        return $this->request('GET', '/_schema/' . rawurlencode($tableName) . '/_related', $params);
    }

    public function getStoredProcedures(): array
    {
        return $this->request('GET', '/_proc');
    }

    public function callStoredProcedure(string $procedureName, ?array $parameters = null, ?string $wrapper = null, ?string $returns = null): array
    {
        $params = [];
        if ($wrapper) { $params['wrapper'] = $wrapper; }
        if ($returns) { $params['returns'] = $returns; }
        $path = '/_proc/' . rawurlencode($procedureName);
        return $this->request('POST', $path, $params, $parameters ?? []);
    }

    public function getStoredFunctions(): array
    {
        return $this->request('GET', '/_func');
    }

    public function callStoredFunction(string $functionName, ?array $parameters = null, ?string $returns = null): array
    {
        $params = [];
        if ($returns) { $params['returns'] = $returns; }
        $path = '/_func/' . rawurlencode($functionName);
        return $this->request('POST', $path, $params, $parameters ?? []);
    }

    public function getDatabaseResources(
        ?bool $asList = null,
        ?bool $asAccessList = null,
        ?bool $includeAccess = null,
        ?array $fields = null,
        ?bool $refresh = null
    ): array {
        $params = [];
        if ($asList !== null) { $params['as_list'] = $asList ? 'true' : 'false'; }
        if ($asAccessList !== null) { $params['as_access_list'] = $asAccessList ? 'true' : 'false'; }
        if ($includeAccess !== null) { $params['include_access'] = $includeAccess ? 'true' : 'false'; }
        if ($fields) { $params['fields'] = implode(',', $fields); }
        if ($refresh !== null) { $params['refresh'] = $refresh ? 'true' : 'false'; }
        return $this->request('GET', '/', $params);
    }

    private function request(string $method, string $path, array $query = [], ?array $json = null): array
    {
        $query['api_key'] = $this->apiKey;
        $url = $this->baseUrl . $path;
        if ($query) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Failed to initialize cURL');
        }
        $headers = [
            'Accept: application/json',
        ];
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_SLASHES));
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $responseBody = curl_exec($ch);
        $curlErr = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 0;
        curl_close($ch);

        if ($responseBody === false) {
            throw new \RuntimeException('Network error: ' . ($curlErr ?: 'Unknown cURL error'));
        }

        $decoded = json_decode($responseBody, true);
        if ($status < 200 || $status >= 300) {
            $message = $this->extractErrorMessage($decoded, $status, $responseBody);
            $this->throwForStatus($status, $message);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('Unexpected response from DreamFactory API');
        }
        return $decoded;
    }

    private function extractErrorMessage($decoded, int $status, string $raw): string
    {
        if (is_array($decoded)) {
            if (isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
                return $decoded['error']['message'];
            }
        }
        return sprintf('HTTP %d: %s', $status, $raw);
    }

    private function throwForStatus(int $status, string $message): void
    {
        switch ($status) {
            case 401:
                throw new \RuntimeException('Authentication failed: ' . $message);
            case 403:
                throw new \RuntimeException('Access forbidden: ' . $message);
            case 404:
                throw new \RuntimeException('Resource not found: ' . $message);
            case 422:
                throw new \RuntimeException('Validation error: ' . $message);
            case 500:
                throw new \RuntimeException('Server error: ' . $message);
            default:
                throw new \RuntimeException('DreamFactory API Error: ' . $message);
        }
    }
}


