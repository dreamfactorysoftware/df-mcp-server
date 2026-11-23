import * as z from 'zod/v4';
import { DreamFactoryService } from './dreamfactory.service.js';
import { registerApiConnectorTools } from './api-connector.tools.js';
import { registerFileApiTools } from './file-api.tools.js';
import { createToolRegistrar, getAuth, registerMergedTools, respond, sanitizeApiName, WRITE_VERBS } from './tool-utils.js';
/**
 * df-core's data model ships `query_templates` naming bare verbs (`get_table_data`)
 * with no `service`. Rewrite each one into the exact call this server accepts:
 * `{prefix}_{verb}` in prefixed mode, `service` added in merged mode.
 */
export function adaptQueryTemplates(model, call = {}) {
    const templates = model?.query_templates;
    if (!templates || typeof templates !== 'object')
        return model;
    for (const t of Object.values(templates)) {
        if (!t || typeof t.tool !== 'string')
            continue;
        if (call.prefix)
            t.tool = `${call.prefix}_${t.tool}`;
        if (call.service && t.params && typeof t.params === 'object')
            t.params = { ...t.params, service: call.service };
    }
    return model;
}
/**
 * df-semantic's `semantics` block (served by get_semantics, and first in the
 * full data model) names bare verbs in `run_with` plus the service. Rewrite
 * each into the exact call this server accepts, the way adaptQueryTemplates
 * does for query templates: `{prefix}_{verb}` in prefixed mode, `service`
 * added to the arguments in merged multi-database mode. The informational
 * `service` key is dropped since the call now encodes it.
 */
export function adaptSemantics(data, call = {}) {
    const semantics = data?.semantics;
    if (!semantics || typeof semantics !== 'object')
        return data;
    for (const list of [semantics.metrics, semantics.verified_queries]) {
        if (!Array.isArray(list))
            continue;
        for (const entry of list) {
            const run = entry?.run_with;
            if (!run || typeof run.tool !== 'string')
                continue;
            if (call.prefix)
                run.tool = `${call.prefix}_${run.tool}`;
            if (call.service)
                run.arguments = { ...(run.arguments ?? {}), service: call.service };
            delete run.service;
        }
    }
    return data;
}
/** Default page for get_table_data when the caller sets no limit. */
export const DEFAULT_ROW_LIMIT = 50;
/** Default and ceiling for grouped aggregate_data results. */
export const DEFAULT_GROUP_LIMIT = 100;
export const MAX_GROUP_LIMIT = 5000;
/** Sample rows kept per table in get_data_model unless asked otherwise. */
export const DEFAULT_SAMPLES = 1;
const MAX_ENUM_VALUES = 20;
/** Above this many tables, get_data_model answers with an index unless detail or tables is given. */
export const INDEX_TABLE_THRESHOLD = 12;
/** Enumerations this small stay in the index: coded columns and test rows live there (the slim model caps them at 20 too). */
export const INDEX_ENUM_MAX = 20;
/**
 * The index form of the data model: every table with its row count and its
 * columns as one string each (name:type, pk, -> fk target), plus the
 * relationships as one line each and the structural patterns. No types
 * beyond the name, no samples, no enumerations: those come from
 * get_data_model(tables=[...]). On a 28-table service this is a quarter of
 * the slim model and a tenth of the full one.
 */
