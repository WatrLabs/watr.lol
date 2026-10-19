<?php

namespace watrlabs;

use watrlabs\watrkit\sanitize;

class projects {

    // every project in order, active or not. for the admin list
    public function all(): array {
        global $db;
        return $db->table('projects')->orderBy('position', 'ASC')->orderBy('id', 'ASC')->get();
    }

    // what /projects shows
    public function active(): array {
        global $db;
        return $db->table('projects')->where('active', 1)->orderBy('position', 'ASC')->orderBy('id', 'ASC')->get();
    }

    public function get(int $id) {
        global $db;
        return $db->table('projects')->where('id', $id)->first();
    }

    // null when it's fine, otherwise what's wrong
    public function validate(string $name, string $url): ?string {
        if ($name === '' || mb_strlen($name) > 60) {
            return 'Project names are 1 to 60 characters long.';
        }
        if ($url !== '' && (!sanitize::url($url) || strlen($url) > 255)) {
            return 'The link needs to be a full http:// or https:// url, 255 characters at most.';
        }
        return null;
    }

    public function create(string $name, string $description, string $url, bool $active): int {
        global $db;
        $position = (int) ($db->table('projects')->select([$db->raw('MAX(position) AS p')])->first()->p ?? 0) + 1;
        return (int) $db->table('projects')->insert([
            'name' => $name,
            'description' => $description ?: null,
            'url' => $url ?: null,
            'active' => $active ? 1 : 0,
            'position' => $position,
            'created' => time(),
        ]);
    }

    public function update(int $id, array $fields): void {
        global $db;
        $db->table('projects')->where('id', $id)->update($fields);
    }

    public function delete(int $id): void {
        global $db;
        $db->table('projects')->where('id', $id)->delete();
    }

    // swaps a project with its neighbour. $direction is -1 (up) or 1 (down)
    public function move(int $id, int $direction): void {
        $all = $this->all();
        $index = null;
        foreach ($all as $i => $project) {
            if ((int) $project->id === $id) $index = $i;
        }
        $other = $index === null ? null : ($all[$index + $direction] ?? null);
        if (!$other) {
            return;
        }
        // renumber everything so equal positions can't get stuck
        $order = array_map(fn ($p) => $p->id, $all);
        [$order[$index], $order[$index + $direction]] = [$order[$index + $direction], $order[$index]];
        foreach ($order as $position => $projectId) {
            $this->update($projectId, ['position' => $position]);
        }
    }

}
