import { currentTraceId } from './trace.service.js';

export type DFAuthConfig = {
  sessionToken: string;
  apiKey?: string;
};

export type FileContentResult =
  | { kind: 'text'; content: string }
  | { kind: 'image'; mimeType: string; data: string }
  | { kind: 'audio'; mimeType: string; data: string };

export type DFService = {
  name: string;
  label: string;
  type: string;
};

/** Default timeout (in ms) for all HTTP requests to the DreamFactory API. */
const REQUEST_TIMEOUT_MS = 30_000;

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
  static async getServices(baseUrl: string, auth: DFAuthConfig): Promise<DFService[]> {
    const url = `${baseUrl}/system/service`;
    console.log('[DreamFactoryService.getServices] Fetching from:', url);
    const response = await this.request('GET', url, auth) as { resource?: DFService[] };
    console.log('[DreamFactoryService.getServices] Full response:', JSON.stringify(response, null, 2));
    console.log('[DreamFactoryService.getServices] Response resource count:', response.resource?.length ?? 0);
    return response.resource ?? [];
  }

  /**
   * Get database services only (filtered by known database types)
   */
  static async getDatabaseServices(baseUrl: string, auth: DFAuthConfig): Promise<DFService[]> {
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
  static async getFileServices(baseUrl: string, auth: DFAuthConfig): Promise<DFService[]> {
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
  static async listFiles(
    baseUrl: string,
    auth: DFAuthConfig,
    path: string = '',
    options: Record<string, unknown> = {}
  ): Promise<unknown> {
    const params = new URLSearchParams();
    if (options.includeFiles !== undefined) params.set('include_files', String(options.includeFiles));
    if (options.includeFolders !== undefined) params.set('include_folders', String(options.includeFolders));
    if (options.fullTree !== undefined) params.set('full_tree', String(options.fullTree));
    if (options.zip !== undefined) params.set('zip', String(options.zip));

    const encodedPath = path ? encodeURIComponent(path).replace(/%2F/g, '/') : '';
    return this.request('GET', `${baseUrl}/${encodedPath}`, auth, params);
  }

  /**
   * Get file content, returning typed results based on the response content type.
   */
  static async getFileContent(
    baseUrl: string,
    auth: DFAuthConfig,
    filePath: string
  ): Promise<FileContentResult> {
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
  static async createFolder(
    baseUrl: string,
    auth: DFAuthConfig,
    folderPath: string
  ): Promise<unknown> {
    const encodedPath = encodeURIComponent(folderPath).replace(/%2F/g, '/');
    const url = `${baseUrl}/${encodedPath}/`;
    return this.request('POST', url, auth, undefined, { folder: { name: folderPath.split('/').pop() } });
  }

  /**
   * Delete a file or folder
   */
  static async deleteFile(
    baseUrl: string,
    auth: DFAuthConfig,
    path: string,
    options: Record<string, unknown> = {}
  ): Promise<unknown> {
    const params = new URLSearchParams();
    if (options.force !== undefined) params.set('force', String(options.force));

    const encodedPath = encodeURIComponent(path).replace(/%2F/g, '/');
    return this.request('DELETE', `${baseUrl}/${encodedPath}`, auth, params);
  }

  /**
   * Get file/folder properties
   */
  static async getFileProperties(
    baseUrl: string,
    auth: DFAuthConfig,
    path: string
  ): Promise<unknown> {
    const params = new URLSearchParams();
    params.set('include_properties', 'true');

    const encodedPath = encodeURIComponent(path).replace(/%2F/g, '/');
    return this.request('GET', `${baseUrl}/${encodedPath}`, auth, params);
  }

  /**
   * Create a file with the given content
   */
  static async createFile(
    baseUrl: string,
    auth: DFAuthConfig,
    filePath: string,
    content: string
  ): Promise<unknown> {
    const encodedPath = encodeURIComponent(filePath).replace(/%2F/g, '/');
    return this.request('POST', `${baseUrl}/${encodedPath}`, auth, undefined, content);
  }

  static async getTables(baseUrl: string, auth: DFAuthConfig): Promise<unknown> {
    return this.request('GET', `${baseUrl}/_schema`, auth);
  }

  static async getTableSchema(tableName: string, baseUrl: string, auth: DFAuthConfig): Promise<unknown> {
    return this.request('GET', `${baseUrl}/_schema/${encodeURIComponent(tableName)}`, auth);
  }

  static async getTableData(
    baseUrl: string,
    auth: DFAuthConfig,
    options: Record<string, unknown> = {}
  ): Promise<unknown> {
    const params = new URLSearchParams();
    const append = (key: string, value?: unknown) => {
      if (value === undefined || value === null || value === '') {
        return;
      }
      if (Array.isArray(value)) {
        params.set(key, value.join(','));
      } else {
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

  static async createRecords(
    tableName: string,
    baseUrl: string,
    auth: DFAuthConfig,
    records: Record<string, unknown>[],
    options: Record<string, unknown> = {}
  ): Promise<unknown> {
    return this.request('POST', `${baseUrl}/_table/${encodeURIComponent(tableName)}`, auth, this.buildParams(options), {
      resource: records
    });
  }

  static async updateRecords(
    tableName: string,
    baseUrl: string,
    auth: DFAuthConfig,
    records: Record<string, unknown>[],
    options: Record<string, unknown> = {}
  ): Promise<unknown> {
    return this.request('PATCH', `${baseUrl}/_table/${encodeURIComponent(tableName)}`, auth, this.buildParams(options), {
      resource: records
    });
  }

  static async deleteRecords(
    tableName: string,
    baseUrl: string,
    auth: DFAuthConfig,
    options: Record<string, unknown> = {}
  ): Promise<unknown> {
    return this.request('DELETE', `${baseUrl}/_table/${encodeURIComponent(tableName)}`, auth, this.buildParams(options));
  }

  static async getTableFields(tableName: string, baseUrl: string, auth: DFAuthConfig, refresh?: boolean): Promise<unknown> {
    const params = new URLSearchParams();
    if (refresh !== undefined) {
      params.set('refresh', String(refresh));
    }
    return this.request('GET', `${baseUrl}/_schema/${encodeURIComponent(tableName)}/_field`, auth, params);
  }

  static async getTableRelationships(
    tableName: string,
    baseUrl: string,
    auth: DFAuthConfig,
    refresh?: boolean
  ): Promise<unknown> {
    const params = new URLSearchParams();
    if (refresh !== undefined) {
      params.set('refresh', String(refresh));
    }
    return this.request('GET', `${baseUrl}/_schema/${encodeURIComponent(tableName)}/_related`, auth, params);
  }

  static async getStoredProcedures(baseUrl: string, auth: DFAuthConfig): Promise<unknown> {
    return this.request('GET', `${baseUrl}/_proc`, auth);
  }

  static async callStoredProcedure(
    procedureName: string,
    baseUrl: string,
    auth: DFAuthConfig,
    parameters?: Record<string, unknown>,
    wrapper?: string,
    returns?: string
  ): Promise<unknown> {
    const params = new URLSearchParams();
    if (wrapper) params.set('wrapper', wrapper);
    if (returns) params.set('returns', returns);
    return this.request('POST', `${baseUrl}/_proc/${encodeURIComponent(procedureName)}`, auth, params, parameters ?? {});
  }

  static async getStoredFunctions(baseUrl: string, auth: DFAuthConfig): Promise<unknown> {
    return this.request('GET', `${baseUrl}/_func`, auth);
  }

  static async callStoredFunction(
    functionName: string,
    baseUrl: string,
    auth: DFAuthConfig,
    parameters?: Record<string, unknown>,
    returns?: string
  ): Promise<unknown> {
    const params = new URLSearchParams();
    if (returns) params.set('returns', returns);
    return this.request('POST', `${baseUrl}/_func/${encodeURIComponent(functionName)}`, auth, params, parameters ?? {});
  }

  static async getDatabaseResources(
    baseUrl: string,
    auth: DFAuthConfig,
    options: Record<string, unknown> = {}
  ): Promise<unknown> {
    return this.request('GET', baseUrl, auth, this.buildParams(options));
  }

  /**
   * Server-side aggregation via DreamFactory's GROUP BY + aggregate fields support.
   * Pushes SUM/COUNT/AVG/MIN/MAX down to the database in a single API call.
   * Falls back to client-side pagination if the server doesn't support aggregate fields.
   */
  static async aggregateData(
    baseUrl: string,
    auth: DFAuthConfig,
    options: {
      tableName: string;
      aggregates: Array<{ function: string; field: string; alias?: string }>;
      filter?: string;
      groupBy?: string[];
    }
  ): Promise<unknown> {
    const { tableName, aggregates, filter, groupBy } = options;

    // Build fields list: group-by columns + aggregate expressions
    const fields: string[] = [];
    if (groupBy) {
      fields.push(...groupBy);
    }
    for (const agg of aggregates) {
      const fn = (agg.function || '').toUpperCase();
      const field = agg.field || '*';
      fields.push(`${fn}(${field})`);
    }

    // Try server-side aggregation first (single API call)
    if (groupBy && groupBy.length > 0) {
      try {
        const params: Record<string, unknown> = {
          tableName,
          fields,
          limit: 0, // no limit on grouped results
        };
        params.group = groupBy.join(',');
        if (filter) {
          params.filter = filter;
        }

        const data = await this.getTableData(baseUrl, auth, params) as Record<string, unknown>;
        const rows = (data?.resource ?? []) as Record<string, unknown>[];

        return { results: rows, mode: 'server-side' };
      } catch (serverErr) {
        console.warn('[aggregateData] Server-side aggregation failed, falling back to client-side:', serverErr instanceof Error ? serverErr.message : serverErr);
      }
    }

    // Fallback: client-side aggregation (paginated fetch + JS compute)
    const PAGE_SIZE = 1000;
    const neededFields = new Set<string>();
    for (const agg of aggregates) {
      if (agg.field && agg.field !== '*') neededFields.add(agg.field);
    }
    if (groupBy) {
      for (const g of groupBy) neededFields.add(g);
    }
    const fieldsParam = neededFields.size > 0 ? Array.from(neededFields) : undefined;

    let allRows: Record<string, unknown>[] = [];
    let offset = 0;
    let totalCount: number | null = null;

    while (true) {
      const params: Record<string, unknown> = { tableName, limit: PAGE_SIZE, offset };
      if (fieldsParam) params.fields = fieldsParam;
      if (filter) params.filter = filter;
      if (offset === 0) params.includeCount = true;

      const data = await this.getTableData(baseUrl, auth, params) as Record<string, unknown>;
      const rows = (data?.resource ?? []) as Record<string, unknown>[];
      if (totalCount === null && (data?.meta as Record<string, unknown>)?.count !== undefined) {
        totalCount = (data.meta as Record<string, unknown>).count as number;
      }
      allRows = allRows.concat(rows);
      if (rows.length < PAGE_SIZE) break;
      offset += PAGE_SIZE;
      if (offset >= 100000) break;
    }

    const computeAgg = (rows: Record<string, unknown>[], aggs: typeof aggregates) => {
      const result: Record<string, unknown> = {};
      for (const agg of aggs) {
        const fn = (agg.function || '').toUpperCase();
        const field = agg.field || '*';
        const alias = agg.alias || `${fn}_${field}`;
        if (fn === 'COUNT') {
          result[alias] = field === '*' ? rows.length : rows.filter(r => r[field] != null).length;
        } else {
          const values = rows.map(r => parseFloat(String(r[field]))).filter(v => !isNaN(v));
          if (values.length === 0) { result[alias] = null; continue; }
          if (fn === 'SUM') result[alias] = Math.round(values.reduce((a, b) => a + b, 0) * 100) / 100;
          else if (fn === 'AVG') result[alias] = Math.round((values.reduce((a, b) => a + b, 0) / values.length) * 100) / 100;
          else if (fn === 'MIN') result[alias] = Math.min(...values);
          else if (fn === 'MAX') result[alias] = Math.max(...values);
          else result[alias] = null;
        }
      }
      return result;
    };

    if (groupBy && groupBy.length > 0) {
      const groups: Record<string, { _key: Record<string, unknown>; _rows: Record<string, unknown>[] }> = {};
      for (const row of allRows) {
        const key = groupBy.map(g => String(row[g] ?? 'NULL')).join('|');
        if (!groups[key]) {
          groups[key] = { _key: {}, _rows: [] };
          for (const g of groupBy) groups[key]._key[g] = row[g];
        }
        groups[key]._rows.push(row);
      }
      const results = Object.values(groups).map(g => ({ ...g._key, ...computeAgg(g._rows, aggregates) }));
      return { results, rows_scanned: allRows.length, total_count: totalCount, mode: 'client-side-fallback' };
    }

    return { results: [computeAgg(allRows, aggregates)], rows_scanned: allRows.length, total_count: totalCount, mode: 'client-side-fallback' };
  }

  /**
   * Get the OpenAPI spec for this service via _spec endpoint.
   * Supports compact mode, resource filtering, and model mode.
   */
  static async getApiSpec(
    baseUrl: string,
    auth: DFAuthConfig,
    options: {
      compact?: boolean;
      resourceName?: string;
      tables?: boolean;
      model?: boolean;
      refresh?: boolean;
      format?: string;
    } = {}
  ): Promise<unknown> {
    const params = new URLSearchParams();
    if (options.compact) params.set('compact', 'true');
    if (options.resourceName) params.set('resource_name', options.resourceName);
    if (options.tables) params.set('tables', 'true');
    if (options.model) params.set('model', 'true');
    if (options.refresh) params.set('refresh', 'true');
    if (options.format) params.set('format', options.format);
    return this.request('GET', `${baseUrl}/_spec`, auth, params);
  }

  private static buildParams(options: Record<string, unknown>): URLSearchParams {
    const params = new URLSearchParams();
    Object.entries(options).forEach(([key, value]) => {
      if (value === undefined || value === null) {
        return;
      }
      if (Array.isArray(value)) {
        params.set(key, value.join(','));
      } else {
        params.set(key, String(value));
      }
    });
    return params;
  }

  private static async requestRaw(
    method: string,
    url: string,
    auth: DFAuthConfig,
    params?: URLSearchParams
  ): Promise<Response> {
    if (!auth.sessionToken) {
      throw new Error('Session token is required');
    }

    const target = new URL(url);
    if (params) {
      params.forEach((value, key) => target.searchParams.set(key, value));
    }

    const headers: Record<string, string> = {
      'X-DreamFactory-Session-Token': auth.sessionToken,
    };

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

  private static async request(
    method: string,
    url: string,
    auth: DFAuthConfig,
    params?: URLSearchParams,
    body?: Record<string, unknown> | string
  ): Promise<unknown> {
    if (!auth.sessionToken) {
      throw new Error('Session token is required');
    }

    const target = new URL(url);

    if (params) {
      params.forEach((value, key) => target.searchParams.set(key, value));
    }

    const headers: Record<string, string> = {
      Accept: 'application/json',
      'X-DreamFactory-Session-Token': auth.sessionToken,
    };

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