export function indexDataModel(model) {
    const tables = {};
    for (const [name, t] of Object.entries(model.tables ?? {})) {
        const columns = (t?.columns ?? []).map((c) => {
            let s = String(c?.name ?? '');
            if (c?.type)
                s += `:${c.type}`;
            if (c?.pk)
                s += ' pk';
            if (c?.fk)
                s += ` -> ${typeof c.fk === 'string' ? c.fk : JSON.stringify(c.fk)}`;
            return s;
        });
        const entry = t?.row_count === undefined ? { columns } : { row_count: t.row_count, columns };
        // Small enumerations are the cheapest semantics there are (status codes,
        // department codes, the test rows among them); keep them in the index.
        if (t?.enum_values && typeof t.enum_values === 'object') {
            const small = Object.fromEntries(Object.entries(t.enum_values).filter(([, v]) => Array.isArray(v) && v.length > 0 && v.length <= INDEX_ENUM_MAX));
            if (Object.keys(small).length)
                entry.enum_values = small;
        }
        tables[name] = entry;
    }
    let relationships = model.relationships;
    if (relationships && typeof relationships === 'object' && !Array.isArray(relationships)) {
        relationships = Object.fromEntries(Object.entries(relationships).map(([table, rels]) => [
            table,
            (Array.isArray(rels) ? rels : []).map((r) => `${r?.name ?? '?'} (${r?.type ?? 'rel'} -> ${r?.ref_table ?? '?'}${r?.junction_table ? ' via ' + r.junction_table : ''})`)
        ]));
    }
    const n = Object.keys(tables).length;
    const out = {
        service: model.service,
        description: `Index of ${n} tables: row counts, columns (name:type, pk, -> foreign key) and small enumerations. Call get_data_model with tables=[...] for sample rows and the larger enumerations of the tables you need, or detail=true for everything.`,
        index_only: true,
    };
    if (model.semantics)
        out.semantics = model.semantics;
    out.tables = tables;
    if (relationships)
        out.relationships = relationships;
    if (model.patterns)
        out.patterns = model.patterns;
    return out;
}
/**
 * compact_results: trim the condensed data model to what a planning step
 * needs. df-core sends three sample rows per table and every enumeration in
 * full; on a 28-table database that is 73 KB (18k tokens) per call. Keep one
 * sample row and up to 20 enumeration values, answer with an index above 12
 * tables, and narrow to `tables` when the caller names them.
 */
export function slimDataModel(model, opts = {}) {
    if (!model || typeof model !== 'object' || !model.tables || typeof model.tables !== 'object')
        return model;
    const keep = opts.tables?.length ? new Set(opts.tables) : null;
    if (!keep && !opts.detail && Object.keys(model.tables).length > INDEX_TABLE_THRESHOLD) {
        return indexDataModel(model);
    }
    const out = { ...model };
    const samples = Math.max(0, Math.min(3, opts.samples ?? DEFAULT_SAMPLES));
    const tables = {};
    for (const [name, t] of Object.entries(model.tables)) {
        if (keep && !keep.has(name))
            continue;
        const slim = { ...t };
        if (Array.isArray(slim.sample_data)) {
            if (samples === 0)
                delete slim.sample_data;
            else
                slim.sample_data = slim.sample_data.slice(0, samples);
        }
        if (slim.enum_values && typeof slim.enum_values === 'object') {
            const enums = {};
            for (const [col, vals] of Object.entries(slim.enum_values)) {
                enums[col] = Array.isArray(vals) && vals.length > MAX_ENUM_VALUES
                    ? [...vals.slice(0, MAX_ENUM_VALUES), `… ${vals.length - MAX_ENUM_VALUES} more`]
                    : vals;
            }
            slim.enum_values = enums;
        }
        tables[name] = slim;
    }
    out.tables = tables;
    if (keep) {
        if (Array.isArray(out.relationships)) {
            out.relationships = out.relationships.filter((r) => keep.has(r?.from_table ?? r?.table ?? '') || keep.has(r?.to_table ?? r?.references ?? ''));
        }
        else if (out.relationships && typeof out.relationships === 'object') {
            out.relationships = Object.fromEntries(Object.entries(out.relationships).filter(([k]) => keep.has(k)));
        }
        out.tables_omitted = Object.keys(model.tables).filter(n => !keep.has(n)).length;
    }
    return out;
}
/**
 * semantic_catalog: drop df-core's query templates when the approved catalog
 * already carries runnable metrics or verified queries (they answer the same
 * questions with approved definitions), unless the caller asks for them.
 */
