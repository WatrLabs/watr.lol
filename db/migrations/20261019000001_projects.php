<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class Projects extends AbstractMigration
{
    public function change(): void
    {
        // the watrlabs things listed on /projects. admins add them, inactive ones stay hidden
        $this->table('projects')
            ->addColumn('name', 'string', ['limit' => 60])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('url', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('active', 'boolean', ['default' => true])
            ->addColumn('position', 'integer', ['default' => 0])
            ->addColumn('created', 'biginteger')
            ->addIndex(['active', 'position'])
            ->create();
    }
}
