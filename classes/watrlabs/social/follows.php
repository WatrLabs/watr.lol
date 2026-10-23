<?php

namespace watrlabs\social;

use watrlabs\authentication;

// following someone puts their updates and forum posts in your home feed
class follows {

    const MAX_FOLLOWING = 2000;

    public function isFollowing(int $followerId, int $followeeId): bool {
        global $db;
        return (bool) $db->table('follows')->where('follower_id', $followerId)->where('followee_id', $followeeId)->first();
    }

    // returns an error message, or null when it worked
    public function follow($follower, $followee): ?string {
        global $db;

        if ((int) $follower->id === (int) $followee->id) {
            return "You can't follow yourself.";
        }
        if ($followee->banned) {
            return "You can't follow a banned account.";
        }
        if ($this->isFollowing((int) $follower->id, (int) $followee->id)) {
            return null;
        }
        if ($this->followingCount((int) $follower->id) >= self::MAX_FOLLOWING) {
            return 'You follow a lot of people already. Unfollow some first.';
        }

        $db->table('follows')->insert([
            'follower_id' => $follower->id,
            'followee_id' => $followee->id,
            'created' => time(),
        ]);

        (new notifications())->add((int) $followee->id, (int) $follower->id, 'follow', '/u/' . $follower->username);
        return null;
    }

    public function unfollow(int $followerId, int $followeeId): void {
        global $db;
        $db->table('follows')->where('follower_id', $followerId)->where('followee_id', $followeeId)->delete();
        (new notifications())->retract($followeeId, $followerId, 'follow');
    }

    public function followerCount(int $userId): int {
        global $db;
        return $db->table('follows')->where('followee_id', $userId)->count();
    }

    public function followingCount(int $userId): int {
        global $db;
        return $db->table('follows')->where('follower_id', $userId)->count();
    }

    // ids of everyone $userId follows
    public function followingIds(int $userId): array {
        global $db;
        return array_map(fn ($r) => (int) $r->followee_id, $db->table('follows')->select('followee_id')->where('follower_id', $userId)->get());
    }

    // people for the followers / following pages, newest first
    public function listPeople(int $userId, string $which, int $offset, int $limit): array {
        global $db;
        [$match, $other] = $which === 'followers' ? ['followee_id', 'follower_id'] : ['follower_id', 'followee_id'];
        $rows = $db->table('follows')->where($match, $userId)->orderBy('id', 'DESC')->limit($limit)->offset($offset)->get();
        $people = (new authentication())->mapByIds(array_map(fn ($r) => $r->$other, $rows));
        $out = [];
        foreach ($rows as $row) {
            if (isset($people[$row->$other])) {
                $out[] = $people[$row->$other];
            }
        }
        return $out;
    }

    // a few people you might like: active lately, not banned, not already followed
    public function suggestions(int $userId, int $limit = 5): array {
        global $db;
        $skip = $this->followingIds($userId);
        $skip[] = $userId;
        return $db->table('users')
            ->where('banned', 0)
            ->whereNotIn('id', $skip)
            ->orderBy('last_seen', 'DESC')
            ->limit($limit)
            ->get();
    }

}
