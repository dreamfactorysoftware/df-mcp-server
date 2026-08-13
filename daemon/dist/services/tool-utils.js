export const respond = (data) => {
    let text;
    try {
        text = JSON.stringify(data, null, 2) ?? 'null';
    }
    catch {
        text = String(data);
    }
    return { content: [{ type: 'text', text }] };
};
export const respondError = (message) => ({
    content: [{ type: 'text', text: message }],
    isError: true
});
export const handleError = (error, operation) => {
    if (!(error instanceof Error)) {
        return `Unknown error during ${operation}: ${String(error)}`;
    }
    const message = error.message ?? '';
    if (message.includes('Authentication failed') || message.includes('401')) {
        return `Authentication Error: ${message}`;
    }
    if (message.includes('Network error') || message.includes('Unable to connect')) {
        return `Connection Error: ${message}`;
    }
    if (message.includes('Access forbidden') || message.includes('403')) {
        return `Permission Error: ${message}`;
    }
    if (message.includes('Resource not found') || message.includes('404')) {
        return `Resource Error: ${message}`;
    }
    if (message.includes('Validation error') || message.includes('422')) {
        return `Validation Error: ${message}`;
    }
    if (message.includes('Server error') || message.includes('500')) {
        return `Server Error: ${message}`;
    }
    return `Error during ${operation}: ${message}`;
};
/**
 * Sanitize API name for use as a tool prefix.
 * Converts to lowercase, replaces non-alphanumeric chars with underscores.
 */
export function sanitizeApiName(name) {
    return name
        .toLowerCase()
        .replace(/[^a-z0-9]/g, '_')
        .replace(/_+/g, '_')
        .replace(/^_|_$/g, '');
}
export function getAuth(sessionManager, sessionId) {
    // Always consult the manager: in stateless mode there is no session ID and the
    // per-request config is served from the default slot.
    const sessionConfig = sessionManager.getConfig(sessionId);
    const sessionToken = sessionConfig?.sessionToken ?? '';
    const apiKey = sessionConfig?.apiKey;
    if (!sessionToken) {
        throw new Error('DreamFactory session not found. Please authenticate via OAuth.');
    }
    return { sessionToken, apiKey };
}
export function createToolRegistrar(server, disabledTools) {
    return (name, title, description, schema, handler) => {
        if (disabledTools?.has(name)) {
            return;
        }
        server.registerTool(name, { title, description, inputSchema: schema }, async (params, context) => {
            console.log(`[tool] ${name} called`);
            try {
                const result = await handler(params ?? {}, context ?? {});
                console.log(`[tool] ${name} completed, isError=${result?.isError ?? false}`);
                return result;
            }
            catch (error) {
                console.error(`[tool] ${name} unhandled error:`, error);
                return respondError(handleError(error, name));
            }
        });
    };
}
