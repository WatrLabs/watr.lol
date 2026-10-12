<?php

namespace watrlabs;

// one-time links that go out by email
class emailtokens {

    const VERIFY = 'verify';
    const RESET = 'reset';

    const LIFETIMES = [
        self::VERIFY => 60 * 60 * 24, // a day to click "verify"
        self::RESET => 60 * 60,       // an hour to reset a password
    ];

    // makes a new link token. older unused ones for the same thing stop working
    public function create(int $userId, string $purpose, string $email): string {
        global $db;

        $db->table('email_tokens')
            ->where('user_id', $userId)
            ->where('purpose', $purpose)
            ->where('used', 0)
            ->update(['used' => 1]);

        $token = encryption::token(32);
        $db->table('email_tokens')->insert([
            'user_id' => $userId,
            'purpose' => $purpose,
            'token_hash' => encryption::hashToken($token),
            'email' => $email,
            'created' => time(),
            'expires' => time() + self::LIFETIMES[$purpose],
        ]);

        // nobody needs week-old links
        $db->table('email_tokens')->where('expires', '<', time() - 604800)->delete();

        return $token;
    }

    // the token row if it's real, unused and in date. doesn't use it up
    public function peek(string $token, string $purpose) {
        global $db;

        if ($token === '') {
            return null;
        }

        $row = $db->table('email_tokens')
            ->where('token_hash', encryption::hashToken($token))
            ->where('purpose', $purpose)
            ->first();

        if (!$row || $row->used || $row->expires < time()) {
            return null;
        }

        return $row;
    }

    public function useUp(int $id): void {
        global $db;
        $db->table('email_tokens')->where('id', $id)->update(['used' => 1]);
    }

    // the address someone is waiting to verify, if any
    public function pendingEmail(int $userId): ?string {
        global $db;
        $row = $db->table('email_tokens')
            ->where('user_id', $userId)
            ->where('purpose', self::VERIFY)
            ->where('used', 0)
            ->where('expires', '>', time())
            ->orderBy('id', 'DESC')
            ->first();
        return $row ? $row->email : null;
    }

    public function cancelPending(int $userId): void {
        global $db;
        $db->table('email_tokens')->where('user_id', $userId)->where('purpose', self::VERIFY)->update(['used' => 1]);
    }

}
