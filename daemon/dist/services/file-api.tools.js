import * as z from 'zod/v4';
import { DreamFactoryService } from './dreamfactory.service.js';
import { respond, sanitizeApiName, getAuth, createToolRegistrar, registerMergedTools, WRITE_VERBS } from './tool-utils.js';
function fileContentToToolResponse(result) {
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
const FILE_TOOLS = [
    {
        name: 'list_files',
        title: 'List Files',
        description: 'List files and folders in a path',
        schema: z.object({
            path: z.string().optional().describe('Path to list (empty for root)'),
            include_files: z.boolean().optional().describe('Include files in listing'),
            include_folders: z.boolean().optional().describe('Include folders in listing'),
            full_tree: z.boolean().optional().describe('Return full directory tree')
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
            const result = await DreamFactoryService.getFileContent(apiConfig.baseUrl, auth, path);
            return fileContentToToolResponse(result);
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
export function registerFileApiTools(server, sessionManager, apiConfigs, disabledTools, allowWrites = true, toolStyle = 'prefixed') {
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
    }
    else {
        // Register prefixed tools for each file API
        for (const apiConfig of fileConfigs) {
            const prefix = sanitizeApiName(apiConfig.name);
            for (const tool of tools) {
                const prefixedName = `${prefix}_${tool.name}`;
                const prefixedTitle = `${apiConfig.name}: ${tool.title}`;
                const prefixedDescription = `[${apiConfig.name}] ${tool.description}`;
                registerTool(prefixedName, prefixedTitle, prefixedDescription, tool.schema, async (params, context) => {
                    const auth = getAuth(sessionManager, context.sessionId);
                    return tool.handler(params, context, apiConfig, auth);
                });
            }
        }
    }
    // Cross-service aggregator only pays off with 2+ file services.
    if (fileConfigs.length < 2) {
        return;
    }
    registerTool('all_list_files', 'List Files from All Storage Services', 'List root files and folders from all connected file storage services', z.object({
        path: z.string().optional().describe('Path to list (empty for root)')
    }), async ({ path }, { sessionId }) => {
        const auth = getAuth(sessionManager, sessionId);
        const results = {};
        await Promise.all(fileConfigs.map(async (api) => {
            try {
                const files = await DreamFactoryService.listFiles(api.baseUrl, auth, path ?? '');
                results[api.name] = { success: true, data: files };
            }
            catch (error) {
                const message = error instanceof Error ? error.message : String(error);
                results[api.name] = { success: false, error: message };
            }
        }));
        return respond({ storages: results });
    });
}