export function dropTemplates(model, templates) {
    if (!model || typeof model !== 'object' || !('query_templates' in model))
        return model;
    const catalogRuns = !!(model.semantics && (model.semantics.metrics?.length || model.semantics.verified_queries?.length));
    if (templates === false || (templates !== true && catalogRuns)) {
        const { query_templates: _dropped, ...rest } = model;
        return rest;
    }
    return model;
}
const GET_DATA_MODEL_141 = 'BEST FIRST CALL. Condensed model of every table in one response: columns (name/type/FK), row counts, self-referencing hierarchies and junction tables. Use it to plan queries before get_table_data; reach for the per-table schema tools only when one table needs more detail.';
const GET_DATA_MODEL_SEMANTIC = 'Condensed model of every table: columns (name/type/FK), row counts, hierarchies, junction tables. Call get_semantics first; use this to plan any query the business definitions do not already answer.';
const GET_DATA_MODEL_INDEX = ' On larger databases the first call returns an index; pass tables for the details you need.';
function buildBaseTools(f) {
    const tools = [
        {
            name: 'get_api_spec',
            title: 'Get API Spec',
            description: 'OpenAPI 3.0 spec for this database: endpoint paths, query-parameter syntax and, with tables=true, every table. Use it when you need exact filter/order syntax or endpoint details; get_data_model is the cheaper call for planning queries. compact=true (default) returns the token-efficient form.',
            schema: z.object({
                compact: z.boolean().optional().describe('Return compact token-efficient format (default: true)'),
                resource_name: z.string().optional().describe('Get spec for a specific table/resource only'),
                tables: z.boolean().optional().describe('Include full table and field details'),
                refresh: z.boolean().optional().describe('Force refresh cached spec data')
            }),
            handler: async (args, _context, apiConfig, auth) => {
                const options = { compact: true, ...args };
                const data = await DreamFactoryService.getApiSpec(apiConfig.baseUrl, auth, options);
                return respond(data);
            }
        },
        {
            name: 'get_semantics',
            title: 'Get Business Definitions',
            description: 'CALL FIRST. Approved business definitions for this database: what terms like "current", "active" or "headcount" mean in the data, metrics and verified queries with the exact tool arguments to run them. Small; follow these definitions instead of guessing.',
            schema: z.object({}),
            handler: async (_args, _context, apiConfig, auth, call) => {
                const data = await DreamFactoryService.getApiSpec(apiConfig.baseUrl, auth, { model: 'semantics' });
                // Without df-semantic, df-core ignores model=semantics and answers with
                // the whole OpenAPI document (no `semantics` key). Never relay that.
                if (!data || typeof data !== 'object' || !('semantics' in data)) {
                    return respond({ semantics: null, note: 'No semantic catalog on this server. Use get_data_model.' });
                }
                if (!data.semantics) {
                    return respond({ semantics: null, note: 'No approved business definitions for this database. Use get_data_model.' });
                }
                return respond(adaptSemantics(data, call));
            }
        },
        {
            name: 'get_data_model',
            title: 'Get Data Model',
            description: (f.semanticCatalog ? GET_DATA_MODEL_SEMANTIC : GET_DATA_MODEL_141) + (f.compactResults ? GET_DATA_MODEL_INDEX : ''),
            schema: z.object({
                ...(f.compactResults ? {
                    tables: z.array(z.string()).optional().describe('Only these tables, with samples and enumerations (plus their relationships). Omit for all.'),
                    detail: z.boolean().optional().describe('Full detail for every table even on a large database (default: index above 12 tables)'),
                    samples: z.number().int().min(0).max(3).optional().describe('Sample rows per table (default 1; 0 for none)')
                } : {}),
                ...(f.semanticCatalog ? {
                    templates: z.boolean().optional().describe('Include query templates (default: only when no catalog query exists)')
                } : {}),
                refresh: z.boolean().optional().describe('Bypass the cached model and rebuild it from the database (slow on large schemas).')
            }),
            handler: async (args, _context, apiConfig, auth, call) => {
                let model = await DreamFactoryService.getApiSpec(apiConfig.baseUrl, auth, {
                    model: true,
                    refresh: args?.refresh,
                    ...(f.semanticCatalog ? { enrich: true } : {})
                });
                if (f.compactResults)
                    model = slimDataModel(model, { tables: args?.tables, samples: args?.samples, detail: args?.detail });
                if (f.semanticCatalog)
                    model = dropTemplates(model, args?.templates);
                model = adaptQueryTemplates(model, call);
                return respond(f.semanticCatalog ? adaptSemantics(model, call) : model);
            }
        },
        {
            name: 'get_tables',
            title: 'List Tables',
            description: 'Table names only, the cheapest schema call. Use it to confirm a table exists; get_data_model also returns columns and relationships for every table.',
            schema: z.object({}),
            handler: async (_args, _context, apiConfig, auth) => {
                const data = await DreamFactoryService.getTables(apiConfig.baseUrl, auth);
                return respond(data);
            }
        },
        {
            name: 'get_table_schema',
            title: 'Get Table Schema',
            description: 'Full definition of ONE table: columns with types, nullability and defaults, primary and foreign keys, and the related-table aliases usable in get_table_data related=. Use it after get_data_model when one table needs closer inspection. get_table_fields returns only the column list and get_table_relationships only the relationship graph.',
            schema: z.object({ table_name: z.string().describe('Exact table name as returned by get_tables or get_data_model.') }),
            handler: async ({ table_name }, _context, apiConfig, auth) => {
                const data = await DreamFactoryService.getTableSchema(table_name, apiConfig.baseUrl, auth);
                return respond(data);
            }
        },
        {
            name: 'get_table_data',
            title: 'Get Table Data',
            description: f.compactResults
                ? 'Read rows from one table with filter/order/limit/offset. Returns 50 rows unless limit is set (max 1000); meta.next tells you when there are more. count_only=true returns just the count. For SUM/COUNT/AVG/MIN/MAX/GROUP BY use aggregate_data — this tool cannot aggregate.'
                : 'Read rows from one table with filter/order/limit/offset; returns a resource array (plus meta.count when include_count is set). Max 1000 rows per call; paginate with offset for more. count_only=true returns just the count. For SUM/COUNT/AVG/MIN/MAX/GROUP BY use aggregate_data — this tool cannot aggregate.',
            schema: z.object({
                table_name: z.string().describe('Exact table name from get_tables or get_data_model.'),
                fields: z.array(z.string()).optional().describe('Columns to return (default: all). Fewer columns means a smaller response.'),
                filter: z.string().optional().describe('SQL-like WHERE clause: field=value, field>10, field LIKE %text%, field IN (1,2), field IS NULL; combine with AND/OR in parentheses, e.g. (status=open) AND (amount>100). Use bare field names, no quotes or brackets around them.'),
                offset: z.number().optional().describe('Rows to skip before the first returned row; combine with limit to page.'),
                limit: z.number().optional().describe(f.compactResults
                    ? 'Max records per request (default 50, server max 1000). Use with offset to paginate.'
                    : 'Max records per request (server max: 1000). Use with offset to paginate.'),
                order: z.string().optional().describe('Sort clause: "field ASC" or "field DESC", comma-separated for several, e.g. "created DESC, id ASC".'),
                continue: z.boolean().optional().describe('Rarely needed on reads: continue past per-row errors in a batch instead of failing the whole request.'),
                related: z.string().optional().describe('Include related records via FK (e.g. "parent_table_by_fk_field"). Check relationships in the data model.'),
                count_only: z.boolean().optional().describe('Return only the record count, no data. Use this instead of COUNT() in fields.'),
                include_count: z.boolean().optional().describe('Include total record count in response metadata alongside data.'),
                include_schema: z.boolean().optional().describe('Append the table schema to the response (adds size; prefer get_table_schema).'),
                ids: z.array(z.union([z.string(), z.number()])).optional().describe('Primary-key values to fetch directly, instead of a filter.')
            }),
            handler: async (args, _context, apiConfig, auth) => {
                // An unbounded read returns the server maximum (1000 rows, ~180 KB on a
                // wide table). Page by default; the model can raise limit or paginate.
                // compact_results only; off, 1.4.1 sends the read through unchanged.
                const wantsAll = args?.count_only || (Array.isArray(args?.ids) && args.ids.length > 0);
                const params = f.compactResults && args?.limit === undefined && !wantsAll ? { ...args, limit: DEFAULT_ROW_LIMIT, include_count: args?.include_count ?? true } : args;
                const data = await DreamFactoryService.getTableData(apiConfig.baseUrl, auth, params);
                return respond(data);
            }
        },
        {
            name: 'create_records',
            title: 'Create Records',
            description: 'Insert one or more rows into a table. Returns the primary key of each new row (or the columns named in fields). Not idempotent: repeating the call inserts again. To change existing rows use update_records.',
            schema: z.object({
                table_name: z.string().describe('Exact table name from get_tables or get_data_model.'),
                records: z.array(z.record(z.string(), z.unknown())).describe('Rows to insert, one object per row keyed by column name. Omit auto-increment keys.'),
                fields: z.array(z.string()).optional().describe('Columns to return for each created row (default: primary key only).'),
                related: z.string().optional().describe('Related-table alias whose nested records inside each row should be created too, e.g. "orders_by_customer_id".'),
                continue: z.boolean().optional().describe('On a per-row error keep inserting the remaining rows and report each failure, instead of stopping at the first.'),
                rollback: z.boolean().optional().describe('On any per-row error undo the rows this call already inserted (all or nothing).')
            }),
            handler: async ({ table_name, records, ...options }, _context, apiConfig, auth) => {
                const data = await DreamFactoryService.createRecords(table_name, apiConfig.baseUrl, auth, records, options);
                return respond(data);
            }
        },
        {
            name: 'update_records',
            title: 'Update Records',
            description: 'Patch existing rows: only the columns present in each record change. Target rows one of three ways: records that each carry the primary key; ids plus a single record applied to every id; or filter plus a single record applied to every match. Returns the primary keys (or fields) of the rows changed.',
            schema: z.object({
                table_name: z.string().describe('Exact table name from get_tables or get_data_model.'),
                records: z.array(z.record(z.string(), z.unknown())).describe('Column/value pairs to apply. One object per row when each carries its primary key; a single object when targeting by ids or filter.'),
                fields: z.array(z.string()).optional().describe('Columns to return for each updated row (default: primary key only).'),
                related: z.string().optional().describe('Related-table alias whose nested records inside each row should be updated too.'),
                ids: z.array(z.union([z.string(), z.number()])).optional().describe('Primary-key values of the rows to patch with the single record.'),
                filter: z.string().optional().describe('SQL-like filter (same syntax as get_table_data) selecting the rows to patch with the single record.'),
                continue: z.boolean().optional().describe('On a per-row error keep updating the remaining rows and report each failure, instead of stopping at the first.'),
                rollback: z.boolean().optional().describe('On any per-row error undo the rows this call already changed (all or nothing).')
            }),
            handler: async ({ table_name, records, ...options }, _context, apiConfig, auth) => {
                const data = await DreamFactoryService.updateRecords(table_name, apiConfig.baseUrl, auth, records, options);
                return respond(data);
            }
        },
        {
            name: 'delete_records',
            title: 'Delete Records',
            description: 'Permanently delete rows selected by ids or filter; there is no undo. A call with neither ids nor filter is refused unless force=true, which empties the whole table. Returns the primary keys of the deleted rows.',
            schema: z.object({
                table_name: z.string().describe('Exact table name from get_tables or get_data_model.'),
                ids: z.array(z.union([z.string(), z.number()])).optional().describe('Primary-key values of the rows to delete.'),
                filter: z.string().optional().describe('SQL-like filter (same syntax as get_table_data) selecting the rows to delete.'),
                force: z.boolean().optional().describe('true deletes EVERY row in the table when no ids or filter are given. Never set it casually.'),
                fields: z.array(z.string()).optional().describe('Columns of each deleted row to return (default: primary key only).'),
                related: z.string().optional().describe('Related-table alias whose records should be included in the response for each deleted row.'),
                continue: z.boolean().optional().describe('On a per-row error keep deleting the remaining rows and report each failure, instead of stopping at the first.'),
                rollback: z.boolean().optional().describe('On any per-row error restore the rows this call already deleted (all or nothing).')
            }),
            handler: async ({ table_name, ...options }, _context, apiConfig, auth) => {
                const data = await DreamFactoryService.deleteRecords(table_name, apiConfig.baseUrl, auth, options);
                return respond(data);
            }
        },
        {
            name: 'get_table_fields',
            title: 'Get Table Fields',
            description: 'Column list for one table: name, type, length, nullable, default, primary/foreign-key flags. Lighter than get_table_schema, which also returns constraints and related tables; get_data_model already lists these columns for every table.',
            schema: z.object({
                table_name: z.string().describe('Exact table name from get_tables or get_data_model.'),
                refresh: z.boolean().optional().describe('Bypass the schema cache and re-read from the database.')
            }),
            handler: async ({ table_name, refresh }, _context, apiConfig, auth) => {
                const data = await DreamFactoryService.getTableFields(table_name, apiConfig.baseUrl, auth, refresh);
                return respond(data);
            }
        },
        {
            name: 'get_table_relationships',
            title: 'Get Table Relationships',
            description: 'Relationships of one table: foreign keys in and out, self-referencing hierarchies (parent_id-style trees) and junction tables for many-to-many. Use it to pick the related= alias for get_table_data; get_data_model summarizes the same relationships for every table.',
            schema: z.object({
                table_name: z.string().describe('Exact table name from get_tables or get_data_model.'),
                refresh: z.boolean().optional().describe('Bypass the schema cache and re-read from the database.')
            }),
            handler: async ({ table_name, refresh }, _context, apiConfig, auth) => {
                const data = await DreamFactoryService.getTableRelationships(table_name, apiConfig.baseUrl, auth, refresh);
                return respond(data);
            }
        },
        {
            name: 'get_stored_procedures',
            title: 'List Stored Procedures',
            description: 'List the stored procedures this database exposes, with their parameter names and types, callable through call_stored_procedure. Empty on databases without procedures (e.g. SQLite).',
            schema: z.object({}),
            handler: async (_args, _context, apiConfig, auth) => {
                const data = await DreamFactoryService.getStoredProcedures(apiConfig.baseUrl, auth);
                return respond(data);
            }
        },
        {
            name: 'call_stored_procedure',
            title: 'Call Stored Procedure',
            description: 'Execute a stored procedure by name with named parameters and return its result sets and output parameters. Procedures can modify data: check what the procedure does (get_stored_procedures) before calling it.',
            schema: z.object({
                procedure_name: z.string().describe('Procedure name exactly as listed by get_stored_procedures.'),
                parameters: z.record(z.string(), z.unknown()).optional().describe('Input parameters keyed by parameter name.'),
                wrapper: z.string().optional().describe('Key under which to wrap the returned result set in the response.'),
                returns: z.string().optional().describe('Declared data type of the procedure\'s scalar return value, e.g. "integer", when the database cannot infer it.')
            }),
            handler: async ({ procedure_name, parameters, wrapper, returns }, _context, apiConfig, auth) => {
                const data = await DreamFactoryService.callStoredProcedure(procedure_name, apiConfig.baseUrl, auth, parameters, wrapper, returns);
                return respond(data);
            }
        },
        {
            name: 'get_stored_functions',
            title: 'List Stored Functions',
            description: 'List the stored functions this database exposes, with their parameter names and return types, callable through call_stored_function. Empty on databases without functions.',
            schema: z.object({}),
            handler: async (_args, _context, apiConfig, auth) => {
                const data = await DreamFactoryService.getStoredFunctions(apiConfig.baseUrl, auth);
                return respond(data);
            }
        },
        {
            name: 'call_stored_function',
            title: 'Call Stored Function',
            description: 'Execute a stored (scalar or table-valued) function by name with named parameters and return its value. Functions usually only read, but treat one as able to write unless you know otherwise.',
            schema: z.object({
                function_name: z.string().describe('Function name exactly as listed by get_stored_functions.'),
                parameters: z.record(z.string(), z.unknown()).optional().describe('Input parameters keyed by parameter name.'),
                returns: z.string().optional().describe('Declared data type of the return value, e.g. "integer", when the database cannot infer it.')
            }),
            handler: async ({ function_name, parameters, returns }, _context, apiConfig, auth) => {
                const data = await DreamFactoryService.callStoredFunction(function_name, apiConfig.baseUrl, auth, parameters, returns);
                return respond(data);
            }
        },
        {
            name: 'get_database_resources',
            title: 'List Database Resources',
            description: 'List the resource groups this database service exposes (_table, _schema, _proc, _func) with their member names. Use it to check whether a service has procedures or functions at all; for table work use get_tables or get_data_model.',
            schema: z.object({
                as_list: z.boolean().optional().describe('Return bare resource names only.'),
                as_access_list: z.boolean().optional().describe('Return every resource/verb combination the current role may call (for checking permissions).'),
                include_access: z.boolean().optional().describe('Add the HTTP verbs the current role may use on each resource.'),
                fields: z.array(z.string()).optional().describe('Resource properties to include (default: name only).'),
                refresh: z.boolean().optional().describe('Bypass the cached resource list.')
            }),
            handler: async (args, _context, apiConfig, auth) => {
                const data = await DreamFactoryService.getDatabaseResources(apiConfig.baseUrl, auth, args);
                return respond(data);
            }
        },
        {
            name: 'aggregate_data',
            title: 'Aggregate Data',
            description: f.compactResults
                ? 'THE ONLY WAY to SUM/COUNT/AVG/MIN/MAX/GROUP BY: pushes the aggregation to the database and returns one row per group. Always pass group_by when the question is per category. Grouped results return 100 groups unless limit is set; the response says when there are more. Do not use get_table_data for aggregation; use it for raw rows instead.'
                : 'THE ONLY WAY to SUM/COUNT/AVG/MIN/MAX/GROUP BY: pushes the aggregation to the database and returns one row per group. Always pass group_by when the question is per category. Do not use get_table_data for aggregation; use it for raw rows instead.',
            schema: z.object({
                table_name: z.string().describe('Table to aggregate'),
                aggregates: z.array(z.object({
                    function: z.enum(['SUM', 'COUNT', 'AVG', 'MIN', 'MAX']).describe('Aggregate function'),
                    field: z.string().describe('Column to aggregate (use "*" for COUNT)'),
                    alias: z.string().optional().describe('Name for the result column')
                })).describe('List of aggregations to compute'),
                filter: z.string().optional().describe('Filter rows before aggregating (same syntax as get_table_data)'),
                group_by: z.array(z.string()).optional().describe('Group results by these columns'),
                ...(f.compactResults ? {
                    limit: z.number().int().min(1).max(MAX_GROUP_LIMIT).optional().describe(`Max groups to return (default ${DEFAULT_GROUP_LIMIT}, max ${MAX_GROUP_LIMIT}). Narrow with filter when truncated.`)
                } : {})
            }),
            handler: async (args, _context, apiConfig, auth) => {
                const data = await DreamFactoryService.aggregateData(apiConfig.baseUrl, auth, args, {
                    aliases: f.semanticCatalog,
                    groupLimit: f.compactResults ? (args?.limit ?? DEFAULT_GROUP_LIMIT) : undefined
                });
                return respond(data);
            }
        }
    ];
    return f.semanticCatalog ? tools : tools.filter(t => t.name !== 'get_semantics');
}
const builtTools = new Map();
/** Database verb definitions for a feature set (built once per combination, so schemas are shared by reference). */
export function baseTools(f = {}) {
    const key = `${!!f.semanticCatalog}/${!!f.compactResults}`;
    let tools = builtTools.get(key);
    if (!tools) {
        tools = buildBaseTools({ semanticCatalog: !!f.semanticCatalog, compactResults: !!f.compactResults });
        builtTools.set(key, tools);
    }
    return tools;
}
/** The 1.4.1 database verbs (every opt-in off). */
export const BASE_TOOLS = baseTools();
/** Base tool names for database services (1.4.1's set; every opt-in off). */
export const DB_TOOL_NAMES = BASE_TOOLS.map(t => t.name);
/**
 * Merged database registration (tool_style = 'merged'): every verb once, with a
 * `service` argument choosing the database. The per-service disable semantics
 * and the single-service collapse live in registerMergedTools, which file
 * services share.
 */
