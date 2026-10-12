<?php

namespace watrlabs;

// every email the site sends about someone's account
class accountmail {

    private function link(string $path): string {
        return rtrim($_ENV['APP_URL'] ?? '', '/') . $path;
    }

    // "is this really your address?" the address only lands on the account once they click
    public function sendVerification($user, string $email): bool {
        $token = (new emailtokens())->create((int) $user->id, emailtokens::VERIFY, $email);
        return mail::send($email, 'Confirm your email for ' . ($_ENV['APP_NAME'] ?? 'watr.lol'), 'verify', [
            'user' => $user,
            'email' => $email,
            'link' => $this->link('/auth/verify?token=' . urlencode($token)),
        ]);
    }

    // returns the user whose address got verified, or an error message
    public function verify(string $token): array {
        global $db;

        $tokens = new emailtokens();
        $row = $tokens->peek($token, emailtokens::VERIFY);
        if (!$row) {
            return ['error' => 'That link expired or was already used. Send a new one from settings.'];
        }

        $auth = new authentication();
        $user = $auth->getUserInfo($row->user_id);
        if (!$user) {
            return ['error' => 'The account that link was for is gone.'];
        }

        $owner = $auth->getByEmail($row->email);
        if ($owner && (int) $owner->id !== (int) $user->id) {
            $tokens->useUp($row->id);
            return ['error' => 'Another account verified that address first.'];
        }

        $tokens->useUp($row->id);
        $old = $user->email;

        $db->table('users')->where('id', $user->id)->update([
            'email' => $row->email,
            'email_lower' => strtolower($row->email),
            'updated' => time(),
        ]);

        if ($old && strtolower($old) !== strtolower($row->email)) {
            $this->notifyEmailChanged($user, $old, $row->email);
        }

        $user->email = $row->email;
        return ['user' => $user];
    }

    // sends a reset link, if the account has somewhere to send it
    public function sendReset($user): bool {
        if (!$user->email) {
            return false;
        }
        $token = (new emailtokens())->create((int) $user->id, emailtokens::RESET, $user->email);
        return mail::send($user->email, 'Reset your ' . ($_ENV['APP_NAME'] ?? 'watr.lol') . ' password', 'reset', [
            'user' => $user,
            'link' => $this->link('/auth/reset?token=' . urlencode($token)),
            'ip' => sitefunctions::getip(),
        ]);
    }

    // a heads up, so someone notices if it wasn't them
    public function notifyPasswordChanged($user): void {
        if (!$user->email) {
            return;
        }
        mail::send($user->email, 'Your ' . ($_ENV['APP_NAME'] ?? 'watr.lol') . ' password was changed', 'password_changed', [
            'user' => $user,
            'ip' => sitefunctions::getip(),
            'link' => $this->link('/auth/forgot'),
        ]);
    }

    // goes to the old address, that's the one that can say "wait, that wasn't me"
    public function notifyEmailChanged($user, string $oldEmail, string $newEmail): void {
        mail::send($oldEmail, 'Your ' . ($_ENV['APP_NAME'] ?? 'watr.lol') . ' email was changed', 'email_changed', [
            'user' => $user,
            'new_email' => $newEmail,
        ]);
    }

}
