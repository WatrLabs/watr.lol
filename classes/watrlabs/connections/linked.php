<?php

namespace watrlabs\connections;

class linked {

    public function find(string $provider, string $providerUserId) {
        global $db;
        return $db->table('linked_accounts')
            ->where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->first();
    }

    // provider slug => link row
    public function forUser(int $userId): array {
        global $db;
        $links = [];
        foreach ($db->table('linked_accounts')->where('user_id', $userId)->get() as $link) {
            $links[$link->provider] = $link;
        }
        return $links;
    }

    public function link(int $userId, string $provider, array $identity): void {
        global $db;
        $db->table('linked_accounts')->insert([
            'user_id' => $userId,
            'provider' => $provider,
            'provider_user_id' => $identity['id'],
            'provider_username' => mb_substr($identity['username'] ?: $identity['display'], 0, 100) ?: null,
            'created' => time(),
        ]);
    }

    public function unlink(int $userId, string $provider): void {
        global $db;
        $db->table('linked_accounts')->where('user_id', $userId)->where('provider', $provider)->delete();
    }

    // turns "Cool Person!!" from discord into something that works as a username here
    static function suggestUsername(string $name): string {
        $name = preg_replace('/[^A-Za-z0-9_.-]+/', '', str_replace(' ', '_', $name));
        $name = trim(substr($name, 0, 20), '.-');
        return strlen($name) >= 3 ? $name : '';
    }

}
