import type { ApiConfig } from '../types.js';

export type SessionConfig = {
  url: string;
  sessionToken: string; // DF JWT for user authentication
  apiKey?: string; // DF API key (required for non-admin users)
  apiConfigs?: ApiConfig[]; // Discovered database API configurations
};

export class SessionService {
  private readonly configs = new Map<string, SessionConfig>();

  setConfig(sessionId: string, config: SessionConfig): void {
    this.configs.set(sessionId, config);
  }

  getConfig(sessionId?: string): SessionConfig | undefined {
    if (!sessionId) {
      return undefined;
    }
    return this.configs.get(sessionId);
  }

  getApiConfigs(sessionId?: string): ApiConfig[] {
    const config = this.getConfig(sessionId);
    return config?.apiConfigs ?? [];
  }

  clearConfig(sessionId: string): void {
    this.configs.delete(sessionId);
  }
}


