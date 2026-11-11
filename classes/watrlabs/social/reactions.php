<?php

namespace watrlabs\social;

// blob emoji reactions on forum posts and updates
class reactions {

    // name => the blob image in public/assets/images/blobs
    const EMOJI = [
        'love' => 'u1f60d',
        'haha' => 'u1f606',
        'wow' => 'u1f62e',
        'hug' => 'u1f917',
        'hmm' => 'thinking',
        'cool' => 'u1f60e',
    ];

    const TARGETS = ['post', 'status'];

    static function image(string $emoji): string {
        return '/assets/images/blobs/' . (self::EMOJI[$emoji] ?? 'u1f610') . '.png';
    }

    // adds it if it isn't there, takes it away if it is. returns true when it's now on
    public function toggle(string $target, int $targetId, $user, int $ownerId, string $url, string $summary, string $emoji): bool {
        global $db;

        $existing = $db->table('reactions')
            ->where('target', $target)->where('target_id', $targetId)
            ->where('user_id', $user->id)->where('emoji', $emoji)
            ->first();

        $notes = new notifications();

        if ($existing) {
            $db->table('reactions')->where('id', $existing->id)->delete();
            // only take the ping back once they've no reactions left on it
            $left = $db->table('reactions')->where('target', $target)->where('target_id', $targetId)->where('user_id', $user->id)->count();
            if ($left === 0) {
                $notes->retract($ownerId, (int) $user->id, 'reaction', $target, $targetId);
            }
            return false;
        }

        $db->table('reactions')->insert([
            'target' => $target,
            'target_id' => $targetId,
            'user_id' => $user->id,
            'emoji' => $emoji,
            'created' => time(),
        ]);
        $notes->add($ownerId, (int) $user->id, 'reaction', $url, $target, $targetId, $summary);
        return true;
    }

    // for a page of posts: [target_id => [emoji => ['count' => n, 'mine' => bool]]], in EMOJI order
    public function summarize(string $target, array $targetIds, ?int $viewerId): array {
        global $db;

        $targetIds = array_values(array_unique(array_map('intval', $targetIds)));
        if (!$targetIds) {
            return [];
        }

        $rows = $db->table('reactions')
            ->select(['target_id', 'emoji', 'user_id'])
            ->where('target', $target)
            ->whereIn('target_id', $targetIds)
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $slot = &$out[$row->target_id][$row->emoji];
            $slot ??= ['count' => 0, 'mine' => false];
            $slot['count']++;
            if ($viewerId !== null && (int) $row->user_id === $viewerId) {
                $slot['mine'] = true;
            }
            unset($slot);
        }

        foreach ($out as $id => $byEmoji) {
            $sorted = [];
            foreach (array_keys(self::EMOJI) as $emoji) {
                if (isset($byEmoji[$emoji])) {
                    $sorted[$emoji] = $byEmoji[$emoji];
                }
            }
            $out[$id] = $sorted;
        }

        return $out;
    }

    public function forget(string $target, array $targetIds): void {
        global $db;
        if ($targetIds) {
            $db->table('reactions')->where('target', $target)->whereIn('target_id', $targetIds)->delete();
        }
    }

}
