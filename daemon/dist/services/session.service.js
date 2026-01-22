/**
 * Default session TTL: 1 hour (in milliseconds)
 */
const DEFAULT_SESSION_TTL_MS = 60 * 60 * 1000;
/**
 * Cleanup interval: every 10 minutes
 */
const CLEANUP_INTERVAL_MS = 10 * 60 * 1000;
export class SessionService {
    sessions = new Map();
    sessionTtlMs;
    cleanupTimer = null;
    constructor(sessionTtlMs = DEFAULT_SESSION_TTL_MS) {
        this.sessionTtlMs = sessionTtlMs;
        this.startCleanupTimer();
    }
    /**
     * Store session configuration with timestamp tracking
     */
    setConfig(sessionId, config) {
        const existing = this.sessions.get(sessionId);
        const now = Date.now();
        this.sessions.set(sessionId, {
            config,
            createdAt: existing?.createdAt ?? now,
            lastAccessedAt: now
        });
        console.debug(`Session ${sessionId}: Config set (total sessions: ${this.sessions.size})`);
    }
    /**
     * Get session configuration and update last accessed time
     */
    getConfig(sessionId) {
        if (!sessionId) {
            return undefined;
        }
        const entry = this.sessions.get(sessionId);
        if (!entry) {
            return undefined;
        }
        // Update last accessed time
        entry.lastAccessedAt = Date.now();
        return entry.config;
    }
    /**
     * Remove a session
     */
    clearConfig(sessionId) {
        const deleted = this.sessions.delete(sessionId);
        if (deleted) {
            console.debug(`Session ${sessionId}: Cleared (remaining sessions: ${this.sessions.size})`);
        }
    }
    /**
     * Get the number of active sessions
     */
    getSessionCount() {
        return this.sessions.size;
    }
    /**
     * Get session stats for monitoring
     */
    getStats() {
        const now = Date.now();
        let oldest = null;
        let totalAge = 0;
        for (const entry of this.sessions.values()) {
            const age = now - entry.createdAt;
            totalAge += age;
            if (oldest === null || age > oldest) {
                oldest = age;
            }
        }
        return {
            total: this.sessions.size,
            oldest,
            avgAge: this.sessions.size > 0 ? Math.round(totalAge / this.sessions.size) : 0
        };
    }
    /**
     * Clean up expired sessions based on TTL
     */
    cleanupExpiredSessions() {
        const now = Date.now();
        const expiredSessionIds = [];
        for (const [sessionId, entry] of this.sessions.entries()) {
            const age = now - entry.lastAccessedAt;
            if (age > this.sessionTtlMs) {
                expiredSessionIds.push(sessionId);
            }
        }
        for (const sessionId of expiredSessionIds) {
            this.sessions.delete(sessionId);
        }
        if (expiredSessionIds.length > 0) {
            console.log(`Session cleanup: Removed ${expiredSessionIds.length} expired sessions (remaining: ${this.sessions.size})`);
        }
        return expiredSessionIds.length;
    }
    /**
     * Start the automatic cleanup timer
     */
    startCleanupTimer() {
        if (this.cleanupTimer) {
            return;
        }
        this.cleanupTimer = setInterval(() => {
            this.cleanupExpiredSessions();
        }, CLEANUP_INTERVAL_MS);
        // Don't prevent the process from exiting
        this.cleanupTimer.unref();
        console.debug(`Session cleanup timer started (interval: ${CLEANUP_INTERVAL_MS / 1000}s, TTL: ${this.sessionTtlMs / 1000}s)`);
    }
    /**
     * Stop the cleanup timer (for graceful shutdown)
     */
    stopCleanupTimer() {
        if (this.cleanupTimer) {
            clearInterval(this.cleanupTimer);
            this.cleanupTimer = null;
            console.debug('Session cleanup timer stopped');
        }
    }
    /**
     * Clear all sessions (for shutdown)
     */
    clearAll() {
        const count = this.sessions.size;
        this.sessions.clear();
        console.debug(`All sessions cleared (${count} sessions)`);
    }
}
