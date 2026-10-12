<?php

namespace watrlabs\oauth;

use watrlabs\encryption;

// the authorization code flow, start to finish:
// authorize -> code -> token -> /api/v1/me
class server {

    const CODE_LIFETIME = 300;               // five minutes to swap a code for a token
    const TOKEN_LIFETIME = 60 * 60 * 24 * 30; // tokens last a month

    // what an app can ask for, and how the consent screen describes it
    const SCOPES = [
        'profile' => 'See your username, display name, bio, role and when you joined',
    ];

    // splits "profile foo" into known scopes. null if any of them aren't real
    static function parseScope(string $scope): ?array {
        $scopes = array_values(array_unique(array_filter(preg_split('/[\s+]+/', $scope))));
        if (!$scopes) {
            $scopes = ['profile'];
        }
        foreach ($scopes as $s) {
            if (!isset(self::SCOPES[$s])) {
                return null;
            }
        }
        sort($scopes);
        return $scopes;
    }

    public function issueCode($app, int $userId, string $redirectUri, array $scopes, ?string $challenge): string {
        global $db;

        $code = encryption::token(32);
        $db->table('oauth_codes')->insert([
            'code_hash' => encryption::hashToken($code),
            'app_id' => $app->id,
            'user_id' => $userId,
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', $scopes),
            'code_challenge' => $challenge,
            'expires' => time() + self::CODE_LIFETIME,
        ]);

        return $code;
    }

    // swaps a code for an access token. returns ['token' => ..., 'scope' => ...] or ['error' => ..., 'description' => ...]
    public function exchangeCode($app, string $code, string $redirectUri, string $verifier): array {
        global $db;

        $row = $db->table('oauth_codes')->where('code_hash', encryption::hashToken($code))->first();

        if (!$row || (int) $row->app_id !== (int) $app->id) {
            return ['error' => 'invalid_grant', 'description' => 'That code is not valid.'];
        }

        if ($row->used) {
            // someone is replaying a code. kill whatever it made, just in case
            $this->revokeFor((int) $row->app_id, (int) $row->user_id);
            return ['error' => 'invalid_grant', 'description' => 'That code was already used.'];
        }

        $db->table('oauth_codes')->where('id', $row->id)->update(['used' => 1]);

        if ($row->expires < time()) {
            return ['error' => 'invalid_grant', 'description' => 'That code expired.'];
        }

        if (!hash_equals($row->redirect_uri, $redirectUri)) {
            return ['error' => 'invalid_grant', 'description' => 'redirect_uri does not match the one used to get the code.'];
        }

        if ($row->code_challenge) {
            $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            if ($verifier === '' || !hash_equals($row->code_challenge, $expected)) {
                return ['error' => 'invalid_grant', 'description' => 'code_verifier does not match the code_challenge.'];
            }
        }

        $user = $db->table('users')->where('id', $row->user_id)->first();
        if (!$user || $user->banned) {
            return ['error' => 'invalid_grant', 'description' => 'That account can no longer sign in.'];
        }

        $token = encryption::token(32);
        $db->table('oauth_tokens')->insert([
            'token_hash' => encryption::hashToken($token),
            'app_id' => $app->id,
            'user_id' => $row->user_id,
            'scope' => $row->scope,
            'created' => time(),
            'expires' => time() + self::TOKEN_LIFETIME,
        ]);

        // codes are single use, no reason to keep old ones around
        $db->table('oauth_codes')->where('expires', '<', time() - 86400)->delete();

        return ['token' => $token, 'scope' => $row->scope];
    }

    // the token row for a bearer token, if it's still good
    public function tokenInfo(string $token) {
        global $db;
        $row = $db->table('oauth_tokens')->where('token_hash', encryption::hashToken($token))->first();
        if (!$row || $row->revoked || $row->expires < time()) {
            return null;
        }
        return $row;
    }

    public function revokeToken($app, string $token): void {
        global $db;
        $db->table('oauth_tokens')
            ->where('token_hash', encryption::hashToken($token))
            ->where('app_id', $app->id)
            ->update(['revoked' => 1]);
    }

    public function revokeFor(int $appId, int $userId): void {
        global $db;
        $db->table('oauth_tokens')->where('app_id', $appId)->where('user_id', $userId)->update(['revoked' => 1]);
    }

    // everything a banned person had, gone
    public function revokeAllForUser(int $userId): void {
        global $db;
        $db->table('oauth_tokens')->where('user_id', $userId)->update(['revoked' => 1]);
    }

    // has this person already said yes to these scopes for this app?
    public function hasGrant(int $appId, int $userId, array $scopes): bool {
        global $db;
        $grant = $db->table('oauth_grants')->where('app_id', $appId)->where('user_id', $userId)->first();
        return $grant && !array_diff($scopes, explode(' ', $grant->scope));
    }

    public function saveGrant(int $appId, int $userId, array $scopes): void {
        global $db;
        $grant = $db->table('oauth_grants')->where('app_id', $appId)->where('user_id', $userId)->first();
        if ($grant) {
            $merged = array_unique(array_merge(explode(' ', $grant->scope), $scopes));
            sort($merged);
            $db->table('oauth_grants')->where('id', $grant->id)->update(['scope' => implode(' ', $merged)]);
        } else {
            $db->table('oauth_grants')->insert([
                'app_id' => $appId,
                'user_id' => $userId,
                'scope' => implode(' ', $scopes),
                'created' => time(),
            ]);
        }
    }

    // the apps someone has connected, for their settings page
    public function grantsForUser(int $userId): array {
        global $db;
        return $db->table('oauth_grants')
            ->join('oauth_apps', 'oauth_apps.id', '=', 'oauth_grants.app_id')
            ->select(['oauth_grants.*', 'oauth_apps.name' => 'app_name', 'oauth_apps.homepage' => 'app_homepage', 'oauth_apps.description' => 'app_description'])
            ->where('oauth_grants.user_id', $userId)
            ->orderBy('oauth_grants.created', 'DESC')
            ->get();
    }

    // disconnecting an app forgets the yes and kills its tokens
    public function revokeGrant(int $appId, int $userId): void {
        global $db;
        $db->table('oauth_grants')->where('app_id', $appId)->where('user_id', $userId)->delete();
        $this->revokeFor($appId, $userId);
    }

    // what /api/v1/me hands back
    static function profile($user, array $scopes): array {
        $profile = [
            'id' => (int) $user->id,
            'sub' => (string) $user->id,
        ];

        if (in_array('profile', $scopes, true)) {
            $profile += [
                'username' => $user->username,
                'preferred_username' => $user->username,
                'display_name' => $user->display_name ?: $user->username,
                'name' => $user->display_name ?: $user->username,
                'bio' => $user->bio,
                'role' => $user->role,
                'created' => (int) $user->created,
                'profile_url' => rtrim($_ENV['APP_URL'] ?? '', '/') . '/u/' . $user->username,
            ];
        }

        return $profile;
    }

}
