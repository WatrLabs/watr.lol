<?php

namespace watrlabs\router;

class routing {

    protected array $routes = [];
    protected $currentprefix = '';
    protected $currentmiddleware = null;
    protected $notfound = null;

    protected function addRoute(string $method, string $uri, callable $callback): void {
        $fullUri = rtrim($this->currentprefix . $uri, '/') ?: '/';

        if ($this->currentmiddleware) {
            $originalcallback = $callback;
            $middleware = $this->currentmiddleware;

            $callback = function(...$args) use ($middleware, $originalcallback) {
                $middleware();
                return $originalcallback(...$args);
            };
        }

        $this->routes[$method][strtolower($fullUri)] = $callback;
    }

    public function get(string $uri, callable $callback) {
        $this->addRoute('GET', $uri, $callback);
    }

    public function post(string $uri, callable $callback) {
        $this->addRoute('POST', $uri, $callback);
    }

    public function put(string $uri, callable $callback) {
        $this->addRoute('PUT', $uri, $callback);
    }

    public function del(string $uri, callable $callback) {
        $this->addRoute('DELETE', $uri, $callback);
    }

    public function patch(string $uri, callable $callback) {
        $this->addRoute('PATCH', $uri, $callback);
    }

    public function options(string $uri, callable $callback) {
        $this->addRoute('OPTIONS', $uri, $callback);
    }

    public function group($prefix, $routes, $middleware = null) {
        $previousprefix = $this->currentprefix;
        $previousmiddleware = $this->currentmiddleware;

        $this->currentprefix .= rtrim($prefix, '/');
        $this->currentmiddleware = $middleware ?? $previousmiddleware;

        $routes($this);

        $this->currentprefix = $previousprefix;
        $this->currentmiddleware = $previousmiddleware;
    }

    // finds the route and runs it. returning an array from a route sends it as json
    public function dispatch($uri, $method) {

        $uri = rtrim($uri, '/') ?: '/';
        $method = $method === 'HEAD' ? 'GET' : $method;

        $callback = null;
        $params = [];

        if (isset($this->routes[$method][strtolower($uri)])) {
            $callback = $this->routes[$method][strtolower($uri)];
        } elseif (isset($this->routes[$method])) {
            foreach ($this->routes[$method] as $routepattern => $routecallback) {
                if (!str_contains($routepattern, '{')) {
                    continue;
                }
                // match without caring about case, but hand the params over as typed
                if (preg_match($this->convertregex($routepattern), $uri, $matches)) {
                    array_shift($matches);
                    $callback = $routecallback;
                    $params = array_map('urldecode', $matches);
                    break;
                }
            }
        }

        if ($callback) {
            $result = call_user_func_array($callback, $params);
            if (is_array($result)) {
                header('Content-type: application/json');
                echo json_encode($result, JSON_UNESCAPED_SLASHES);
                return null;
            }
            return $result;
        }

        foreach ($this->routes as $routesformethod) {
            foreach (array_keys($routesformethod) as $routepattern) {
                if ($routepattern === strtolower($uri) || (str_contains($routepattern, '{') && preg_match($this->convertregex($routepattern), $uri))) {
                    return $this->return_status(405);
                }
            }
        }

        return $this->return_status(404);
    }

    public function addrouter($routername) {
        global $router;
        $router = $this;
        require_once __DIR__ . "/../../../routes/{$routername}.php";
    }

    public function set404($function) {
        $this->notfound = $function;
    }

    public function return_status($statuscode) {
        http_response_code($statuscode);
        if ($this->notfound) {
            call_user_func($this->notfound, $statuscode);
        }
    }

    protected function convertregex($pattern) {
        $regex = preg_replace('#\\\\\{[^/]+?\\\\\}#', '([^/]+)', preg_quote($pattern, '#'));
        return '#^' . $regex . '$#i';
    }

}
