<?php

namespace watrlabs;

// counts things (failed logins, new posts) and says when there have been too many
class throttle {

    static function tooMany(string $bucket, string $key, int $max, int $window): bool {
        global $db;
        return $db->table('throttle')
            ->where('bucket', $bucket)
            ->where('thekey', $key)
            ->where('created', '>', time() - $window)
            ->count() >= $max;
    }

    static function hit(string $bucket, string $key): void {
        global $db;
        $db->table('throttle')->insert(['bucket' => $bucket, 'thekey' => substr($key, 0, 100), 'created' => time()]);

        // tidy up now and then instead of needing a cron job
        if (random_int(1, 50) === 1) {
            $db->table('throttle')->where('created', '<', time() - 86400)->delete();
        }
    }

    static function clear(string $bucket, string $key): void {
        global $db;
        $db->table('throttle')->where('bucket', $bucket)->where('thekey', $key)->delete();
    }

}
