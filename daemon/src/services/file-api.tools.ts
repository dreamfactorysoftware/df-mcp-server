import * as z from 'zod/v4';
import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { DreamFactoryService, type DFAuthConfig } from './dreamfactory.service.js';
import { SessionService } from './session.service.js';
import type { ApiConfig } from '../types.js';

type ToolResponse = {
  content: Array<{ type: 'text'; text: string }>;
  isError?: boolean;
};

type FileToolDefinition = {
  name: string;
  title: string;
  description: string;
  schema: z.ZodTypeAny;
  handler: (
    params: any,
    context: { sessionId?: string },
    apiConfig: ApiConfig,
    auth: DFAuthConfig
  ) => Promise<ToolResponse>;
};

const respond = (data: unknown): ToolResponse => ({
  content: [{ type: 'text', text: JSON.stringify(data, null, 2) }]
});

const respondError = (message: string): ToolResponse => ({
  content: [{ type: 'text', text: message }],
  isError: true
});

const handleError = (error: unknown, operation: string): string => {
  if (!(error instanceof Error)) {
    return `Unknown error during ${operation}: ${String(error)}`;
  }
  return `Error during ${operation}: ${error.message}`;
};

/**
 * Sanitize API name for use as a tool prefix.
 */
function sanitizeApiName(name: string): string {
  return name
    .toLowerCase()
    .replace(/[^a-z0-9]/g, '_')
    .replace(/_+/g, '_')
    .replace(/^_|_$/g, '');
}

/**
 * Base file tool definitions that will be registered for each file API.
 */
const FILE_TOOLS: FileToolDefinition[] = [
  {
    name: 'list_files',
    title: 'List Files',
    description: 'List files and folders in a path',
    schema: z.object({
      path: z.string().optional().describe('Path to list (empty for root)'),
      includeFiles: z.boolean().optional().describe('Include files in listing'),
      includeFolders: z.boolean().optional().describe('Include folders in listing'),
      fullTree: z.boolean().optional().describe('Return full directory tree')
    }),
    handler: async ({ path, ...options }, _context, apiConfig, auth) => {
      const data = await DreamFactoryService.listFiles(apiConfig.baseUrl, auth, path ?? '', options);
      return respond(data);
    }
  },
  {
    name: 'get_file',
    title: 'Get File Content',
    description: 'Get the content of a file',
    schema: z.object({
      path: z.string().describe('Path to the file')
    }),
    handler: async ({ path }, _context, apiConfig, auth) => {
      const data = await DreamFactoryService.getFileContent(apiConfig.baseUrl, auth, path);
      return respond(data);
    }
  },
  {
    name: 'create_file',
    title: 'Create File',
    description: 'Create a new file with the given content',
    schema: z.object({
      path: z.string().describe('Path for the new file (e.g. folder/filename.txt)'),
      content: z.string().describe('Content to write to the file')
    }),
    handler: async ({ path, content }, _context, apiConfig, auth) => {
      const data = await DreamFactoryService.createFile(apiConfig.baseUrl, auth, path, content);
      return respond(data);
    }
  },
  {
    name: 'get_file_properties',
    title: 'Get File Properties',
    description: 'Get properties/metadata of a file or folder',
    schema: z.object({
      path: z.string().describe('Path to the file or folder')
    }),
    handler: async ({ path }, _context, apiConfig, auth) => {
      const data = await DreamFactoryService.getFileProperties(apiConfig.baseUrl, auth, path);
      return respond(data);
    }
  },
  {
    name: 'create_folder',
    title: 'Create Folder',
    description: 'Create a new folder',
    schema: z.object({
      path: z.string().describe('Path for the new folder')
    }),
    handler: async ({ path }, _context, apiConfig, auth) => {
      const data = await DreamFactoryService.createFolder(apiConfig.baseUrl, auth, path);
      return respond(data);
    }
  },
  {
    name: 'delete_file',
    title: 'Delete File or Folder',
    description: 'Delete a file or folder',
    schema: z.object({
      path: z.string().describe('Path to the file or folder to delete'),
      force: z.boolean().optional().describe('Force delete non-empty folders')
    }),
    handler: async ({ path, force }, _context, apiConfig, auth) => {
      const data = await DreamFactoryService.deleteFile(apiConfig.baseUrl, auth, path, { force });
      return respond(data);
    }
  }
];

/**
 * Register file API tools for each file service.
 */
export function registerFileApiTools(
  server: McpServer,
  sessionManager: SessionService,
  apiConfigs: ApiConfig[]
) {
  const fileConfigs = apiConfigs.filter(c => c.category === 'file');

  if (fileConfigs.length === 0) {
    console.log('[registerFileApiTools] No file services found, skipping file tools registration');
    return;
  }

  console.log('[registerFileApiTools] Registering tools for file services:', fileConfigs.map(c => c.name));

  const getAuth = (sessionId?: string): DFAuthConfig => {
    const sessionConfig = sessionId ? sessionManager.getConfig(sessionId) : undefined;
    const sessionToken = sessionConfig?.sessionToken ?? '';
    const apiKey = sessionConfig?.apiKey;

    if (!sessionToken) {
      throw new Error('DreamFactory session not found. Please authenticate via OAuth.');
    }

    return { sessionToken, apiKey };
  };

  const registerTool = (
    name: string,
    title: string,
    description: string,
    schema: z.ZodTypeAny,
    handler: (params: any, context: { sessionId?: string }) => Promise<ToolResponse>
  ) => {
    server.registerTool(
      name,
      { title, description, inputSchema: schema },
      async (params, context) => {
        try {
          return await handler(params ?? {}, context ?? {});
        } catch (error) {
          console.error(`Tool ${name} error:`, error);
          return respondError(handleError(error, name));
        }
      }
    );
  };

  // Register prefixed tools for each file API
  for (const apiConfig of fileConfigs) {
    const prefix = sanitizeApiName(apiConfig.name);

    for (const tool of FILE_TOOLS) {
      const prefixedName = `${prefix}_${tool.name}`;
      const prefixedTitle = `${apiConfig.name}: ${tool.title}`;
      const prefixedDescription = `[${apiConfig.name}] ${tool.description}`;

      registerTool(
        prefixedName,
        prefixedTitle,
        prefixedDescription,
        tool.schema,
        async (params, context) => {
          const auth = getAuth(context.sessionId);
          return tool.handler(params, context, apiConfig, auth);
        }
      );
    }
  }

  // Register cross-file-service tools
  registerTool(
    'all_list_files',
    'List Files from All Storage Services',
    'List root files and folders from all connected file storage services',
    z.object({
      path: z.string().optional().describe('Path to list (empty for root)')
    }),
    async ({ path }, { sessionId }) => {
      const auth = getAuth(sessionId);
      const results: Record<string, unknown> = {};

      await Promise.all(
        fileConfigs.map(async (api) => {
          try {
            const files = await DreamFactoryService.listFiles(api.baseUrl, auth, path ?? '');
            results[api.name] = { success: true, data: files };
          } catch (error) {
            const message = error instanceof Error ? error.message : String(error);
            results[api.name] = { success: false, error: message };
          }
        })
      );

      return respond({ storages: results });
    }
  );
}
