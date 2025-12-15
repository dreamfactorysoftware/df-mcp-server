import { createHash, randomUUID } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);
// Load login template once at module initialization
const loginTemplate = readFileSync(join(__dirname, 'templates/login.html'), 'utf-8');
// ChatGPT OAuth redirect URIs
const CHATGPT_REDIRECT_URIS = [
    'https://chatgpt.com/oauth/callback',
    'https://chat.openai.com/oauth/callback',
    'https://chatgpt.com/connector_platform_oauth_redirect',
];
// Check if client ID looks like a ChatGPT-generated UUID
function isChatGPTClient(clientId) {
    return /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(clientId);
}
export class DreamFactoryOAuthProvider {
    dfUrl;
    apiKey;
    baseUrl;
    // In production, use Redis or a database
    clients = new Map();
    authCodes = new Map();
    accessTokens = new Map();
    refreshTokens = new Map();
    pendingAuthorizations = new Map();
    constructor(opts) {
        this.dfUrl = opts.dreamFactoryUrl;
        this.apiKey = opts.apiKey;
        this.baseUrl = opts.baseUrl;
    }
    // ============ Client Store ============
    get clientsStore() {
        return {
            getClient: (clientId) => {
                // Check if already registered
                const existing = this.clients.get(clientId);
                if (existing)
                    return existing;
                // Auto-register ChatGPT clients (they use UUID format)
                if (isChatGPTClient(clientId)) {
                    const chatGPTClient = {
                        client_id: clientId,
                        client_id_issued_at: Math.floor(Date.now() / 1000),
                        redirect_uris: CHATGPT_REDIRECT_URIS,
                        client_name: 'ChatGPT',
                        grant_types: ['authorization_code', 'refresh_token'],
                        response_types: ['code'],
                        token_endpoint_auth_method: 'none',
                    };
                    this.clients.set(clientId, chatGPTClient);
                    console.log(`Auto-registered ChatGPT client: ${clientId}`);
                    return chatGPTClient;
                }
                return undefined;
            },
            registerClient: (client) => {
                const clientId = randomUUID();
                const fullClient = {
                    ...client,
                    client_id: clientId,
                    client_id_issued_at: Math.floor(Date.now() / 1000),
                };
                this.clients.set(clientId, fullClient);
                return fullClient;
            },
        };
    }
    // ============ Authorization ============
    /**
     * Generate authorization page HTML for a service
     */
    authorize(serviceName, params) {
        // Validate client
        const client = this.clientsStore.getClient(params.clientId);
        if (!client) {
            throw new Error('Invalid client');
        }
        // Generate a unique state to track this authorization
        const authState = randomUUID();
        // Store the pending authorization
        this.pendingAuthorizations.set(authState, {
            clientId: params.clientId,
            redirectUri: params.redirectUri,
            codeChallenge: params.codeChallenge,
            codeChallengeMethod: 'S256',
            state: params.state,
        });
        // Build per-service URLs
        const serviceUrl = `${this.baseUrl}/mcp/${serviceName}`;
        const loginUrl = `${serviceUrl}/login`;
        const dfCallbackUrl = `${serviceUrl}/df-callback`;
        // Render login template with placeholders replaced
        const html = loginTemplate
            .replace(/\{\{CLIENT_ID\}\}/g, params.clientId)
            .replace(/\{\{REDIRECT_URI\}\}/g, params.redirectUri)
            .replace(/\{\{AUTH_STATE\}\}/g, authState)
            .replace(/\{\{CODE_CHALLENGE\}\}/g, params.codeChallenge || '')
            .replace(/\{\{ORIGINAL_STATE\}\}/g, params.state || '')
            .replace(/\{\{DF_URL\}\}/g, this.dfUrl)
            .replace(/\{\{LOGIN_URL\}\}/g, loginUrl)
            .replace(/\{\{DF_CALLBACK_URL\}\}/g, dfCallbackUrl);
        return html;
    }
    // ============ Login Handler ============
    async handleLogin(email, password, params) {
        // Validate client
        const client = this.clientsStore.getClient(params.clientId);
        if (!client) {
            throw new Error('Invalid client');
        }
        // For ChatGPT clients, allow their known redirect URIs
        const validRedirectUris = [...(client.redirect_uris || []), ...CHATGPT_REDIRECT_URIS];
        if (!validRedirectUris.includes(params.redirectUri)) {
            throw new Error('Invalid redirect URI');
        }
        // Authenticate against DreamFactory
        const dfSession = await this.authenticateDF(email, password);
        // Generate authorization code
        const code = randomUUID();
        this.authCodes.set(code, {
            dfJwt: dfSession.session_token,
            userId: dfSession.id,
            email: dfSession.email,
            name: dfSession.name || dfSession.first_name || '',
            clientId: params.clientId,
            redirectUri: params.redirectUri,
            codeChallenge: params.codeChallenge,
            codeChallengeMethod: params.codeChallengeMethod,
            expiresAt: Date.now() + 60_000, // 1 minute
            role: dfSession.role,
            roleId: dfSession.role_id,
            isSysAdmin: dfSession.is_sys_admin,
        });
        // Build redirect URL
        const redirectUrl = new URL(params.redirectUri);
        redirectUrl.searchParams.set('code', code);
        if (params.state) {
            redirectUrl.searchParams.set('state', params.state);
        }
        return redirectUrl.toString();
    }
    // ============ DF Callback Handler ============
    async handleDFCallback(sessionToken, state, clientId, redirectUri, codeChallenge, originalState) {
        // Auto-register ChatGPT client if needed
        this.clientsStore.getClient(clientId);
        // Validate the session token against DF
        const headers = {
            'X-DreamFactory-Session-Token': sessionToken,
        };
        if (this.apiKey) {
            headers['X-DreamFactory-Api-Key'] = this.apiKey;
        }
        console.log(`Validating token against: ${this.dfUrl}/api/v2/user/session`);
        let res;
        try {
            res = await fetch(`${this.dfUrl}/api/v2/user/session`, { headers });
        }
        catch (fetchError) {
            console.error(`Failed to connect to DreamFactory at ${this.dfUrl}:`, fetchError);
            throw new Error(`Cannot connect to DreamFactory at ${this.dfUrl}. Check DF_URL environment variable.`);
        }
        if (!res.ok) {
            const errorText = await res.text();
            console.log(`DF validation failed: ${res.status} - ${errorText}`);
            throw new Error('Invalid or expired session token');
        }
        console.log('DF token validation successful');
        const session = await res.json();
        // Generate authorization code
        const code = randomUUID();
        this.authCodes.set(code, {
            dfJwt: sessionToken,
            userId: session.id,
            email: session.email,
            name: session.name || session.first_name || '',
            clientId,
            redirectUri,
            codeChallenge,
            codeChallengeMethod: 'S256',
            expiresAt: Date.now() + 60_000, // 1 minute
            role: session.role,
            roleId: session.role_id,
            isSysAdmin: session.is_sys_admin,
        });
        // Build redirect URL
        const url = new URL(redirectUri);
        url.searchParams.set('code', code);
        if (originalState) {
            url.searchParams.set('state', originalState);
        }
        // Clean up pending authorization
        this.pendingAuthorizations.delete(state);
        return url.toString();
    }
    // ============ Token Exchange ============
    async exchangeAuthorizationCode(params) {
        console.log(`Token exchange requested for client: ${params.clientId}`);
        const authData = this.authCodes.get(params.code);
        if (!authData) {
            console.log('Invalid authorization code - not found in store');
            throw new Error('Invalid authorization code');
        }
        console.log(`Auth code valid for user: ${authData.email}`);
        if (authData.expiresAt < Date.now()) {
            this.authCodes.delete(params.code);
            throw new Error('Authorization code expired');
        }
        if (authData.clientId !== params.clientId) {
            throw new Error('Client mismatch');
        }
        if (params.redirectUri && authData.redirectUri !== params.redirectUri) {
            throw new Error('Redirect URI mismatch');
        }
        // Verify PKCE code_verifier
        if (authData.codeChallenge && params.codeVerifier) {
            const expectedChallenge = createHash('sha256')
                .update(params.codeVerifier)
                .digest('base64url');
            if (expectedChallenge !== authData.codeChallenge) {
                throw new Error('Invalid code verifier');
            }
        }
        // Generate tokens
        const accessToken = randomUUID();
        const refreshToken = randomUUID();
        const expiresIn = 3600; // 1 hour
        this.accessTokens.set(accessToken, {
            dfJwt: authData.dfJwt,
            userId: authData.userId,
            email: authData.email,
            name: authData.name,
            clientId: params.clientId,
            scopes: ['mcp:read', 'mcp:write'],
            expiresAt: Date.now() + expiresIn * 1000,
            role: authData.role,
            roleId: authData.roleId,
            isSysAdmin: authData.isSysAdmin,
        });
        this.refreshTokens.set(refreshToken, {
            accessToken,
            expiresAt: Date.now() + 7 * 24 * 60 * 60 * 1000, // 7 days
        });
        // Delete used auth code
        this.authCodes.delete(params.code);
        console.log(`Token exchange successful for user: ${authData.email}`);
        return {
            access_token: accessToken,
            token_type: 'Bearer',
            expires_in: expiresIn,
            refresh_token: refreshToken,
            scope: 'mcp:read mcp:write',
        };
    }
    async exchangeRefreshToken(params) {
        const refreshData = this.refreshTokens.get(params.refreshToken);
        if (!refreshData || refreshData.expiresAt < Date.now()) {
            throw new Error('Invalid refresh token');
        }
        const oldTokenData = this.accessTokens.get(refreshData.accessToken);
        if (!oldTokenData) {
            throw new Error('Token data not found');
        }
        // Check if DF session is still valid
        const isValid = await this.validateDFSession(oldTokenData.dfJwt);
        if (!isValid) {
            this.refreshTokens.delete(params.refreshToken);
            this.accessTokens.delete(refreshData.accessToken);
            throw new Error('DreamFactory session expired');
        }
        // Issue new tokens
        const newAccessToken = randomUUID();
        const newRefreshToken = randomUUID();
        const expiresIn = 3600;
        this.accessTokens.set(newAccessToken, {
            ...oldTokenData,
            expiresAt: Date.now() + expiresIn * 1000,
        });
        this.refreshTokens.set(newRefreshToken, {
            accessToken: newAccessToken,
            expiresAt: Date.now() + 7 * 24 * 60 * 60 * 1000,
        });
        // Revoke old tokens
        this.accessTokens.delete(refreshData.accessToken);
        this.refreshTokens.delete(params.refreshToken);
        return {
            access_token: newAccessToken,
            token_type: 'Bearer',
            expires_in: expiresIn,
            refresh_token: newRefreshToken,
            scope: 'mcp:read mcp:write',
        };
    }
    // ============ Token Validation ============
    async verifyAccessToken(token) {
        const tokenData = this.accessTokens.get(token);
        if (!tokenData) {
            throw new Error('Invalid token');
        }
        if (tokenData.expiresAt < Date.now()) {
            this.accessTokens.delete(token);
            throw new Error('Token expired');
        }
        return {
            token,
            clientId: tokenData.clientId,
            scopes: tokenData.scopes,
            expiresAt: Math.floor(tokenData.expiresAt / 1000),
            extra: {
                userId: tokenData.userId,
                email: tokenData.email,
                name: tokenData.name,
                dfJwt: tokenData.dfJwt,
                role: tokenData.role,
                roleId: tokenData.roleId,
                isSysAdmin: tokenData.isSysAdmin,
            },
        };
    }
    // ============ Helpers ============
    async authenticateDF(email, password) {
        const headers = {
            'Content-Type': 'application/json',
        };
        if (this.apiKey) {
            headers['X-DreamFactory-Api-Key'] = this.apiKey;
        }
        const res = await fetch(`${this.dfUrl}/api/v2/user/session`, {
            method: 'POST',
            headers,
            body: JSON.stringify({ email, password }),
        });
        if (!res.ok) {
            const error = await res.text();
            throw new Error(`Authentication failed: ${error}`);
        }
        return res.json();
    }
    async validateDFSession(jwt) {
        try {
            const headers = {
                'X-DreamFactory-Session-Token': jwt,
            };
            if (this.apiKey) {
                headers['X-DreamFactory-Api-Key'] = this.apiKey;
            }
            const res = await fetch(`${this.dfUrl}/api/v2/user/session`, { headers });
            return res.ok;
        }
        catch {
            return false;
        }
    }
}
