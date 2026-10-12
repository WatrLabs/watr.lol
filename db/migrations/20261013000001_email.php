<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class Email extends AbstractMigration
{
    public function change(): void
    {
        // only ever holds an address someone proved they own. unverified ones wait in email_tokens,
        // so nobody can squat an address they can't read
        $this->table('users')
            ->addColumn('email', 'string', ['limit' => 254, 'null' => true])
            ->addColumn('email_lower', 'string', ['limit' => 254, 'null' => true])
            ->addIndex(['email_lower'], ['unique' => true])
            ->update();

        // links we email out: "verify this address" and "reset your password"
        $this->table('email_tokens')
            ->addColumn('user_id', 'integer')
            ->addColumn('purpose', 'string', ['limit' => 10])
            ->addColumn('token_hash', 'string', ['limit' => 64])
            ->addColumn('email', 'string', ['limit' => 254])
            ->addColumn('created', 'biginteger')
            ->addColumn('expires', 'biginteger')
            ->addColumn('used', 'boolean', ['default' => false])
            ->addIndex(['token_hash'], ['unique' => true])
            ->addIndex(['user_id', 'purpose'])
            ->create();
    }
}
