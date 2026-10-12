<?php

namespace watrlabs;

// json replies for the api. routes can also just return an array
class api {

    static function error(string $error, int $code = 400, ?string $description = null): array {
        http_response_code($code);
        $body = ['error' => $error];
        if ($description) {
            $body['error_description'] = $description;
        }
        return $body;
    }

    static function cors(): void {
        // the api is bearer-token only, no cookies, so any site can call it
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    }

}
