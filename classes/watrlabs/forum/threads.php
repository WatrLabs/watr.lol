<?php

namespace watrlabs\forum;

use watrlabs\authentication;

class threads {

    const TITLE_MAX = 120;

    public function get(int $id) {
        global $db;
        return $db->table('forum_threads')->where('id', $id)->first();
    }

    public function countForCategory(int $categoryId): int {
        global $db;
        return $db->table('forum_threads')->where('category_id', $categoryId)->count();
    }

    // pinned first, then whatever was replied to most recently
    public function listForCategory(int $categoryId, int $offset, int $limit): array {
        global $db;
        $threads = $db->table('forum_threads')
            ->where('category_id', $categoryId)
            ->orderBy('pinned', 'DESC')
            ->orderBy('last_post_at', 'DESC')
            ->limit($limit)
            ->offset($offset)
            ->get();
        return $this->withPeople($threads);
    }

    public function recent(int $limit = 10): array {
        global $db;
        $threads = $db->table('forum_threads')->orderBy('last_post_at', 'DESC')->limit($limit)->get();
        return $this->withCategories($this->withPeople($threads));
    }

    public function byUser(int $userId, int $limit = 10): array {
        global $db;
        $threads = $db->table('forum_threads')->where('user_id', $userId)->orderBy('created', 'DESC')->limit($limit)->get();
        return $this->withCategories($this->withPeople($threads));
    }

    // hangs ->author and ->last_poster on each thread
    public function withPeople(array $threads): array {
        $ids = [];
        foreach ($threads as $thread) {
            $ids[] = $thread->user_id;
            $ids[] = $thread->last_post_user_id;
        }
        $people = (new authentication())->mapByIds($ids);
        foreach ($threads as $thread) {
            $thread->author = $people[$thread->user_id] ?? null;
            $thread->last_poster = $people[$thread->last_post_user_id] ?? null;
        }
        return $threads;
    }

    public function withCategories(array $threads): array {
        $categories = [];
        foreach ((new categories())->all() as $category) {
            $categories[$category->id] = $category;
        }
        foreach ($threads as $thread) {
            $thread->category = $categories[$thread->category_id] ?? null;
        }
        return $threads;
    }

    public function validateTitle(string $title): ?string {
        if (mb_strlen($title) < 3) {
            return 'Give your thread a title, at least 3 characters.';
        }
        if (mb_strlen($title) > self::TITLE_MAX) {
            return 'Thread titles can be ' . self::TITLE_MAX . ' characters at most.';
        }
        return null;
    }

    // makes the thread and its first post together
    public function create(int $categoryId, int $userId, string $title, string $body): int {
        global $db;

        $now = time();
        $threadId = (int) $db->table('forum_threads')->insert([
            'category_id' => $categoryId,
            'user_id' => $userId,
            'title' => $title,
            'post_count' => 0,
            'created' => $now,
            'last_post_at' => $now,
            'last_post_user_id' => $userId,
        ]);

        (new posts())->create($threadId, $userId, $body);

        return $threadId;
    }

    public function update(int $id, array $fields): void {
        global $db;
        $db->table('forum_threads')->where('id', $id)->update($fields);
    }

    public function delete(int $id): void {
        global $db;
        $db->table('forum_posts')->where('thread_id', $id)->delete();
        $db->table('forum_threads')->where('id', $id)->delete();
    }

    // after a post goes away the thread needs its counts and "last reply" redone
    public function recount(int $id): void {
        global $db;

        $count = $db->table('forum_posts')->where('thread_id', $id)->count();
        if ($count === 0) {
            $this->delete($id);
            return;
        }

        $last = $db->table('forum_posts')->where('thread_id', $id)->orderBy('id', 'DESC')->first();
        $this->update($id, [
            'post_count' => $count,
            'last_post_at' => $last->created,
            'last_post_user_id' => $last->user_id,
        ]);
    }

}
