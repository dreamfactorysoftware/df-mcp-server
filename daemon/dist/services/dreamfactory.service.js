export class DreamFactoryService {
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
    /**
     * Server-side aggregation: fetches all matching rows (paginated internally)
     * and computes SUM, COUNT, AVG, MIN, MAX on specified fields.
     */
    static async aggregateData(baseUrl, auth, options) {
        const { tableName, aggregates, filter, groupBy } = options;
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
            const params = { tableName, limit: PAGE_SIZE, offset };
            if (fieldsParam)
                params.fields = fieldsParam;
            if (filter)
                params.filter = filter;
            if (offset === 0)
                params.includeCount = true;
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
                const alias = agg.alias || `${fn}_${field}`;
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
            const results = Object.values(groups).map(g => ({ ...g._key, ...computeAgg(g._rows, aggregates) }));
            return { results, rows_scanned: allRows.length, total_count: totalCount };
        }
        return { results: [computeAgg(allRows, aggregates)], rows_scanned: allRows.length, total_count: totalCount };
    }
    /**
     * Get the OpenAPI spec for this service via _spec endpoint.
     * Supports compact mode, resource filtering, and model mode.
     */
    static async getApiSpec(baseUrl, auth, options = {}) {
        const params = new URLSearchParams();
        if (options.compact)
            params.set('compact', 'true');
        if (options.resourceName)
            params.set('resource_name', options.resourceName);
        if (options.tables)
            params.set('tables', 'true');
        if (options.model)
            params.set('model', 'true');
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
