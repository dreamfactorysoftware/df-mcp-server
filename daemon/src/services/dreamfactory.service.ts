export type DFAuthConfig = {
  sessionToken?: string; // Optional for API-key-only auth
  apiKey?: string; // Can be used alone if app has a role assigned
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
    // Require at least one auth method
    if (!auth.sessionToken && !auth.apiKey) {
      throw new Error('Either session token or API key is required');
    }

    const target = new URL(url);

    if (params) {
      params.forEach((value, key) => target.searchParams.set(key, value));
    }

    const headers: Record<string, string> = {
      Accept: 'application/json',
    };

    // Add session token if available
    if (auth.sessionToken) {
      headers['X-DreamFactory-Session-Token'] = auth.sessionToken;
    }

    // Add API key if available
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
      // Log full error details server-side for debugging
      console.error(`DreamFactory API Error [${method} ${target.pathname}]:`, {
        status: response.status,
        statusText: response.statusText,
        body: text
      });

      // Return sanitized error message to client based on status code
      // Avoid leaking sensitive details from error responses
      const safeMessage = this.getSafeErrorMessage(response.status, text);
      throw new Error(safeMessage);
    }

    return response.json();
  }

  /**
   * Get a safe, sanitized error message for the client.
   * Extracts useful info without leaking sensitive details.
   */
  private static getSafeErrorMessage(status: number, responseText: string): string {
    // Try to extract DreamFactory's error message if it's JSON
    try {
      const parsed = JSON.parse(responseText);
      const dfError = parsed?.error?.message;
      if (dfError && typeof dfError === 'string') {
        // DreamFactory error messages are generally safe to expose
        return dfError;
      }
    } catch {
      // Not JSON, use generic message
    }

    // Map common HTTP status codes to safe messages
    switch (status) {
      case 400:
        return 'Bad request: Invalid parameters provided';
      case 401:
        return 'Authentication failed: Invalid or expired credentials';
      case 403:
        return 'Access forbidden: Insufficient permissions for this operation';
      case 404:
        return 'Resource not found';
      case 409:
        return 'Conflict: The operation conflicts with existing data';
      case 422:
        return 'Validation error: The provided data is invalid';
      case 429:
        return 'Too many requests: Rate limit exceeded';
      case 500:
        return 'Server error: DreamFactory encountered an internal error';
      case 502:
      case 503:
      case 504:
        return 'Service unavailable: DreamFactory is temporarily unavailable';
      default:
        return `Request failed with status ${status}`;
    }
  }
}
