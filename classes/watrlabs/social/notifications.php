<?php

namespace watrlabs\social;

use watrlabs\authentication;

// the bell. someone replied, mentioned you, followed you or reacted to something you wrote
class notifications {

    const MENTION_PATTERN = '/(^|[\s(])@([A-Za-z0-9_.-]{3,20})(?<![.-])/m'; // same as markup.php
    const MAX_MENTIONS = 10; // per post, so nobody can ping the whole site at once

    private static array $unread = [];

    public function add(int $userId, int $actorId, string $type, string $url, ?string $target = null, ?int $targetId = null, ?string $summary = null): void {
        global $db;

        if ($userId === $actorId) {
            return; // nobody needs to hear about their own stuff
        }

        // the same thing twice (unfollow, follow again) only shows once while it's unread
        $query = $db->table('notifications')
            ->where('user_id', $userId)
            ->where('actor_id', $actorId)
            ->where('type', $type)
            ->where('seen', 0);
        $query = $targetId === null ? $query->whereNull('target_id') : $query->where('target', $target)->where('target_id', $targetId);
        if ($query->first()) {
            return;
        }

        $db->table('notifications')->insert([
            'user_id' => $userId,
            'actor_id' => $actorId,
            'type' => $type,
            'target' => $target,
            'target_id' => $targetId,
            'summary' => $summary !== null ? mb_substr($summary, 0, 160) : null,
            'url' => $url,
            'created' => time(),
        ]);
        unset(self::$unread[$userId]);
    }

    // usernames @mentioned in some text, lowercased, without the author
    static function mentioned(string $text): array {
        // mentions inside code don't count
        $text = preg_replace(['/```.*?```/s', '/`[^`\n]+`/'], ' ', $text);
        preg_match_all(self::MENTION_PATTERN, $text, $m);
        return array_slice(array_values(array_unique(array_map('strtolower', $m[2] ?? []))), 0, self::MAX_MENTIONS);
    }

    // pings everyone mentioned in $text. $skip are user ids that already got a notification for this
    public function mentions(string $text, int $actorId, string $url, string $target, int $targetId, string $summary, array $skip = [], array $alreadyMentioned = []): void {
        global $db;

        $names = array_diff(self::mentioned($text), $alreadyMentioned);
        if (!$names) {
            return;
        }

        $people = $db->table('users')->whereIn('username_lower', array_values($names))->where('banned', 0)->get();
        foreach ($people as $person) {
            if (in_array((int) $person->id, $skip, true)) {
                continue;
            }
            $this->add((int) $person->id, $actorId, 'mention', $url, $target, $targetId, $summary);
        }
    }

    public function unreadCount(int $userId): int {
        global $db;
        return self::$unread[$userId] ??= $db->table('notifications')->where('user_id', $userId)->where('seen', 0)->count();
    }

    public function listFor(int $userId, int $offset, int $limit): array {
        global $db;
        $rows = $db->table('notifications')
            ->where('user_id', $userId)
            ->orderBy('id', 'DESC')
            ->limit($limit)
            ->offset($offset)
            ->get();
        $people = (new authentication())->mapByIds(array_map(fn ($n) => $n->actor_id, $rows));
        foreach ($rows as $row) {
            $row->actor = $people[$row->actor_id] ?? null;
        }
        return $rows;
    }

    public function countFor(int $userId): int {
        global $db;
        return $db->table('notifications')->where('user_id', $userId)->count();
    }

    public function get(int $userId, int $id) {
        global $db;
        return $db->table('notifications')->where('user_id', $userId)->where('id', $id)->first();
    }

    public function markSeen(int $userId, ?int $id = null): void {
        global $db;
        $query = $db->table('notifications')->where('user_id', $userId)->where('seen', 0);
        if ($id !== null) {
            $query->where('id', $id);
        }
        $query->update(['seen' => 1]);
        unset(self::$unread[$userId]);
    }

    public function clear(int $userId): void {
        global $db;
        $db->table('notifications')->where('user_id', $userId)->delete();
        unset(self::$unread[$userId]);
    }

    // when the thing a notification points at goes away
    public function forget(string $target, array $targetIds): void {
        global $db;
        if ($targetIds) {
            $db->table('notifications')->where('target', $target)->whereIn('target_id', $targetIds)->delete();
        }
    }

    // "a reaction was removed", "they unfollowed": take back the ping if it hasn't been seen
    public function retract(int $userId, int $actorId, string $type, ?string $target = null, ?int $targetId = null): void {
        global $db;
        $query = $db->table('notifications')
            ->where('user_id', $userId)
            ->where('actor_id', $actorId)
            ->where('type', $type)
            ->where('seen', 0);
        $query = $targetId === null ? $query->whereNull('target_id') : $query->where('target', $target)->where('target_id', $targetId);
        $query->delete();
        unset(self::$unread[$userId]);
    }

}
