export class SessionService {
    configs = new Map();
    setConfig(sessionId, config) {
        this.configs.set(sessionId, config);
    }
    getConfig(sessionId) {
        if (!sessionId) {
            return undefined;
        }
        return this.configs.get(sessionId);
    }
    getApiConfigs(sessionId) {
        const config = this.getConfig(sessionId);
        return config?.apiConfigs ?? [];
    }
    clearConfig(sessionId) {
        this.configs.delete(sessionId);
    }
}
