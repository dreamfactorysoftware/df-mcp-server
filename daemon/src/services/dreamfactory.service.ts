export class DreamFactoryService {
  static async getTables(baseUrl: string, apiKey: string): Promise<unknown> {
    return this.request('GET', `${baseUrl}/_schema`, apiKey);
  }

  static async getTableSchema(tableName: string, baseUrl: string, apiKey: string): Promise<unknown> {
    return this.request('GET', `${baseUrl}/_schema/${encodeURIComponent(tableName)}`, apiKey);
  }

  static async getTableData(
    baseUrl: string,
    apiKey: string,
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
    return this.request('GET', url, apiKey, params);
  }

  static async createRecords(
    tableName: string,
    baseUrl: string,
    apiKey: string,
    records: Record<string, unknown>[],
    options: Record<string, unknown> = {}
  ): Promise<unknown> {
    return this.request('POST', `${baseUrl}/_table/${encodeURIComponent(tableName)}`, apiKey, this.buildParams(options), {
      resource: records
    });
  }

  static async updateRecords(
    tableName: string,
    baseUrl: string,
    apiKey: string,
    records: Record<string, unknown>[],
    options: Record<string, unknown> = {}
  ): Promise<unknown> {
    return this.request('PATCH', `${baseUrl}/_table/${encodeURIComponent(tableName)}`, apiKey, this.buildParams(options), {
      resource: records
    });
  }

  static async deleteRecords(
    tableName: string,
    baseUrl: string,
    apiKey: string,
    options: Record<string, unknown> = {}
  ): Promise<unknown> {
    return this.request('DELETE', `${baseUrl}/_table/${encodeURIComponent(tableName)}`, apiKey, this.buildParams(options));
  }

  static async getTableFields(tableName: string, baseUrl: string, apiKey: string, refresh?: boolean): Promise<unknown> {
    const params = new URLSearchParams();
    if (refresh !== undefined) {
      params.set('refresh', String(refresh));
    }
    return this.request('GET', `${baseUrl}/_schema/${encodeURIComponent(tableName)}/_field`, apiKey, params);
  }

  static async getTableRelationships(
    tableName: string,
    baseUrl: string,
    apiKey: string,
    refresh?: boolean
  ): Promise<unknown> {
    const params = new URLSearchParams();
    if (refresh !== undefined) {
      params.set('refresh', String(refresh));
    }
    return this.request('GET', `${baseUrl}/_schema/${encodeURIComponent(tableName)}/_related`, apiKey, params);
  }

  static async getStoredProcedures(baseUrl: string, apiKey: string): Promise<unknown> {
    return this.request('GET', `${baseUrl}/_proc`, apiKey);
  }

  static async callStoredProcedure(
    procedureName: string,
    baseUrl: string,
    apiKey: string,
    parameters?: Record<string, unknown>,
    wrapper?: string,
    returns?: string
  ): Promise<unknown> {
    const params = new URLSearchParams();
    if (wrapper) params.set('wrapper', wrapper);
    if (returns) params.set('returns', returns);
    return this.request('POST', `${baseUrl}/_proc/${encodeURIComponent(procedureName)}`, apiKey, params, parameters ?? {});
  }

  static async getStoredFunctions(baseUrl: string, apiKey: string): Promise<unknown> {
    return this.request('GET', `${baseUrl}/_func`, apiKey);
  }

  static async callStoredFunction(
    functionName: string,
    baseUrl: string,
    apiKey: string,
    parameters?: Record<string, unknown>,
    returns?: string
  ): Promise<unknown> {
    const params = new URLSearchParams();
    if (returns) params.set('returns', returns);
    return this.request('POST', `${baseUrl}/_func/${encodeURIComponent(functionName)}`, apiKey, params, parameters ?? {});
  }

  static async getDatabaseResources(
    baseUrl: string,
    apiKey: string,
    options: Record<string, unknown> = {}
  ): Promise<unknown> {
    return this.request('GET', baseUrl, apiKey, this.buildParams(options));
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
    body?: Record<string, unknown>
  ): Promise<unknown> {
    if (!apiKey) {
      throw new Error('API key is required');
    }

    const target = new URL(url);
    target.searchParams.set('api_key', apiKey);
    if (params) {
      params.forEach((value, key) => target.searchParams.set(key, value));
    }

    const response = await fetch(target, {
      method,
      headers: {
        Accept: 'application/json',
        ...(body ? { 'Content-Type': 'application/json' } : {})
      },
      body: body ? JSON.stringify(body) : undefined
    });

    if (!response.ok) {
      const text = await response.text();
      throw new Error(text || response.statusText);
    }

    return response.json();
  }
}


