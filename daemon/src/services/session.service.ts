import type { ApiConfig } from '../types.js';

export type SessionConfig = {
  url: string;
  sessionToken: string; // DF JWT for user authentication
  apiKey?: string; // DF API key (required for non-admin users)
  apiConfigs?: ApiConfig[]; // Discovered database API configurations
};

export class SessionService {
  private readonly configs = new Map<string, SessionConfig>();
  private defaultConfig?: SessionConfig;

  /**
   * Register a config that is returned when no session ID is available.
   *
   * Stateless mode issues no session IDs, so tool handlers are invoked with an
   * undefined sessionId. In that mode a SessionService is created per request
   * and seeded here. Left unset in stateful mode, where lookups stay keyed by
   * session ID.
   */
  setDefaultConfig(config: SessionConfig): void {
    this.defaultConfig = config;
  }

  setConfig(sessionId: string, config: SessionConfig): void {
    this.configs.set(sessionId, config);
  }

  getConfig(sessionId?: string): SessionConfig | undefined {
    if (!sessionId) {
      return this.defaultConfig;
    }
    return this.configs.get(sessionId) ?? this.defaultConfig;
  }

  getApiConfigs(sessionId?: string): ApiConfig[] {
    const config = this.getConfig(sessionId);
    return config?.apiConfigs ?? [];
  }

  clearConfig(sessionId: string): void {
    this.configs.delete(sessionId);
  }
}


