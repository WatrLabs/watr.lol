<?php

use Phinx\Seed\AbstractSeed;

// a few categories so a fresh forum isn't empty. run with: vendor/bin/phinx seed:run
class ForumSeeder extends AbstractSeed
{
    public function run(): void
    {
        if ($this->fetchRow('SELECT id FROM forum_categories LIMIT 1')) {
            return;
        }

        $now = time();
        $this->table('forum_categories')->insert([
            ['slug' => 'announcements', 'name' => 'Announcements', 'description' => 'News about watr.lol and the other watrlabs sites.', 'position' => 0, 'staff_only' => 1, 'created' => $now],
            ['slug' => 'general', 'name' => 'General', 'description' => 'Anything goes. Within reason.', 'position' => 1, 'staff_only' => 0, 'created' => $now],
            ['slug' => 'help', 'name' => 'Help', 'description' => 'Stuck on something? Ask here.', 'position' => 2, 'staff_only' => 0, 'created' => $now],
            ['slug' => 'showcase', 'name' => 'Showcase', 'description' => 'Show off what you made.', 'position' => 3, 'staff_only' => 0, 'created' => $now],
        ])->saveData();
    }
}
