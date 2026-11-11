<?php

namespace watrlabs\social;

use watrlabs\authentication;

// the home feed: updates and forum posts, newest first, from people you follow (or everyone)
class feed {

    const PER_PAGE = 20;

    // $authorIds null = everyone. $before pages back in time (a unix time from the last page)
    // returns ['items' => [...], 'next' => ?int]
    public function build(?array $authorIds, ?int $before, ?int $viewerId, int $limit = self::PER_PAGE): array {
        global $db;

        if ($authorIds !== null && !$authorIds) {
            return ['items' => [], 'next' => null];
        }

        // one extra so we know if there's another page
        $grab = $limit + 1;

        $statuses = $db->table('statuses');
        $posts = $db->table('forum_posts');
        if ($authorIds !== null) {
            $statuses->whereIn('user_id', $authorIds);
            $posts->whereIn('user_id', $authorIds);
        }
        if ($before) {
            $statuses->where('created', '<', $before);
            $posts->where('created', '<', $before);
        }
        $statuses = $statuses->orderBy('created', 'DESC')->orderBy('id', 'DESC')->limit($grab)->get();
        $posts = $posts->orderBy('created', 'DESC')->orderBy('id', 'DESC')->limit($grab)->get();

        $items = [];
        foreach ($statuses as $s) {
            $items[] = (object) ['kind' => 'status', 'id' => (int) $s->id, 'user_id' => (int) $s->user_id, 'body' => $s->body, 'created' => (int) $s->created, 'row' => $s];
        }
        foreach ($posts as $p) {
            $items[] = (object) ['kind' => 'post', 'id' => (int) $p->id, 'user_id' => (int) $p->user_id, 'body' => $p->body, 'created' => (int) $p->created, 'row' => $p];
        }

        usort($items, fn ($a, $b) => [$b->created, $b->id] <=> [$a->created, $a->id]);

        $next = null;
        if (count($items) > $limit) {
            // keep everything from the same second as the last one, so paging by time skips nothing
            $cut = $items[$limit - 1]->created;
            $page = [];
            foreach ($items as $item) {
                if (count($page) >= $limit && $item->created < $cut) {
                    break;
                }
                $page[] = $item;
            }
            $items = $page;
            $next = $cut;
        }

        return ['items' => $this->decorate($items, $viewerId), 'next' => $next];
    }

    // hangs authors, thread titles and reactions on each item, and drops banned people
    private function decorate(array $items, ?int $viewerId): array {
        global $db;

        $people = (new authentication())->mapByIds(array_map(fn ($i) => $i->user_id, $items));

        $threadIds = [];
        foreach ($items as $item) {
            if ($item->kind === 'post') {
                $threadIds[] = (int) $item->row->thread_id;
            }
        }
        $threadIds = array_values(array_unique($threadIds));

        $threads = [];
        $firsts = [];
        if ($threadIds) {
            foreach ($db->table('forum_threads')->whereIn('id', $threadIds)->get() as $t) {
                $threads[$t->id] = $t;
            }
            $rows = $db->table('forum_posts')
                ->select(['thread_id', $db->raw('MIN(id) AS first_id')])
                ->whereIn('thread_id', $threadIds)
                ->groupBy('thread_id')
                ->get();
            foreach ($rows as $r) {
                $firsts[$r->thread_id] = (int) $r->first_id;
            }
        }

        $reactions = new reactions();
        $statusReactions = $reactions->summarize('status', array_map(fn ($i) => $i->id, array_filter($items, fn ($i) => $i->kind === 'status')), $viewerId);
        $postReactions = $reactions->summarize('post', array_map(fn ($i) => $i->id, array_filter($items, fn ($i) => $i->kind === 'post')), $viewerId);

        $out = [];
        foreach ($items as $item) {
            $item->author = $people[$item->user_id] ?? null;
            if (!$item->author || $item->author->banned) {
                continue;
            }
            if ($item->kind === 'post') {
                $item->thread = $threads[$item->row->thread_id] ?? null;
                if (!$item->thread) {
                    continue;
                }
                $item->started = ($firsts[$item->row->thread_id] ?? 0) === $item->id;
                $item->reactions = $postReactions[$item->id] ?? [];
            } else {
                $item->reactions = $statusReactions[$item->id] ?? [];
            }
            $out[] = $item;
        }
        return $out;
    }

}
