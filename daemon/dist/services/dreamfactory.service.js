import { currentTraceId } from './trace.service.js';
/**
 * Default timeout (in ms) for all HTTP requests to the DreamFactory API.
 *
 * 60s, not 30s: `GET {service}/_spec?model=true` (what get_data_model calls)
 * takes ~33s uncached against a 28-table MySQL service measured over loopback,
 * so a 30s ceiling aborted the call every time and no amount of routing or
 * caching changed that. The underlying spec call is still too slow and is
 * tracked separately.
 */
const REQUEST_TIMEOUT_MS = 60_000;
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
        if (options.include_files !== undefined)
            params.set('include_files', String(options.include_files));
        if (options.include_folders !== undefined)
            params.set('include_folders', String(options.include_folders));
        if (options.full_tree !== undefined)
            params.set('full_tree', String(options.full_tree));
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
        append('fields', options.fields);
        append('filter', options.filter);
        append('offset', options.offset);
        append('limit', options.limit);
        append('order', options.order);
        append('group', options.group);
        append('continue', options.continue);
        append('related', options.related);
        append('count_only', options.count_only);
        append('include_count', options.include_count);
        append('include_schema', options.include_schema);
        append('ids', options.ids);
        const url = `${baseUrl}/_table/${encodeURIComponent(String(options.table_name ?? ''))}`;
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
    /**
     * Server-side aggregation via DreamFactory's GROUP BY + aggregate fields support.
     * Pushes SUM/COUNT/AVG/MIN/MAX down to the database in a single API call.
     * Falls back to client-side pagination if the server doesn't support aggregate fields.
     */
    static async aggregateData(baseUrl, auth, options, behaviour = {}) {
        const { table_name: tableName, aggregates, filter, group_by: groupBy } = options;
        // compact_results: a grouping on a high-cardinality column used to page
        // through 50,000 groups (3.6 MB seen in production logs). Cap it; the
        // caller can raise the limit or narrow with a filter. Off: 1.4.1 paging.
        const groupLimit = behaviour.groupLimit === undefined
            ? undefined
            : Math.max(1, Math.min(5000, Math.floor(behaviour.groupLimit)));
        const alias = (rows) => behaviour.aliases ? applyAggregateAliases(rows, aggregates, groupBy) : rows;
        // Build fields list: group-by columns + aggregate expressions
        const fields = [];
        if (groupBy) {
            fields.push(...groupBy);
        }
        for (const agg of aggregates) {
            const fn = (agg.function || '').toUpperCase();
            const field = agg.field || '*';
            fields.push(`${fn}(${field})`);
        }
        // Try server-side aggregation first (single API call). DreamFactory accepts
        // aggregate fields with or without GROUP BY, so whole-table MIN/MAX/COUNT
        // also push down instead of paging the table through the 100k-row fallback.
        {
            try {
                const params = { table_name: tableName, fields };
                if (filter) {
                    params.filter = filter;
                }
                if (!groupBy || groupBy.length === 0) {
                    // Whole-table aggregate: exactly one row.
                    const data = await this.getTableData(baseUrl, auth, { ...params, limit: 1 });
                    return { results: alias((data?.resource ?? [])), mode: 'server-side' };
                }
                // Grouped: DreamFactory caps every response at the service's max_records
                // (commonly 1000), so page through the groups. Ordering by the group
                // columns makes offset paging deterministic (no missing or duplicate groups).
                params.group = groupBy.join(',');
                params.order = groupBy.join(',');
                const PAGE = 1000;
                if (groupLimit === undefined) {
                    const MAX_GROUPS = 50000;
                    const rows = [];
                    let truncated = false;
                    for (let offset = 0;; offset += PAGE) {
                        const data = await this.getTableData(baseUrl, auth, { ...params, limit: PAGE, offset });
                        const page = (data?.resource ?? []);
                        rows.push(...page);
                        if (page.length < PAGE)
                            break;
                        if (rows.length >= MAX_GROUPS) {
                            truncated = true;
                            break;
                        }
                    }
                    const results = alias(rows);
                    return truncated
                        ? { results, mode: 'server-side', groups: results.length, truncated: true, note: `Stopped at ${MAX_GROUPS} groups; add a filter to narrow the grouping.` }
                        : { results, mode: 'server-side', groups: results.length };
                }
                // Fetch one group past the limit so we know whether there are more.
                const rows = [];
                for (let offset = 0; rows.length <= groupLimit; offset += PAGE) {
                    const want = Math.min(PAGE, groupLimit + 1 - rows.length);
                    const data = await this.getTableData(baseUrl, auth, { ...params, limit: want, offset });
                    const page = (data?.resource ?? []);
                    rows.push(...page);
                    if (page.length < want)
                        break;
                }
                const truncated = rows.length > groupLimit;
                const results = alias(rows.slice(0, groupLimit));
                return truncated
                    ? { results, mode: 'server-side', groups: results.length, truncated: true, note: `Showing the first ${groupLimit} groups; more exist. Narrow with a filter or raise limit (max 5000).` }
                    : { results, mode: 'server-side', groups: results.length };
            }
            catch (serverErr) {
                console.warn('[aggregateData] Server-side aggregation failed, falling back to client-side:', serverErr instanceof Error ? serverErr.message : serverErr);
            }
        }
        // Fallback: client-side aggregation (paginated fetch + JS compute)
        const PAGE_SIZE = 1000;
        const neededFields = new Set();
        for (const agg of aggregates) {
            if (agg.field && agg.field !== '*')
                neededFields.add(agg.field);
        }
        if (groupBy) {
            for (const g of groupBy)
                neededFields.add(g);
        }
        const fieldsParam = neededFields.size > 0 ? Array.from(neededFields) : undefined;
        let allRows = [];
        let offset = 0;
        let totalCount = null;
        while (true) {
            const params = { table_name: tableName, limit: PAGE_SIZE, offset };
            if (fieldsParam)
                params.fields = fieldsParam;
            if (filter)
                params.filter = filter;
            if (offset === 0)
                params.include_count = true;
            const data = await this.getTableData(baseUrl, auth, params);
            const rows = (data?.resource ?? []);
            if (totalCount === null && data?.meta?.count !== undefined) {
                totalCount = data.meta.count;
            }
            allRows = allRows.concat(rows);
            if (rows.length < PAGE_SIZE)
                break;
            offset += PAGE_SIZE;
            if (offset >= 100000)
                break;
        }
        const computeAgg = (rows, aggs) => {
            const result = {};
            for (const agg of aggs) {
                const fn = (agg.function || '').toUpperCase();
                const field = agg.field || '*';
                let alias = agg.alias || `${fn}_${field}`;
                // semantic_catalog: an alias that would overwrite a group column or an
                // earlier result keeps DreamFactory's server-side key, as applyAggregateAliases does.
                if (behaviour.aliases && agg.alias && (groupBy?.includes(alias) || alias in result))
                    alias = serverAggregateKey(agg);
                if (fn === 'COUNT') {
                    result[alias] = field === '*' ? rows.length : rows.filter(r => r[field] != null).length;
                }
                else {
                    const values = rows.map(r => parseFloat(String(r[field]))).filter(v => !isNaN(v));
                    if (values.length === 0) {
                        result[alias] = null;
                        continue;
                    }
                    if (fn === 'SUM')
                        result[alias] = Math.round(values.reduce((a, b) => a + b, 0) * 100) / 100;
                    else if (fn === 'AVG')
                        result[alias] = Math.round((values.reduce((a, b) => a + b, 0) / values.length) * 100) / 100;
                    else if (fn === 'MIN')
                        result[alias] = Math.min(...values);
                    else if (fn === 'MAX')
                        result[alias] = Math.max(...values);
                    else
                        result[alias] = null;
                }
            }
            return result;
        };
        if (groupBy && groupBy.length > 0) {
            const groups = {};
            for (const row of allRows) {
                const key = groupBy.map(g => String(row[g] ?? 'NULL')).join('|');
                if (!groups[key]) {
                    groups[key] = { _key: {}, _rows: [] };
                    for (const g of groupBy)
                        groups[key]._key[g] = row[g];
                }
                groups[key]._rows.push(row);
            }
            const all = Object.values(groups).map(g => ({ ...g._key, ...computeAgg(g._rows, aggregates) }));
            if (groupLimit === undefined || all.length <= groupLimit) {
                return { results: all, rows_scanned: allRows.length, total_count: totalCount, mode: 'client-side-fallback' };
            }
            // Grouping order here is object-key order (integer-like keys first), not
            // anything meaningful; sort like the server-side ORDER BY group_by so the
            // groups kept are the first ones in that order, and say so.
            const cmp = (a, b) => {
                if (a === b)
                    return 0;
                if (a === null || a === undefined)
                    return -1;
                if (b === null || b === undefined)
                    return 1;
                if (typeof a === 'number' && typeof b === 'number')
                    return a - b;
                if (typeof a === 'number')
                    return -1;
                if (typeof b === 'number')
                    return 1;
                return String(a) < String(b) ? -1 : 1;
            };
            all.sort((x, y) => {
                for (const g of groupBy) {
                    const c = cmp(x[g], y[g]);
                    if (c !== 0)
                        return c;
                }
                return 0;
            });
            const results = all.slice(0, groupLimit);
            return { results, groups: all.length, truncated: true, note: `Showing the first ${groupLimit} of ${all.length} groups, ordered by ${groupBy.join(', ')}. Narrow with a filter or raise limit (max 5000).`, rows_scanned: allRows.length, total_count: totalCount, mode: 'client-side-fallback' };
        }
        return { results: [computeAgg(allRows, aggregates)], rows_scanned: allRows.length, total_count: totalCount, mode: 'client-side-fallback' };
    }
    /**
     * Get the OpenAPI spec for this service via _spec endpoint.
     * Supports compact mode, resource filtering, and model mode.
     */
    static async getApiSpec(baseUrl, auth, options = {}) {
        const params = new URLSearchParams();
        if (options.compact)
            params.set('compact', 'true');
        if (options.resource_name)
            params.set('resource_name', options.resource_name);
        if (options.tables)
            params.set('tables', 'true');
        if (options.model)
            params.set('model', options.model === 'semantics' ? 'semantics' : 'true');
        if (options.enrich)
            params.set('enrich', 'true');
        if (options.refresh)
            params.set('refresh', 'true');
        if (options.format)
            params.set('format', options.format);
        return this.request('GET', `${baseUrl}/_spec`, auth, params);
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
        // Require at least one auth method (API-key-only works when the app has a role)
        if (!auth.sessionToken && !auth.apiKey) {
            throw new Error('Either session token or API key is required');
        }
        const target = new URL(url);
        if (params) {
            params.forEach((value, key) => target.searchParams.set(key, value));
        }
        const headers = {};
        if (auth.sessionToken) {
            headers['X-DreamFactory-Session-Token'] = auth.sessionToken;
        }
        if (auth.apiKey) {
            headers['X-DreamFactory-API-Key'] = auth.apiKey;
        }
        const traceId = currentTraceId();
        if (traceId) {
            headers['X-DreamFactory-Trace-Id'] = traceId;
        }
        console.log(`[DreamFactoryService.requestRaw] ${method} ${target.toString()}`);
        const response = await fetch(target, {
            method,
            headers,
            signal: AbortSignal.timeout(REQUEST_TIMEOUT_MS),
        });
        console.log(`[DreamFactoryService.requestRaw] Response status: ${response.status} ${response.statusText}`);
        if (!response.ok) {
            const text = await response.text();
            console.error(`[DreamFactoryService.requestRaw] Error response:`, text);
            throw new Error(text || response.statusText);
        }
        return response;
    }
    static async request(method, url, auth, params, body) {
        // Require at least one auth method (API-key-only works when the app has a role)
        if (!auth.sessionToken && !auth.apiKey) {
            throw new Error('Either session token or API key is required');
        }
        const target = new URL(url);
        if (params) {
            params.forEach((value, key) => target.searchParams.set(key, value));
        }
        const headers = {
            Accept: 'application/json',
        };
        if (auth.sessionToken) {
            headers['X-DreamFactory-Session-Token'] = auth.sessionToken;
        }
        if (auth.apiKey) {
            headers['X-DreamFactory-API-Key'] = auth.apiKey;
        }
        const traceId = currentTraceId();
        if (traceId) {
            headers['X-DreamFactory-Trace-Id'] = traceId;
        }
        if (body) {
            headers['Content-Type'] = typeof body === 'string' ? 'text/plain' : 'application/json';
        }
        console.log(`[DreamFactoryService.request] ${method} ${target.toString()}`);
        const response = await fetch(target, {
            method,
            headers,
            body: body ? (typeof body === 'string' ? body : JSON.stringify(body)) : undefined,
            signal: AbortSignal.timeout(REQUEST_TIMEOUT_MS),
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
/** DreamFactory's key for a server-side aggregate column: FN_column, COUNT(*) is COUNT_ALL. */
function serverAggregateKey(agg) {
    const fn = (agg.function || '').toUpperCase();
    const field = agg.field || '*';
    return field === '*' ? `${fn}_ALL` : `${fn}_${field.replace(/[^a-zA-Z0-9_]/g, '_')}`;
}
/**
 * DreamFactory names a server-side aggregate column FN_column (COUNT(*) is
 * COUNT_ALL) and ignores any alias, so a metric asking for `revenue` came back
 * as SUM_total_amount. Apply the caller's aliases to the rows here (only under
 * semantic_catalog or a named semantic tool). An alias never overwrites a
 * group_by column or a key already in the row (another column or an earlier
 * alias): that aggregate keeps DreamFactory's key instead.
 */
export function applyAggregateAliases(rows, aggregates, groupBy = []) {
    const renames = [];
    for (const agg of aggregates ?? []) {
        if (!agg?.alias)
            continue;
        const serverKey = serverAggregateKey(agg);
        if (serverKey !== agg.alias)
            renames.push([serverKey, agg.alias]);
    }
    if (renames.length === 0)
        return rows;
    return rows.map(row => {
        const out = { ...row };
        for (const [from, to] of renames) {
            if (!(from in out) || groupBy.includes(to) || to in out)
                continue;
            out[to] = out[from];
            delete out[from];
        }
        return out;
    });
}