function registerMergedDatabaseTools(server, sessionManager, dbConfigs, disabledTools, tools = BASE_TOOLS) {
    registerMergedTools(server, sessionManager, dbConfigs, tools, {
        targetNoun: 'database',
        logLabel: 'merged-tools',
        disabledTools
    });
}
export function registerDreamFactoryTools(server, sessionManager, apiConfigs, disabledTools, toolStyle = 'prefixed', allowWrites = true, features = {}) {
    const registerTool = createToolRegistrar(server, disabledTools);
    // The database verbs for this endpoint's opt-ins (1.4.1's when all are off).
    const BASE_TOOLS = baseTools(features);
    // Register API connector tools (list_apis, all_get_tables, etc.)
    registerApiConnectorTools(server, sessionManager, apiConfigs, disabledTools);
    // Register file API tools
    registerFileApiTools(server, sessionManager, apiConfigs, disabledTools, allowWrites, toolStyle);
    // Filter to database services only for database tools
    const dbConfigs = apiConfigs.filter(c => c.category === 'database');
    // allow_writes=false: write verbs are not registered at all, so they are
    // absent from tools/list and from the lazy catalog (call_tool cannot reach them).
    const tools = allowWrites ? BASE_TOOLS : BASE_TOOLS.filter(t => !WRITE_VERBS.has(t.name));
    if (toolStyle === 'merged') {
        registerMergedDatabaseTools(server, sessionManager, dbConfigs, disabledTools, tools);
    }
    else {
        // Register prefixed tools for each database API
        for (const apiConfig of dbConfigs) {
            const prefix = sanitizeApiName(apiConfig.name);
            for (const tool of tools) {
                const prefixedName = `${prefix}_${tool.name}`;
                const prefixedTitle = `${apiConfig.name}: ${tool.title}`;
                const prefixedDescription = `[${apiConfig.name}] ${tool.description}`;
                registerTool(prefixedName, prefixedTitle, prefixedDescription, tool.schema, async (params, context) => {
                    const auth = getAuth(sessionManager, context.sessionId);
                    return tool.handler(params, context, apiConfig, auth, { prefix });
                });
            }
        }
    }
    // Stub tools for connectors (ChatGPT) that refuse servers without search/fetch.
    // They do nothing; the descriptions say so and point at the real tools.
    registerTool('search', 'Search (connector stub)', 'Compatibility stub for connectors (such as ChatGPT) that require a search tool. It searches nothing and always returns an empty results list. To find data, call get_data_model and then get_table_data with a filter; to locate a table by name across databases use all_find_table.', z.object({ query: z.string().describe('Ignored; any text.') }), async () => ({
        content: [{ type: 'text', text: JSON.stringify({ results: [] }) }]
    }));
    registerTool('fetch', 'Fetch (connector stub)', 'Compatibility stub for connectors (such as ChatGPT) that require a fetch tool. It fetches nothing and returns an empty document for any id. To read specific rows, call get_table_data with ids.', z.object({ id: z.string().describe('Ignored; any string.') }), async ({ id }) => ({
        content: [
            {
                type: 'text',
                text: JSON.stringify({
                    id,
                    title: '',
                    text: '',
                    url: '',
                    metadata: null
                })
            }
        ]
    }));
}
