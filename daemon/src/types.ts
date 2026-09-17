export type ServiceCategory = 'database' | 'file';

/**
 * How database tools are exposed.
 *  'prefixed' (default, unchanged): one copy of every verb per service
 *      (sales_get_table_data, orders_get_table_data, ...). N services = N x 16 tools.
 *  'merged': one copy of each verb, with the service chosen by a `service`
 *      argument. Single-database endpoints omit the argument entirely.
 */
export type ToolStyle = 'prefixed' | 'merged';

export type ApiConfig = {
  name: string;
  baseUrl: string;
  category: ServiceCategory;
  type: string; // The specific service type (e.g., 'sqlite', 'local_file')
};

export type CustomToolParameter = {
  name: string;
  type: 'string' | 'number' | 'boolean' | 'integer';
  in: 'path' | 'query' | 'body' | 'header';
  required: boolean;
  description?: string;
};

export type CustomToolDefinition = {
  name: string;
  description: string;
  tool_type?: 'api' | 'function';
  http_method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  url?: string;
  parameters: CustomToolParameter[];
  headers?: Record<string, string>;
  function?: string;
  secrets?: Record<string, string>;
};
