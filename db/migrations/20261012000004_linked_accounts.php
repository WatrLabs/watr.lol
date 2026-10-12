<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

// discord, github, google and friends, attached to a watr.lol account
final class LinkedAccounts extends AbstractMigration
{
    public function change(): void
    {
        $this->table('linked_accounts')
            ->addColumn('user_id', 'integer')
            ->addColumn('provider', 'string', ['limit' => 20])
            ->addColumn('provider_user_id', 'string', ['limit' => 100])
            ->addColumn('provider_username', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('created', 'biginteger')
            ->addIndex(['provider', 'provider_user_id'], ['unique' => true])
            ->addIndex(['user_id', 'provider'], ['unique' => true])
            ->create();
    }
}
