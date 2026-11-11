<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

// following people, short updates, reactions, notifications, two-factor login and a few profile fields
final class Social extends AbstractMigration
{
    public function change(): void
    {
        $this->table('users')
            ->addColumn('pronouns', 'string', ['limit' => 30, 'null' => true])
            ->addColumn('website', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('location', 'string', ['limit' => 60, 'null' => true])
            ->addColumn('totp_secret', 'string', ['limit' => 64, 'null' => true]) // null = two-factor is off
            ->addColumn('totp_last_step', 'biginteger', ['null' => true])         // so one code can't be used twice
            ->update();

        // one-time codes for when your phone is gone
        $this->table('backup_codes')
            ->addColumn('user_id', 'integer')
            ->addColumn('code_hash', 'string', ['limit' => 64])
            ->addColumn('used', 'boolean', ['default' => false])
            ->addColumn('created', 'biginteger')
            ->addIndex(['user_id'])
            ->create();

        $this->table('follows')
            ->addColumn('follower_id', 'integer')
            ->addColumn('followee_id', 'integer')
            ->addColumn('created', 'biginteger')
            ->addIndex(['follower_id', 'followee_id'], ['unique' => true])
            ->addIndex(['followee_id'])
            ->create();

        // the little "what's up" posts on the home page and profiles
        $this->table('statuses')
            ->addColumn('user_id', 'integer')
            ->addColumn('body', 'text')
            ->addColumn('created', 'biginteger')
            ->addIndex(['user_id', 'created'])
            ->addIndex(['created'])
            ->create();

        // target is 'post' (forum) or 'status'
        $this->table('reactions')
            ->addColumn('target', 'string', ['limit' => 10])
            ->addColumn('target_id', 'integer')
            ->addColumn('user_id', 'integer')
            ->addColumn('emoji', 'string', ['limit' => 10])
            ->addColumn('created', 'biginteger')
            ->addIndex(['target', 'target_id', 'user_id', 'emoji'], ['unique' => true])
            ->addIndex(['user_id'])
            ->create();

        // type: reply, mention, follow, reaction. url is where clicking it goes
        $this->table('notifications')
            ->addColumn('user_id', 'integer')
            ->addColumn('actor_id', 'integer')
            ->addColumn('type', 'string', ['limit' => 10])
            ->addColumn('target', 'string', ['limit' => 10, 'null' => true])
            ->addColumn('target_id', 'integer', ['null' => true])
            ->addColumn('summary', 'string', ['limit' => 160, 'null' => true])
            ->addColumn('url', 'string', ['limit' => 255])
            ->addColumn('seen', 'boolean', ['default' => false])
            ->addColumn('created', 'biginteger')
            ->addIndex(['user_id', 'seen'])
            ->addIndex(['user_id', 'created'])
            ->addIndex(['actor_id'])
            ->create();

        // the feed shows forum posts from people you follow, newest first
        $this->table('forum_posts')
            ->addIndex(['user_id', 'created'])
            ->addIndex(['created'])
            ->update();
    }
}
