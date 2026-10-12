<?php

namespace watrlabs\forum;

class categories {

    private static $cache = null;

    // every category with its thread count, in order. cached for the request since the sidebar asks too
    public function all(): array {
        global $db;

        if (self::$cache !== null) {
            return self::$cache;
        }

        $counts = [];
        $rows = $db->table('forum_threads')
            ->select(['category_id', $db->raw('COUNT(*) AS threads'), $db->raw('SUM(post_count) AS posts'), $db->raw('MAX(last_post_at) AS last_post_at')])
            ->groupBy('category_id')
            ->get();
        foreach ($rows as $row) {
            $counts[$row->category_id] = $row;
        }

        $categories = $db->table('forum_categories')->orderBy('position', 'ASC')->orderBy('id', 'ASC')->get();
        foreach ($categories as $category) {
            $category->threads = (int) ($counts[$category->id]->threads ?? 0);
            $category->posts = (int) ($counts[$category->id]->posts ?? 0);
            $category->last_post_at = $counts[$category->id]->last_post_at ?? null;
        }

        return self::$cache = $categories;
    }

    public function get(int $id) {
        global $db;
        return $db->table('forum_categories')->where('id', $id)->first();
    }

    public function bySlug(string $slug) {
        global $db;
        return $db->table('forum_categories')->where('slug', strtolower($slug))->first();
    }

    // null when it's fine, otherwise what's wrong
    public function validate(string $name, string $slug, ?int $ignoreId = null): ?string {
        if ($name === '' || mb_strlen($name) > 60) {
            return 'Category names are 1 to 60 characters long.';
        }
        if ($slug === '' || !preg_match('/^[a-z0-9-]{1,40}$/', $slug)) {
            return 'The url name can use lowercase letters, numbers and dashes.';
        }
        if (in_array($slug, ['t', 'p', 'new'], true)) {
            return 'That url name is used by the forum itself. Pick another one.';
        }
        $existing = $this->bySlug($slug);
        if ($existing && (int) $existing->id !== $ignoreId) {
            return 'Another category already uses that url name.';
        }
        return null;
    }

    public function create(string $name, string $slug, string $description, bool $staffOnly): int {
        global $db;
        $position = (int) ($db->table('forum_categories')->select([$db->raw('MAX(position) AS p')])->first()->p ?? 0) + 1;
        self::$cache = null;
        return (int) $db->table('forum_categories')->insert([
            'name' => $name,
            'slug' => $slug,
            'description' => $description ?: null,
            'position' => $position,
            'staff_only' => $staffOnly ? 1 : 0,
            'created' => time(),
        ]);
    }

    public function update(int $id, array $fields): void {
        global $db;
        self::$cache = null;
        $db->table('forum_categories')->where('id', $id)->update($fields);
    }

    // only empty categories go, so nobody loses threads by accident
    public function delete(int $id): bool {
        global $db;
        if ($db->table('forum_threads')->where('category_id', $id)->count() > 0) {
            return false;
        }
        self::$cache = null;
        $db->table('forum_categories')->where('id', $id)->delete();
        return true;
    }

    // swaps a category with its neighbour. $direction is -1 (up) or 1 (down)
    public function move(int $id, int $direction): void {
        $all = $this->all();
        $index = null;
        foreach ($all as $i => $category) {
            if ((int) $category->id === $id) $index = $i;
        }
        $other = $index === null ? null : ($all[$index + $direction] ?? null);
        if (!$other) {
            return;
        }
        // renumber everything so equal positions can't get stuck
        $order = array_map(fn ($c) => $c->id, $all);
        [$order[$index], $order[$index + $direction]] = [$order[$index + $direction], $order[$index]];
        foreach ($order as $position => $categoryId) {
            $this->update($categoryId, ['position' => $position]);
        }
    }

}
