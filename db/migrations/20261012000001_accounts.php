<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class Accounts extends AbstractMigration
{
    public function change(): void
    {
        $this->table('users')
            ->addColumn('username', 'string', ['limit' => 20])
            ->addColumn('username_lower', 'string', ['limit' => 20]) // usernames are unique ignoring case, on every database
            ->addColumn('password', 'string', ['limit' => 255, 'null' => true]) // null = only signs in through a linked account
            ->addColumn('display_name', 'string', ['limit' => 40, 'null' => true])
            ->addColumn('bio', 'text', ['null' => true])
            ->addColumn('role', 'string', ['limit' => 10, 'default' => 'user'])
            ->addColumn('banned', 'boolean', ['default' => false])
            ->addColumn('ban_reason', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('created', 'biginteger')
            ->addColumn('updated', 'biginteger', ['null' => true])
            ->addColumn('last_seen', 'biginteger', ['null' => true])
            ->addIndex(['username_lower'], ['unique' => true])
            ->create();

        $this->table('sessions')
            ->addColumn('token_hash', 'string', ['limit' => 64])
            ->addColumn('user_id', 'integer')
            ->addColumn('user_agent', 'string', ['limit' => 255])
            ->addColumn('ip', 'string', ['limit' => 45])
            ->addColumn('created', 'biginteger')
            ->addColumn('last_seen', 'biginteger')
            ->addColumn('expires', 'biginteger')
            ->addIndex(['token_hash'], ['unique' => true])
            ->addIndex(['user_id'])
            ->create();

        // counts things like failed logins per ip
        $this->table('throttle')
            ->addColumn('bucket', 'string', ['limit' => 30])
            ->addColumn('thekey', 'string', ['limit' => 100])
            ->addColumn('created', 'biginteger')
            ->addIndex(['bucket', 'thekey', 'created'])
            ->create();
    }
}
