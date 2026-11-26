<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    private array $routes = [];
    private array $groups = [];

    public function get(string $path, array|callable $handler, array $mw = []): void { $this->add(['GET'], $path, $handler, $mw); }
    public function post(string $path, array|callable $handler, array $mw = []): void { $this->add(['POST'], $path, $handler, $mw); }
    public function put(string $path, array|callable $handler, array $mw = []): void { $this->add(['PUT', 'PATCH'], $path, $handler, $mw); }
    public function delete(string $path, array|callable $handler, array $mw = []): void { $this->add(['DELETE'], $path, $handler, $mw); }
    public function match(array $methods, string $path, array|callable $handler, array $mw = []): void { $this->add($methods, $path, $handler, $mw); }

    public function group(array $attributes, callable $callback): void
    {
        $this->groups[] = $attributes;
        $callback($this);
        array_pop($this->groups);
    }

    private function add(array $methods, string $path, array|callable $handler, array $mw): void
    {
        $prefix = '';
        $middleware = [];
        foreach ($this->groups as $g) {
            $prefix .= $g['prefix'] ?? '';
            $middleware = array_merge($middleware, $g['middleware'] ?? []);
        }
        $full = '/' . trim($prefix . '/' . trim($path, '/'), '/');
        $regex = preg_replace_callback('/\{(\w+)(?::((?:[^{}]|\{[^{}]*\})+))?\}/', static function ($m) {
            return '(?P<' . $m[1] . '>' . ($m[2] ?? '[^/]+') . ')';
        }, $full);
        $this->routes[] = [
            'methods' => $methods,
            'regex' => '#^' . $regex . '$#u',
            'handler' => $handler,
            'middleware' => array_merge($middleware, $mw),
        ];
    }

    public function dispatch(Request $request): Response
    {
        $path = $request->path();
        $method = $request->method();
        $allowed = [];
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            if (!in_array($method, $route['methods'], true) && !($method === 'OPTIONS' && in_array('api', $route['middleware'], true))) {
                $allowed = array_merge($allowed, $route['methods']);
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            $request->setRouteParams($params);
            $core = fn (Request $r): Response => $this->call($route['handler'], $r, $params);
            $pipeline = array_reduce(
                array_reverse($route['middleware']),
                static fn (callable $next, string $spec) => static fn (Request $r) => Middleware::resolve($spec)($r, $next),
                $core
            );
            return $pipeline($request);
        }
        if ($allowed) {
            throw new HttpException(405, 'الطريقة غير مسموحة لهذا المسار', ['Allow' => implode(', ', array_unique($allowed))]);
        }
        throw new HttpException(404, 'الصفحة غير موجودة');
    }

    private function call(array|callable $handler, Request $request, array $params): Response
    {
        if (is_array($handler)) {
            [$class, $method] = $handler;
            $result = (new $class($request))->$method(...array_values($params));
        } else {
            $result = $handler($request, ...array_values($params));
        }
        if ($result instanceof Response) {
            return $result;
        }
        if (is_array($result)) {
            return Response::json($result);
        }
        return Response::html((string) $result);
    }
}
