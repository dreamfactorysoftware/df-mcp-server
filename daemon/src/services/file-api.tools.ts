import * as z from 'zod/v4';
import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { DreamFactoryService, type DFAuthConfig, type FileContentResult } from './dreamfactory.service.js';
import { SessionService } from './session.service.js';
import type { ApiConfig, ToolStyle } from '../types.js';
import { type ToolResponse, respond, sanitizeApiName, getAuth, createToolRegistrar, registerMergedTools, WRITE_VERBS } from './tool-utils.js';

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

function fileContentToToolResponse(result: FileContentResult): ToolResponse {
  switch (result.kind) {
    case 'image':
      return { content: [{ type: 'image', data: result.data, mimeType: result.mimeType }] };
    case 'audio':
      return { content: [{ type: 'audio', data: result.data, mimeType: result.mimeType }] };
    case 'text':
      return { content: [{ type: 'text', text: result.content }] };
  }
}

/**
 * Base file tool definitions that will be registered for each file API.
 */
const FILE_TOOLS: FileToolDefinition[] = [
  {
    name: 'list_files',
    title: 'List Files',
    description: 'List files and folders under a path in this file service: names, paths, sizes and last-modified times. One level only unless full_tree=true.',
    schema: z.object({
      path: z.string().optional().describe('Folder path relative to the service root; empty or omitted for the root.'),
      include_files: z.boolean().optional().describe('Include files in the listing (default true).'),
      include_folders: z.boolean().optional().describe('Include folders in the listing (default true).'),
      full_tree: z.boolean().optional().describe('Recurse into every subfolder (can be large).')
    }),
    handler: async ({ path, ...options }, _context, apiConfig, auth) => {
      const data = await DreamFactoryService.listFiles(apiConfig.baseUrl, auth, path ?? '', options);
      return respond(data);
    }
  },
  {
    name: 'get_file',
    title: 'Get File Content',
    description: 'Return one file\'s content. Text-like files (text, JSON, XML) come back as text; images and audio as base64 with their MIME type. Use get_file_properties for size and modified time without downloading.',
    schema: z.object({
      path: z.string().describe('File path relative to the service root, e.g. reports/2024/q1.csv.')
    }),
    handler: async ({ path }, _context, apiConfig, auth) => {
      const result = await DreamFactoryService.getFileContent(apiConfig.baseUrl, auth, path);
      return fileContentToToolResponse(result);
    }
  },
  {
    name: 'create_file',
    title: 'Create File',
    description: 'Write a text file at path, creating it or replacing an existing file at the same path. Returns the written path. Create the parent folder with create_folder first if it may not exist.',
    schema: z.object({
      path: z.string().describe('Path for the file relative to the service root, e.g. folder/filename.txt.'),
      content: z.string().describe('Full text content to write; replaces any existing content.')
    }),
    handler: async ({ path, content }, _context, apiConfig, auth) => {
      const data = await DreamFactoryService.createFile(apiConfig.baseUrl, auth, path, content);
      return respond(data);
    }
  },
  {
    name: 'get_file_properties',
    title: 'Get File Properties',
    description: 'Metadata for one file or folder (name, path, type, size, content type, last modified) without returning its content. Use get_file to read the content.',
    schema: z.object({
      path: z.string().describe('File or folder path relative to the service root.')
    }),
    handler: async ({ path }, _context, apiConfig, auth) => {
      const data = await DreamFactoryService.getFileProperties(apiConfig.baseUrl, auth, path);
      return respond(data);
    }
  },
  {
    name: 'create_folder',
    title: 'Create Folder',
    description: 'Create a folder at path in this file service. Check with list_files on the parent first if the folder may already exist.',
    schema: z.object({
      path: z.string().describe('Folder path relative to the service root, e.g. reports/2024.')
    }),
    handler: async ({ path }, _context, apiConfig, auth) => {
      const data = await DreamFactoryService.createFolder(apiConfig.baseUrl, auth, path);
      return respond(data);
    }
  },
  {
    name: 'delete_file',
    title: 'Delete File or Folder',
    description: 'Permanently delete a file, or a folder (with force=true when it is not empty). There is no undo.',
    schema: z.object({
      path: z.string().describe('File or folder path relative to the service root.'),
      force: z.boolean().optional().describe('Delete a non-empty folder together with everything inside it.')
    }),
    handler: async ({ path, force }, _context, apiConfig, auth) => {
      const data = await DreamFactoryService.deleteFile(apiConfig.baseUrl, auth, path, { force });
      return respond(data);
    }
  }
];

/** Base tool names for file services. */
export const FILE_TOOL_NAMES = FILE_TOOLS.map(t => t.name);

/**
 * Register file API tools.
 *
 * Prefixed mode emits every verb once per file service (logs_list_files,
 * files_list_files, ...). Merged mode registers each verb once and takes a
 * `service` argument, the same shape the database tools use, which is where a
 * connection with several file services spends most of its catalog.
 */
export function registerFileApiTools(
  server: McpServer,
  sessionManager: SessionService,
  apiConfigs: ApiConfig[],
  disabledTools?: Set<string>,
  allowWrites = true,
  toolStyle: ToolStyle = 'prefixed'
) {
  const fileConfigs = apiConfigs.filter(c => c.category === 'file');
  const tools = allowWrites ? FILE_TOOLS : FILE_TOOLS.filter(t => !WRITE_VERBS.has(t.name));

  if (fileConfigs.length === 0) {
    console.log('[registerFileApiTools] No file services found, skipping file tools registration');
    return;
  }

  console.log('[registerFileApiTools] Registering tools for file services:', fileConfigs.map(c => c.name));

  const registerTool = createToolRegistrar(server, disabledTools);

  if (toolStyle === 'merged') {
    registerMergedTools(server, sessionManager, fileConfigs, tools, {
      targetNoun: 'file service',
      logLabel: 'merged-file-tools',
      disabledTools
    });
  } else {
    // Register prefixed tools for each file API
    for (const apiConfig of fileConfigs) {
      const prefix = sanitizeApiName(apiConfig.name);

      for (const tool of tools) {
        const prefixedName = `${prefix}_${tool.name}`;
        const prefixedTitle = `${apiConfig.name}: ${tool.title}`;
        const prefixedDescription = `[${apiConfig.name}] ${tool.description}`;

        registerTool(
          prefixedName,
          prefixedTitle,
          prefixedDescription,
          tool.schema,
          async (params, context) => {
            const auth = getAuth(sessionManager, context.sessionId);
            return tool.handler(params, context, apiConfig, auth);
          }
        );
      }
    }
  }

  // Cross-service aggregator only pays off with 2+ file services.
  if (fileConfigs.length < 2) {
    return;
  }

  registerTool(
    'all_list_files',
    'List Files from All Storage Services',
    'Files and folders at one path (default: root) from every connected file service in one call, keyed by service. For one service call list_files.',
    z.object({
      path: z.string().optional().describe('Path to list (empty for root)')
    }),
    async ({ path }, { sessionId }) => {
      const auth = getAuth(sessionManager, sessionId);
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
