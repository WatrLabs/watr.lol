<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class Forum extends AbstractMigration
{
    public function change(): void
    {
        $this->table('forum_categories')
            ->addColumn('slug', 'string', ['limit' => 40])
            ->addColumn('name', 'string', ['limit' => 60])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('position', 'integer', ['default' => 0])
            ->addColumn('staff_only', 'boolean', ['default' => false]) // only mods and admins can start threads
            ->addColumn('created', 'biginteger')
            ->addIndex(['slug'], ['unique' => true])
            ->create();

        $this->table('forum_threads')
            ->addColumn('category_id', 'integer')
            ->addColumn('user_id', 'integer')
            ->addColumn('title', 'string', ['limit' => 120])
            ->addColumn('pinned', 'boolean', ['default' => false])
            ->addColumn('locked', 'boolean', ['default' => false])
            ->addColumn('post_count', 'integer', ['default' => 0])
            ->addColumn('created', 'biginteger')
            ->addColumn('last_post_at', 'biginteger')
            ->addColumn('last_post_user_id', 'integer', ['null' => true])
            ->addIndex(['category_id', 'pinned', 'last_post_at'])
            ->addIndex(['user_id'])
            ->create();

        $this->table('forum_posts')
            ->addColumn('thread_id', 'integer')
            ->addColumn('user_id', 'integer')
            ->addColumn('body', 'text')
            ->addColumn('created', 'biginteger')
            ->addColumn('edited', 'biginteger', ['null' => true])
            ->addIndex(['thread_id', 'id'])
            ->addIndex(['user_id'])
            ->create();
    }
}
