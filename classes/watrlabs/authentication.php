<?php

namespace watrlabs;

class authentication {

    const USERNAME_PATTERN = '/^[A-Za-z0-9_.-]{3,20}$/';

    // names nobody gets, mostly so nobody can pretend to be the site
    const RESERVED = ['admin', 'administrator', 'root', 'system', 'mod', 'moderator', 'staff', 'support', 'watr', 'watrlabs', 'watrlol', 'deleted', 'anonymous', 'api', 'auth', 'oauth', 'null', 'undefined'];

    // returns current user information based on
    // the currently logged in user or provided id
    public function getUserInfo($id = null) {
        global $db;

        if ($id) {
            return $db->table('users')->where('id', $id)->first();
        }

        $session = (new sessions())->getCurrentSession();

        if (!$session) {
            return null;
        }

        $user = $db->table('users')->where('id', $session->user_id)->first();

        // banned people are logged out the moment they're banned, this is the backstop
        if (!$user || $user->banned) {
            return null;
        }

        if (!$user->last_seen || $user->last_seen < time() - 300) {
            $db->table('users')->where('id', $user->id)->update(['last_seen' => time()]);
        }

        return $user;
    }

    public function getByUsername(string $username) {
        global $db;
        return $db->table('users')->where('username_lower', strtolower($username))->first();
    }

    public function isLoggedIn() {
        return (bool) $this->getUserInfo();
    }

    // null when it's fine, otherwise what's wrong with it
    public function validateUsername(string $username, ?int $ignoreId = null): ?string {
        if (strlen($username) < 3 || strlen($username) > 20) {
            return 'Usernames are 3 to 20 characters long.';
        }

        if (!preg_match(self::USERNAME_PATTERN, $username)) {
            return 'Usernames can only use letters, numbers, dots, dashes and underscores.';
        }

        if (in_array(strtolower($username), self::RESERVED, true)) {
            return 'That username is reserved. Pick another one.';
        }

        $existing = $this->getByUsername($username);
        if ($existing && (int) $existing->id !== $ignoreId) {
            return 'That username is taken. Pick another one.';
        }

        return null;
    }

    public function validatePassword(string $password): ?string {
        if (strlen($password) < 8) {
            return 'Passwords need at least 8 characters.';
        }
        if (strlen($password) > 200) {
            return 'Passwords can be 200 characters at most.';
        }
        return null;
    }

    // creates a user. password can be null for people signing up with discord and co.
    // returns ['id' => ...] or ['error' => ...]
    public function createUser(string $username, ?string $password): array {
        global $db;

        if ($error = $this->validateUsername($username)) {
            return ['error' => $error];
        }

        if ($password !== null && ($error = $this->validatePassword($password))) {
            return ['error' => $error];
        }

        // the very first account runs the place
        $isFirst = $db->table('users')->count() === 0;

        $userId = $db->table('users')->insert([
            'username' => $username,
            'username_lower' => strtolower($username),
            'password' => $password === null ? null : password_hash($password, PASSWORD_DEFAULT),
            'role' => $isFirst ? 'admin' : 'user',
            'created' => time(),
            'last_seen' => time(),
        ]);

        return ['id' => (int) $userId];
    }

    // checks a username and password. returns ['user' => ...] or ['error' => ...]
    public function attemptLogin(string $username, string $password): array {
        global $db;

        $ip = sitefunctions::getip();

        if (throttle::tooMany('login', $ip, 10, 900)) {
            return ['error' => 'Too many tries from your network. Wait 15 minutes and try again.'];
        }

        $user = $this->getByUsername($username);

        // check a hash even when there's no user, so timing doesn't say who exists
        $hash = $user && $user->password ? $user->password : '$2y$10$KBTjuZ4s1bIu8ciO9vzY.uc1yeIF4tNYTRyRWKmfJvmuC/san0xy.';
        $valid = password_verify($password, $hash) && $user && $user->password;

        if (!$valid) {
            throttle::hit('login', $ip);
            if ($user && !$user->password) {
                return ['error' => 'This account signs in with a linked account. Use one of the buttons below.'];
            }
            return ['error' => 'Username or password is incorrect.'];
        }

        if ($user->banned) {
            return ['error' => 'This account is banned' . ($user->ban_reason ? ': ' . $user->ban_reason : '.')];
        }

        if (password_needs_rehash($user->password, PASSWORD_DEFAULT)) {
            $db->table('users')->where('id', $user->id)->update(['password' => password_hash($password, PASSWORD_DEFAULT)]);
        }

        return ['user' => $user];
    }

    public function setPassword(int $userId, string $password): void {
        global $db;
        $db->table('users')->where('id', $userId)->update([
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'updated' => time(),
        ]);
    }

    // a handful of users by id, keyed by id. for lists that show lots of names
    public function mapByIds(array $ids): array {
        global $db;

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }

        $map = [];
        foreach ($db->table('users')->whereIn('id', $ids)->get() as $user) {
            $map[$user->id] = $user;
        }
        return $map;
    }

}
