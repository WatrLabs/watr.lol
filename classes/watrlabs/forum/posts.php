<?php

namespace watrlabs\forum;

use watrlabs\authentication;

class posts {

    const BODY_MAX = 10000;

    public function get(int $id) {
        global $db;
        return $db->table('forum_posts')->where('id', $id)->first();
    }

    public function first(int $threadId) {
        global $db;
        return $db->table('forum_posts')->where('thread_id', $threadId)->orderBy('id', 'ASC')->first();
    }

    public function listForThread(int $threadId, int $offset, int $limit): array {
        global $db;

        $posts = $db->table('forum_posts')
            ->where('thread_id', $threadId)
            ->orderBy('id', 'ASC')
            ->limit($limit)
            ->offset($offset)
            ->get();

        $people = (new authentication())->mapByIds(array_map(fn ($p) => $p->user_id, $posts));
        $counts = $this->countsFor(array_keys($people));

        foreach ($posts as $post) {
            $post->author = $people[$post->user_id] ?? null;
            $post->author_posts = $counts[$post->user_id] ?? 0;
        }

        return $posts;
    }

    // how many posts each of these people has made, keyed by user id
    public function countsFor(array $userIds): array {
        global $db;

        if (!$userIds) {
            return [];
        }

        $counts = [];
        $rows = $db->table('forum_posts')
            ->select(['user_id', $db->raw('COUNT(*) AS c')])
            ->whereIn('user_id', $userIds)
            ->groupBy('user_id')
            ->get();
        foreach ($rows as $row) {
            $counts[$row->user_id] = (int) $row->c;
        }
        return $counts;
    }

    // which page of the thread a post lands on
    public function pageOf($post, int $perPage): int {
        global $db;
        $before = $db->table('forum_posts')->where('thread_id', $post->thread_id)->where('id', '<', $post->id)->count();
        return intdiv($before, $perPage) + 1;
    }

    public function validateBody(string $body): ?string {
        if (mb_strlen($body) < 2) {
            return 'Write something first.';
        }
        if (mb_strlen($body) > self::BODY_MAX) {
            return 'Posts can be ' . number_format(self::BODY_MAX) . ' characters at most. Yours is ' . number_format(mb_strlen($body)) . '.';
        }
        return null;
    }

    public function create(int $threadId, int $userId, string $body): int {
        global $db;

        $now = time();
        $postId = (int) $db->table('forum_posts')->insert([
            'thread_id' => $threadId,
            'user_id' => $userId,
            'body' => $body,
            'created' => $now,
        ]);

        $db->table('forum_threads')->where('id', $threadId)->update([
            'post_count' => $db->raw('post_count + 1'),
            'last_post_at' => $now,
            'last_post_user_id' => $userId,
        ]);

        return $postId;
    }

    public function update(int $id, string $body): void {
        global $db;
        $db->table('forum_posts')->where('id', $id)->update(['body' => $body, 'edited' => time()]);
    }

    // deleting the first post deletes the whole thread, there's nothing left to hang it on
    public function delete($post): void {
        global $db;

        $first = $this->first($post->thread_id);
        if ($first && $first->id === $post->id) {
            (new threads())->delete($post->thread_id);
            return;
        }

        $db->table('forum_posts')->where('id', $post->id)->delete();
        (new threads())->recount($post->thread_id);
    }

    public function countByUser(int $userId): int {
        global $db;
        return $db->table('forum_posts')->where('user_id', $userId)->count();
    }

}
