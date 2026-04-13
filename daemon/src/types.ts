export type ServiceCategory = 'database' | 'file';

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
