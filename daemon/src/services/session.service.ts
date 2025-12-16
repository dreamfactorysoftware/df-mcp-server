export type SessionConfig = {
  url: string;
  sessionToken: string; // DF JWT for user authentication
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

  clearConfig(sessionId: string): void {
    this.configs.delete(sessionId);
  }
}


