<?php

namespace watrlabs;

// three roles, each can do everything the one below it can
class roles {

    const LEVELS = [
        'user' => 0,
        'mod' => 1,
        'admin' => 2,
    ];

    static function all(): array {
        return array_keys(self::LEVELS);
    }

    static function level($user): int {
        if (!$user) {
            return -1;
        }
        return self::LEVELS[$user->role] ?? 0;
    }

    static function atLeast($user, string $role): bool {
        return self::level($user) >= (self::LEVELS[$role] ?? PHP_INT_MAX);
    }

}
