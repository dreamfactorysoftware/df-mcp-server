export type DFAuthConfig = {
  apiKey: string;
  sessionToken?: string;
};

export class DreamFactoryService {
  static async getTables(baseUrl: string, auth: DFAuthConfig): Promise<unknown> {
    return this.request('GET', `${baseUrl}/_schema`, auth.apiKey, undefined, undefined, auth.sessionToken);
  }

  static async getTableSchema(tableName: string, baseUrl: string, auth: DFAuthConfig): Promise<unknown> {
    return this.request('GET', `${baseUrl}/_schema/${encodeURIComponent(tableName)}`, auth.apiKey, undefined, undefined, auth.sessionToken);
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
    return this.request('GET', url, auth.apiKey, params, undefined, auth.sessionToken);
  }

  static async createRecords(
    tableName: string,
    baseUrl: string,
    auth: DFAuthConfig,
    records: Record<string, unknown>[],
    options: Record<string, unknown> = {}
  ): Promise<unknown> {
    return this.request('POST', `${baseUrl}/_table/${encodeURIComponent(tableName)}`, auth.apiKey, this.buildParams(options), {
      resource: records
    }, auth.sessionToken);
  }

  static async updateRecords(
    tableName: string,
    baseUrl: string,
    auth: DFAuthConfig,
    records: Record<string, unknown>[],
    options: Record<string, unknown> = {}
  ): Promise<unknown> {
    return this.request('PATCH', `${baseUrl}/_table/${encodeURIComponent(tableName)}`, auth.apiKey, this.buildParams(options), {
      resource: records
    }, auth.sessionToken);
  }

  static async deleteRecords(
    tableName: string,
    baseUrl: string,
    auth: DFAuthConfig,
    options: Record<string, unknown> = {}
  ): Promise<unknown> {
    return this.request('DELETE', `${baseUrl}/_table/${encodeURIComponent(tableName)}`, auth.apiKey, this.buildParams(options), undefined, auth.sessionToken);
  }

  static async getTableFields(tableName: string, baseUrl: string, auth: DFAuthConfig, refresh?: boolean): Promise<unknown> {
    const params = new URLSearchParams();
    if (refresh !== undefined) {
      params.set('refresh', String(refresh));
    }
    return this.request('GET', `${baseUrl}/_schema/${encodeURIComponent(tableName)}/_field`, auth.apiKey, params, undefined, auth.sessionToken);
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
    return this.request('GET', `${baseUrl}/_schema/${encodeURIComponent(tableName)}/_related`, auth.apiKey, params, undefined, auth.sessionToken);
  }

  static async getStoredProcedures(baseUrl: string, auth: DFAuthConfig): Promise<unknown> {
    return this.request('GET', `${baseUrl}/_proc`, auth.apiKey, undefined, undefined, auth.sessionToken);
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
    return this.request('POST', `${baseUrl}/_proc/${encodeURIComponent(procedureName)}`, auth.apiKey, params, parameters ?? {}, auth.sessionToken);
  }

  static async getStoredFunctions(baseUrl: string, auth: DFAuthConfig): Promise<unknown> {
    return this.request('GET', `${baseUrl}/_func`, auth.apiKey, undefined, undefined, auth.sessionToken);
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
    return this.request('POST', `${baseUrl}/_func/${encodeURIComponent(functionName)}`, auth.apiKey, params, parameters ?? {}, auth.sessionToken);
  }

  static async getDatabaseResources(
    baseUrl: string,
    auth: DFAuthConfig,
    options: Record<string, unknown> = {}
  ): Promise<unknown> {
    return this.request('GET', baseUrl, auth.apiKey, this.buildParams(options), undefined, auth.sessionToken);
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
    apiKey: string,
    params?: URLSearchParams,
    body?: Record<string, unknown>,
    sessionToken?: string
  ): Promise<unknown> {
    if (!sessionToken && !apiKey) {
      throw new Error('Session token or API key is required');
    }

    const target = new URL(url);

    // Only add api_key to query params if no session token (fallback mode)
    if (!sessionToken) {
      target.searchParams.set('api_key', apiKey);
    }

    if (params) {
      params.forEach((value, key) => target.searchParams.set(key, value));
    }

    const headers: Record<string, string> = {
      Accept: 'application/json',
    };

    // Use session token header for user role-based authentication
    if (sessionToken) {
      headers['X-DreamFactory-Session-Token'] = sessionToken;
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


