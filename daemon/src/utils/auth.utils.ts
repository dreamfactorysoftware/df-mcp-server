import { Request } from 'express';

/**
 * Authentication configuration for DreamFactory API calls.
 * At least one of sessionToken or apiKey must be provided.
 */
export type AuthCredentials = {
  sessionToken?: string;
  apiKey?: string;
};

/**
 * Result of auth validation
 */
export type AuthValidationResult = {
  valid: boolean;
  credentials?: AuthCredentials;
  error?: string;
  mode?: 'session_token' | 'api_key' | 'both';
};

/**
 * DreamFactory API key format validation.
 * API keys are typically 64-character hex strings.
 */
const API_KEY_PATTERN = /^[a-fA-F0-9]{64}$/;

/**
 * Minimum length for session tokens (JWTs are typically much longer)
 */
const MIN_SESSION_TOKEN_LENGTH = 20;

/**
 * Normalize a header value: trim whitespace and return undefined for empty values.
 */
export function normalizeHeaderValue(value: string | string[] | undefined): string | undefined {
  if (!value) return undefined;
  const str = Array.isArray(value) ? value[0] : value;
  const trimmed = str?.trim();
  return trimmed && trimmed.length > 0 ? trimmed : undefined;
}

/**
 * Extract auth header from request with normalization.
 */
export function getAuthHeaderFromRequest(req: Request, headerName: string): string | undefined {
  return normalizeHeaderValue(req.headers[headerName.toLowerCase()]);
}

/**
 * Validate API key format.
 * Returns true if the API key appears to be valid format.
 */
export function isValidApiKeyFormat(apiKey: string | undefined): boolean {
  if (!apiKey) return false;
  // DreamFactory API keys are 64-character hex strings
  return API_KEY_PATTERN.test(apiKey);
}

/**
 * Validate session token format.
 * Basic validation - checks minimum length (JWTs are typically 100+ chars).
 */
export function isValidSessionTokenFormat(sessionToken: string | undefined): boolean {
  if (!sessionToken) return false;
  return sessionToken.length >= MIN_SESSION_TOKEN_LENGTH;
}

/**
 * Validate authentication credentials.
 * Requires at least one valid auth method.
 *
 * @param credentials - The credentials to validate
 * @param strictFormat - If true, validate format of provided credentials
 * @returns Validation result with error message if invalid
 */
export function validateAuthCredentials(
  credentials: AuthCredentials,
  strictFormat: boolean = false
): AuthValidationResult {
  const { sessionToken, apiKey } = credentials;
  const hasSessionToken = !!sessionToken;
  const hasApiKey = !!apiKey;

  // Must have at least one auth method
  if (!hasSessionToken && !hasApiKey) {
    return {
      valid: false,
      error: 'At least one authentication method required (session token or API key)'
    };
  }

  // Strict format validation if enabled
  if (strictFormat) {
    if (hasSessionToken && !isValidSessionTokenFormat(sessionToken)) {
      return {
        valid: false,
        error: 'Invalid session token format'
      };
    }
    if (hasApiKey && !isValidApiKeyFormat(apiKey)) {
      return {
        valid: false,
        error: 'Invalid API key format (expected 64-character hex string)'
      };
    }
  }

  // Determine auth mode
  let mode: AuthValidationResult['mode'];
  if (hasSessionToken && hasApiKey) {
    mode = 'both';
  } else if (hasSessionToken) {
    mode = 'session_token';
  } else {
    mode = 'api_key';
  }

  return {
    valid: true,
    credentials: { sessionToken, apiKey },
    mode
  };
}

/**
 * Extract and validate auth credentials from a request.
 *
 * @param req - Express request
 * @param strictFormat - If true, validate format of provided credentials
 * @returns Validation result
 */
export function extractAndValidateAuth(req: Request, strictFormat: boolean = false): AuthValidationResult {
  const sessionToken = getAuthHeaderFromRequest(req, 'x-dreamfactory-session-token');
  const apiKey = getAuthHeaderFromRequest(req, 'x-dreamfactory-api-key');

  return validateAuthCredentials({ sessionToken, apiKey }, strictFormat);
}

/**
 * Get a human-readable description of the auth mode.
 */
export function getAuthModeDescription(mode: AuthValidationResult['mode']): string {
  switch (mode) {
    case 'both':
      return 'session token + API key (user context with app)';
    case 'session_token':
      return 'session token only (OAuth flow)';
    case 'api_key':
      return 'API key only (app role-based access)';
    default:
      return 'unknown';
  }
}
