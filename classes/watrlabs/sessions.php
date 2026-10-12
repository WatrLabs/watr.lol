<?php

namespace watrlabs;

class sessions {

    const LIFETIME = 60 * 60 * 24 * 30; // a month

    private static $current = false;

    static function cookieName(): string {
        return $_ENV['AUTH_COOKIE'] ?? 'watr_session';
    }

    // signs someone in on this browser
    public function createSession(int $userId): string {
        global $db;

        $token = encryption::token(32);

        $db->table('sessions')->insert([
            'token_hash' => encryption::hashToken($token),
            'user_id' => $userId,
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255),
            'ip' => sitefunctions::getip(),
            'created' => time(),
            'last_seen' => time(),
            'expires' => time() + self::LIFETIME,
        ]);

        cookies::set(self::cookieName(), $token, time() + self::LIFETIME);
        self::$current = false;

        return $token;
    }

    public function getCurrentSession() {
        global $db;

        if (self::$current !== false) {
            return self::$current;
        }

        $token = cookies::get(self::cookieName());
        self::$current = null;

        if (!$token) {
            return null;
        }

        $session = $db->table('sessions')
            ->where('token_hash', encryption::hashToken($token))
            ->where('expires', '>', time())
            ->first();

        if ($session && $session->last_seen < time() - 300) {
            // no need to write on every single request
            $db->table('sessions')->where('id', $session->id)->update(['last_seen' => time()]);
        }

        return self::$current = $session;
    }

    // logs out this browser
    public function destroyCurrent(): void {
        global $db;

        $session = $this->getCurrentSession();
        if ($session) {
            $db->table('sessions')->where('id', $session->id)->delete();
        }

        cookies::destroy(self::cookieName());
        self::$current = null;
    }

    public function listForUser(int $userId): array {
        global $db;
        return $db->table('sessions')
            ->where('user_id', $userId)
            ->where('expires', '>', time())
            ->orderBy('last_seen', 'DESC')
            ->get();
    }

    public function revoke(int $userId, int $sessionId): void {
        global $db;
        $db->table('sessions')->where('user_id', $userId)->where('id', $sessionId)->delete();
    }

    // logs someone out everywhere, except maybe the browser they're using
    public function revokeAllForUser(int $userId, ?int $exceptId = null): void {
        global $db;
        $query = $db->table('sessions')->where('user_id', $userId);
        if ($exceptId) {
            $query->where('id', '!=', $exceptId);
        }
        $query->delete();
    }

}
