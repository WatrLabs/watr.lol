<?php

namespace watrlabs\social;

use watrlabs\authentication;

// short "what's up" posts. they live on your profile and in your followers' feeds
class statuses {

    const BODY_MAX = 500;

    public function get(int $id) {
        global $db;
        $status = $db->table('statuses')->where('id', $id)->first();
        if ($status) {
            $status->author = (new authentication())->getUserInfo($status->user_id);
        }
        return $status;
    }

    public function validate(string $body): ?string {
        if (mb_strlen($body) < 1) {
            return 'Write something first.';
        }
        if (mb_strlen($body) > self::BODY_MAX) {
            return 'Updates can be ' . self::BODY_MAX . ' characters at most. Yours is ' . number_format(mb_strlen($body)) . '.';
        }
        return null;
    }

    public function create($user, string $body): int {
        global $db;
        $id = (int) $db->table('statuses')->insert([
            'user_id' => $user->id,
            'body' => $body,
            'created' => time(),
        ]);
        (new notifications())->mentions($body, (int) $user->id, '/updates/' . $id, 'status', $id, $body);
        return $id;
    }

    public function delete(int $id): void {
        global $db;
        $db->table('statuses')->where('id', $id)->delete();
        (new reactions())->forget('status', [$id]);
        (new notifications())->forget('status', [$id]);
    }

    public function byUser(int $userId, int $limit, ?int $before = null): array {
        global $db;
        $query = $db->table('statuses')->where('user_id', $userId);
        if ($before) {
            $query->where('created', '<', $before);
        }
        return $query->orderBy('created', 'DESC')->orderBy('id', 'DESC')->limit($limit)->get();
    }

    public function countByUser(int $userId): int {
        global $db;
        return $db->table('statuses')->where('user_id', $userId)->count();
    }

}
