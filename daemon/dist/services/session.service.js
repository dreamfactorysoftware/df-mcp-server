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
    clearConfig(sessionId) {
        this.configs.delete(sessionId);
    }
}
