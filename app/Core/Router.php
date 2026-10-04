<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\HttpException;
use Closure;

/**
 * Pattern router. Placeholders: {id} matches digits, {token} a 64-char hex token,
 * any other {name} matches one path segment.
 */
final class Router
{
    /** @var list<array{method:string, regex:string, handler:array{class-string,string}|Closure, middleware:list<string>, keys:list<string>}> */
    private array $routes = [];

    /** @var list<array{prefix:string, middleware:list<string>}> */
    private array $groupStack = [];

    /** @var array<string, class-string<Middleware>> */
    private array $aliases = [];

    public function __construct(private readonly Container $container)
    {
    }

    /** @param array<string, class-string<Middleware>> $aliases */
    public function aliasMiddleware(array $aliases): void
    {
        $this->aliases = $aliases;
    }

    /** @param array{class-string,string}|Closure $handler @param list<string> $middleware */
    public function get(string $path, array|Closure $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    /** @param array{class-string,string}|Closure $handler @param list<string> $middleware */
    public function post(string $path, array|Closure $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    /** @param array{class-string,string}|Closure $handler @param list<string> $middleware */
    public function put(string $path, array|Closure $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }

    /** @param array{class-string,string}|Closure $handler @param list<string> $middleware */
    public function delete(string $path, array|Closure $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    /** @param list<string> $middleware @param Closure(self): void $routes */
    public function group(string $prefix, array $middleware, Closure $routes): void
    {
        $this->groupStack[] = ['prefix' => $prefix, 'middleware' => $middleware];
        $routes($this);
        array_pop($this->groupStack);
    }

    /** @param array{class-string,string}|Closure $handler @param list<string> $middleware */
    private function add(string $method, string $path, array|Closure $handler, array $middleware): void
    {
        $prefix = '';
        $groupMiddleware = [];
        foreach ($this->groupStack as $group) {
            $prefix .= $group['prefix'];
            $groupMiddleware = array_merge($groupMiddleware, $group['middleware']);
        }

        $full = '/' . trim($prefix . '/' . trim($path, '/'), '/');
        $keys = [];
        $regex = preg_replace_callback('/\{(\w+)\}/', static function (array $m) use (&$keys): string {
            $keys[] = $m[1];

            return match ($m[1]) {
                'id' => '(\d+)',
                'token' => '([A-Fa-f0-9]{64})',
                default => '([^/]+)',
            };
        }, $full);

        $this->routes[] = [
            'method' => $method,
            'regex' => '#^' . $regex . '$#',
            'handler' => $handler,
            'middleware' => array_merge($groupMiddleware, $middleware),
            'keys' => $keys,
        ];
    }

    /** Whether a GET route (a page) exists for this path, e.g. to send a failed form back to itself. */
    public function hasGetRoute(string $path): bool
    {
        foreach ($this->routes as $route) {
            if ($route['method'] === 'GET' && preg_match($route['regex'], $path) === 1) {
                return true;
            }
        }

        return false;
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->method === 'HEAD' ? 'GET' : $request->method;
        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $matches)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $method) {
                continue;
            }

            $params = array_combine($route['keys'], array_slice($matches, 1)) ?: [];
            $request = $request->withParams($params);

            return $this->runPipeline($request, $route['middleware'], function (Request $req) use ($route, $params): Response {
                return $this->callHandler($route['handler'], $req, $params);
            });
        }

        throw new HttpException($pathMatched ? 405 : 404);
    }

    /**
     * @param list<string> $middleware "alias" or "alias:arg1,arg2"
     * @param Closure(Request): Response $core
     */
    public function runPipeline(Request $request, array $middleware, Closure $core): Response
    {
        $next = $core;
        foreach (array_reverse($middleware) as $definition) {
            [$alias, $argString] = array_pad(explode(':', $definition, 2), 2, '');
            $class = $this->aliases[$alias] ?? $alias;
            $args = $argString === '' ? [] : explode(',', $argString);
            $instance = $this->container->get($class);
            $next = static fn (Request $req): Response => $instance->handle($req, $next, ...$args);
        }

        return $next($request);
    }

    /** @param array{class-string,string}|Closure $handler @param array<string,string> $params */
    private function callHandler(array|Closure $handler, Request $request, array $params): Response
    {
        if ($handler instanceof Closure) {
            return $handler($request, ...array_values($params));
        }

        [$class, $method] = $handler;
        $controller = $this->container->get($class);
        $args = [];
        foreach ($params as $key => $value) {
            $args[] = $key === 'id' ? (int) $value : $value;
        }

        return $controller->{$method}($request, ...$args);
    }
}
