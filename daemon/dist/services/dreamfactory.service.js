// Known DreamFactory database service types
const DATABASE_SERVICE_TYPES = new Set([
    'sqlite',
    'mysql',
    'mariadb',
    'pgsql',
    'sqlsrv',
    'oracle',
    'ibmdb2',
    'informix',
    'firebird',
    'mongodb',
    'couchdb',
    'dynamodb',
    'hana',
    'databricks',
    'salesforce_db',
    'aws_redshift',
    'snowflake',
]);
// Known DreamFactory file service types
const FILE_SERVICE_TYPES = new Set([
    'local_file',
    'aws_s3',
    'azure_blob',
    'rackspace_cloud_files',
    'openstack_object_storage',
    'ftp_file',
    'sftp_file',
    'webdav_file',
]);
export class DreamFactoryService {
    /**
     * Get all available services from DreamFactory.
     * Call this with the base DreamFactory URL (e.g., http://localhost/api/v2)
     */
    static async getServices(baseUrl, auth) {
        const url = `${baseUrl}/system/service`;
        console.log('[DreamFactoryService.getServices] Fetching from:', url);
        const response = await this.request('GET', url, auth);
        console.log('[DreamFactoryService.getServices] Full response:', JSON.stringify(response, null, 2));
        console.log('[DreamFactoryService.getServices] Response resource count:', response.resource?.length ?? 0);
        return response.resource ?? [];
    }
    /**
     * Get database services only (filtered by known database types)
     */
    static async getDatabaseServices(baseUrl, auth) {
        const services = await this.getServices(baseUrl, auth);
        console.log('[DreamFactoryService.getDatabaseServices] All services with all fields:');
        services.forEach((s, i) => {
            console.log(`  [${i}] name="${s.name}" label="${s.label}" type="${s.type}"`);
        });
        const dbServices = services.filter(service => DATABASE_SERVICE_TYPES.has(service.type));
        console.log('[DreamFactoryService.getDatabaseServices] Filtered database services:', dbServices.map(s => `${s.name} (${s.type})`));
        return dbServices;
    }
    /**
     * Get file services only (filtered by known file types)
     */
    static async getFileServices(baseUrl, auth) {
        const services = await this.getServices(baseUrl, auth);
        const fileServices = services.filter(service => FILE_SERVICE_TYPES.has(service.type));
        console.log('[DreamFactoryService.getFileServices] Filtered file services:', fileServices.map(s => `${s.name} (${s.type})`));
        return fileServices;
    }
    // ============================================================================
    // File API Methods
    // ============================================================================
    /**
     * List files and folders in a container/path
     */
    static async listFiles(baseUrl, auth, path = '', options = {}) {
        const params = new URLSearchParams();
        if (options.includeFiles !== undefined)
            params.set('include_files', String(options.includeFiles));
        if (options.includeFolders !== undefined)
            params.set('include_folders', String(options.includeFolders));
        if (options.fullTree !== undefined)
            params.set('full_tree', String(options.fullTree));
        if (options.zip !== undefined)
            params.set('zip', String(options.zip));
        const encodedPath = path ? encodeURIComponent(path).replace(/%2F/g, '/') : '';
        return this.request('GET', `${baseUrl}/${encodedPath}`, auth, params);
    }
    /**
     * Get file content, returning typed results based on the response content type.
     */
    static async getFileContent(baseUrl, auth, filePath) {
        const encodedPath = encodeURIComponent(filePath).replace(/%2F/g, '/');
        const url = `${baseUrl}/${encodedPath}`;
        const response = await this.requestRaw('GET', url, auth);
        const contentType = response.headers.get('content-type') ?? 'application/octet-stream';
        const mimeType = contentType.split(';')[0].trim();
        if (mimeType.startsWith('image/')) {
            const buffer = Buffer.from(await response.arrayBuffer());
            return { kind: 'image', mimeType, data: buffer.toString('base64') };
        }
        if (mimeType.startsWith('audio/')) {
            const buffer = Buffer.from(await response.arrayBuffer());
            return { kind: 'audio', mimeType, data: buffer.toString('base64') };
        }
        // Text-like types: text/*, application/json, application/xml, etc.
        const text = await response.text();
        return { kind: 'text', content: text };
    }
    /**
     * Create a folder
     */
    static async createFolder(baseUrl, auth, folderPath) {
        const encodedPath = encodeURIComponent(folderPath).replace(/%2F/g, '/');
        const url = `${baseUrl}/${encodedPath}/`;
        return this.request('POST', url, auth, undefined, { folder: { name: folderPath.split('/').pop() } });
    }
    /**
     * Delete a file or folder
     */
    static async deleteFile(baseUrl, auth, path, options = {}) {
        const params = new URLSearchParams();
        if (options.force !== undefined)
            params.set('force', String(options.force));
        const encodedPath = encodeURIComponent(path).replace(/%2F/g, '/');
        return this.request('DELETE', `${baseUrl}/${encodedPath}`, auth, params);
    }
    /**
     * Get file/folder properties
     */
    static async getFileProperties(baseUrl, auth, path) {
        const params = new URLSearchParams();
        params.set('include_properties', 'true');
        const encodedPath = encodeURIComponent(path).replace(/%2F/g, '/');
        return this.request('GET', `${baseUrl}/${encodedPath}`, auth, params);
    }
    /**
     * Create a file with the given content
     */
    static async createFile(baseUrl, auth, filePath, content) {
        const encodedPath = encodeURIComponent(filePath).replace(/%2F/g, '/');
        return this.request('POST', `${baseUrl}/${encodedPath}`, auth, undefined, content);
    }
    static async getTables(baseUrl, auth) {
        return this.request('GET', `${baseUrl}/_schema`, auth);
    }
    static async getTableSchema(tableName, baseUrl, auth) {
        return this.request('GET', `${baseUrl}/_schema/${encodeURIComponent(tableName)}`, auth);
    }
    static async getTableData(baseUrl, auth, options = {}) {
        const params = new URLSearchParams();
        const append = (key, value) => {
            if (value === undefined || value === null || value === '') {
                return;
            }
            if (Array.isArray(value)) {
                params.set(key, value.join(','));
            }
            else {
                params.set(key, String(value));
            }
        };
        append('tableName', options.tableName);
        append('fields', options.fields);
        append('filter', options.filter);
        append('offset', options.offset);
        append('limit', options.limit);
        append('order', options.order);
        append('group', options.group);
        append('continue', options.continue);
        append('related', options.related);
        append('count_only', options.countOnly);
        append('include_count', options.includeCount);
        append('include_schema', options.includeSchema);
        append('ids', options.ids);
        const url = `${baseUrl}/_table/${encodeURIComponent(String(options.tableName ?? ''))}`;
        return this.request('GET', url, auth, params);
    }
    static async createRecords(tableName, baseUrl, auth, records, options = {}) {
        return this.request('POST', `${baseUrl}/_table/${encodeURIComponent(tableName)}`, auth, this.buildParams(options), {
            resource: records
        });
    }
    static async updateRecords(tableName, baseUrl, auth, records, options = {}) {
        return this.request('PATCH', `${baseUrl}/_table/${encodeURIComponent(tableName)}`, auth, this.buildParams(options), {
            resource: records
        });
    }
    static async deleteRecords(tableName, baseUrl, auth, options = {}) {
        return this.request('DELETE', `${baseUrl}/_table/${encodeURIComponent(tableName)}`, auth, this.buildParams(options));
    }
    static async getTableFields(tableName, baseUrl, auth, refresh) {
        const params = new URLSearchParams();
        if (refresh !== undefined) {
            params.set('refresh', String(refresh));
        }
        return this.request('GET', `${baseUrl}/_schema/${encodeURIComponent(tableName)}/_field`, auth, params);
    }
    static async getTableRelationships(tableName, baseUrl, auth, refresh) {
        const params = new URLSearchParams();
        if (refresh !== undefined) {
            params.set('refresh', String(refresh));
        }
        return this.request('GET', `${baseUrl}/_schema/${encodeURIComponent(tableName)}/_related`, auth, params);
    }
    static async getStoredProcedures(baseUrl, auth) {
        return this.request('GET', `${baseUrl}/_proc`, auth);
    }
    static async callStoredProcedure(procedureName, baseUrl, auth, parameters, wrapper, returns) {
        const params = new URLSearchParams();
        if (wrapper)
            params.set('wrapper', wrapper);
        if (returns)
            params.set('returns', returns);
        return this.request('POST', `${baseUrl}/_proc/${encodeURIComponent(procedureName)}`, auth, params, parameters ?? {});
    }
    static async getStoredFunctions(baseUrl, auth) {
        return this.request('GET', `${baseUrl}/_func`, auth);
    }
    static async callStoredFunction(functionName, baseUrl, auth, parameters, returns) {
        const params = new URLSearchParams();
        if (returns)
            params.set('returns', returns);
        return this.request('POST', `${baseUrl}/_func/${encodeURIComponent(functionName)}`, auth, params, parameters ?? {});
    }
    static async getDatabaseResources(baseUrl, auth, options = {}) {
        return this.request('GET', baseUrl, auth, this.buildParams(options));
    }
    static buildParams(options) {
        const params = new URLSearchParams();
        Object.entries(options).forEach(([key, value]) => {
            if (value === undefined || value === null) {
                return;
            }
            if (Array.isArray(value)) {
                params.set(key, value.join(','));
            }
            else {
                params.set(key, String(value));
            }
        });
        return params;
    }
    static async requestRaw(method, url, auth, params) {
        if (!auth.sessionToken) {
            throw new Error('Session token is required');
        }
        const target = new URL(url);
        if (params) {
            params.forEach((value, key) => target.searchParams.set(key, value));
        }
        const headers = {
            'X-DreamFactory-Session-Token': auth.sessionToken,
        };
        if (auth.apiKey) {
            headers['X-DreamFactory-API-Key'] = auth.apiKey;
        }
        console.log(`[DreamFactoryService.requestRaw] ${method} ${target.toString()}`);
        const response = await fetch(target, { method, headers });
        console.log(`[DreamFactoryService.requestRaw] Response status: ${response.status} ${response.statusText}`);
        if (!response.ok) {
            const text = await response.text();
            console.error(`[DreamFactoryService.requestRaw] Error response:`, text);
            throw new Error(text || response.statusText);
        }
        return response;
    }
    static async request(method, url, auth, params, body) {
        if (!auth.sessionToken) {
            throw new Error('Session token is required');
        }
        const target = new URL(url);
        if (params) {
            params.forEach((value, key) => target.searchParams.set(key, value));
        }
        const headers = {
            Accept: 'application/json',
            'X-DreamFactory-Session-Token': auth.sessionToken,
        };
        if (auth.apiKey) {
            headers['X-DreamFactory-API-Key'] = auth.apiKey;
        }
        if (body) {
            headers['Content-Type'] = typeof body === 'string' ? 'text/plain' : 'application/json';
        }
        console.log(`[DreamFactoryService.request] ${method} ${target.toString()}`);
        const response = await fetch(target, {
            method,
            headers,
            body: body ? (typeof body === 'string' ? body : JSON.stringify(body)) : undefined
        });
        console.log(`[DreamFactoryService.request] Response status: ${response.status} ${response.statusText}`);
        if (!response.ok) {
            const text = await response.text();
            console.error(`[DreamFactoryService.request] Error response:`, text);
            throw new Error(text || response.statusText);
        }
        return response.json();
    }
}
