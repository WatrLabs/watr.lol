<?php

namespace watrlabs;

use watrlabs\forum\posts;
use watrlabs\forum\threads;
use watrlabs\social\statuses;

// deleting your own account. everything that's yours goes. forum posts stay (as "deleted")
// unless you ask for them to go too, because other people's replies hang off them
class accountdeletion {

    const WIPED_BODY = '*This post was deleted.*';

    // null when they're allowed to, otherwise why not
    public function blocker($user): ?string {
        global $db;
        if ($user->role === 'admin' && $db->table('users')->where('role', 'admin')->where('id', '!=', $user->id)->count() === 0) {
            return "You're the only admin. Make someone else an admin first, so the site isn't left without one.";
        }
        return null;
    }

    public function delete($user, bool $wipePosts): void {
        global $db;
        $id = (int) $user->id;

        if ($wipePosts) {
            $this->wipeForumPosts($id);
        }

        // updates are personal, they always go
        $statuses = new statuses();
        foreach ($db->table('statuses')->select('id')->where('user_id', $id)->get() as $s) {
            $statuses->delete((int) $s->id);
        }

        // apps they made stop working for everyone
        $appIds = array_map(fn ($a) => (int) $a->id, $db->table('oauth_apps')->select('id')->where('owner_id', $id)->get());
        if ($appIds) {
            foreach (['oauth_tokens', 'oauth_codes', 'oauth_grants'] as $table) {
                $db->table($table)->whereIn('app_id', $appIds)->delete();
            }
            $db->table('oauth_apps')->whereIn('id', $appIds)->delete();
        }

        foreach (['sessions', 'oauth_tokens', 'oauth_codes', 'oauth_grants', 'linked_accounts', 'email_tokens', 'backup_codes', 'reactions'] as $table) {
            $db->table($table)->where('user_id', $id)->delete();
        }
        $db->table('follows')->where('follower_id', $id)->delete();
        $db->table('follows')->where('followee_id', $id)->delete();
        $db->table('notifications')->where('user_id', $id)->delete();
        $db->table('notifications')->where('actor_id', $id)->delete();

        $db->table('users')->where('id', $id)->delete();
    }

    // replies go. threads they started go too, unless someone else replied, then just the words go
    private function wipeForumPosts(int $userId): void {
        global $db;

        $posts = new posts();
        $threads = new threads();

        foreach ($db->table('forum_threads')->where('user_id', $userId)->get() as $thread) {
            $others = $db->table('forum_posts')->where('thread_id', $thread->id)->where('user_id', '!=', $userId)->count();
            if ($others === 0) {
                $threads->delete((int) $thread->id);
                continue;
            }
            $first = $posts->first((int) $thread->id);
            if ($first && (int) $first->user_id === $userId) {
                $db->table('forum_posts')->where('id', $first->id)->update(['body' => self::WIPED_BODY, 'edited' => time()]);
            }
        }

        // whatever's left is replies in other people's threads (or later replies in their own)
        $rows = $db->table('forum_posts')->where('user_id', $userId)->where('body', '!=', self::WIPED_BODY)->get();
        foreach ($rows as $post) {
            $first = $posts->first((int) $post->thread_id);
            if ($first && (int) $first->id === (int) $post->id) {
                continue; // a thread's first post, handled above
            }
            $posts->delete($post);
        }
    }

}
