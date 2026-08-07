export class SessionService {
    configs = new Map();
    defaultConfig;
    /**
     * Register a config that is returned when no session ID is available.
     *
     * Stateless mode issues no session IDs, so tool handlers are invoked with an
     * undefined sessionId. In that mode a SessionService is created per request
     * and seeded here. Left unset in stateful mode, where lookups stay keyed by
     * session ID.
     */
    setDefaultConfig(config) {
        this.defaultConfig = config;
    }
    setConfig(sessionId, config) {
        this.configs.set(sessionId, config);
    }
    getConfig(sessionId) {
        if (!sessionId) {
            return this.defaultConfig;
        }
        return this.configs.get(sessionId) ?? this.defaultConfig;
    }
    getApiConfigs(sessionId) {
        const config = this.getConfig(sessionId);
        return config?.apiConfigs ?? [];
    }
    clearConfig(sessionId) {
        this.configs.delete(sessionId);
    }
}
