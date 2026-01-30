export type DFAuthConfig = {
  sessionToken: string;
  apiKey?: string;
};

export class DreamFactoryService {
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

  private static async request(
    method: string,
    url: string,
    auth: DFAuthConfig,
    params?: URLSearchParams,
    body?: Record<string, unknown>
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

    if (body) {
      headers['Content-Type'] = 'application/json';
    }

    const response = await fetch(target, {
      method,
      headers,
      body: body ? JSON.stringify(body) : undefined
    });

    if (!response.ok) {
      const text = await response.text();
      throw new Error(text || response.statusText);
    }

    return response.json();
  }
}
