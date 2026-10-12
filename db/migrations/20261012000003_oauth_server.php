<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

// watr.lol as the place other sites send people to sign in
final class OauthServer extends AbstractMigration
{
    public function change(): void
    {
        $this->table('oauth_apps')
            ->addColumn('owner_id', 'integer')
            ->addColumn('name', 'string', ['limit' => 40])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('homepage', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('client_id', 'string', ['limit' => 32])
            ->addColumn('secret_hash', 'string', ['limit' => 64])
            ->addColumn('redirect_uris', 'text')
            ->addColumn('trusted', 'boolean', ['default' => false]) // first-party: skips the consent screen
            ->addColumn('created', 'biginteger')
            ->addIndex(['client_id'], ['unique' => true])
            ->addIndex(['owner_id'])
            ->create();

        $this->table('oauth_codes')
            ->addColumn('code_hash', 'string', ['limit' => 64])
            ->addColumn('app_id', 'integer')
            ->addColumn('user_id', 'integer')
            ->addColumn('redirect_uri', 'string', ['limit' => 255])
            ->addColumn('scope', 'string', ['limit' => 100])
            ->addColumn('code_challenge', 'string', ['limit' => 128, 'null' => true])
            ->addColumn('expires', 'biginteger')
            ->addColumn('used', 'boolean', ['default' => false])
            ->addIndex(['code_hash'], ['unique' => true])
            ->create();

        $this->table('oauth_tokens')
            ->addColumn('token_hash', 'string', ['limit' => 64])
            ->addColumn('app_id', 'integer')
            ->addColumn('user_id', 'integer')
            ->addColumn('scope', 'string', ['limit' => 100])
            ->addColumn('created', 'biginteger')
            ->addColumn('expires', 'biginteger')
            ->addColumn('revoked', 'boolean', ['default' => false])
            ->addIndex(['token_hash'], ['unique' => true])
            ->addIndex(['app_id', 'user_id'])
            ->create();

        // "you said yes to this app once", so it doesn't ask again
        $this->table('oauth_grants')
            ->addColumn('app_id', 'integer')
            ->addColumn('user_id', 'integer')
            ->addColumn('scope', 'string', ['limit' => 100])
            ->addColumn('created', 'biginteger')
            ->addIndex(['app_id', 'user_id'], ['unique' => true])
            ->addIndex(['user_id'])
            ->create();
    }
}
