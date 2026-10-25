<?php

declare(strict_types=1);

namespace App\Http;

use App\Exception\HttpException;

final class Router
{
    /** @var array<string, callable(Request): Response> */
    private array $routes = [];

    /**
     * @param callable(Request): Response $handler
     */
    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    /**
     * @param callable(Request): Response $handler
     */
    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function dispatch(Request $request): Response
    {
        $key = $this->key($request->method(), $request->path());

        if (!isset($this->routes[$key])) {
            $apiAvailabilityPrefix = '/api/products/';
            $apiAvailabilitySuffix = '/availability';
            if (
                $request->method() === 'GET'
                && str_starts_with($request->path(), $apiAvailabilityPrefix)
                && str_ends_with($request->path(), $apiAvailabilitySuffix)
                && isset($this->routes[$this->key('GET', '/api/products/{sku}/availability')])
            ) {
                $sku = substr($request->path(), strlen($apiAvailabilityPrefix), -strlen($apiAvailabilitySuffix));
                $query = $request->query();
                $query['sku'] = rawurldecode($sku);

                return ($this->routes[$this->key('GET', '/api/products/{sku}/availability')])(
                    new Request($request->method(), $request->path(), $query, $request->post(), $request->server())
                );
            }

            // Other parameterized routes: one placeholder per path segment, e.g. /api/barcodes/{code} (D-18, ADR-024).
            foreach ($this->routes as $routeKey => $handler) {
                [$method, $pattern] = explode(' ', $routeKey, 2);
                if ($method !== strtoupper($request->method()) || !str_contains($pattern, '{')) {
                    continue;
                }
                $regex = '#^' . preg_replace('#\\\\\{([a-z_]+)\\\\\}#', '(?P<$1>[^/]+)', preg_quote($pattern, '#')) . '$#D';
                if (preg_match($regex, $request->path(), $match) === 1) {
                    $query = $request->query();
                    foreach ($match as $name => $value) {
                        if (is_string($name)) {
                            $query[$name] = rawurldecode($value);
                        }
                    }

                    return $handler(new Request($request->method(), $request->path(), $query, $request->post(), $request->server()));
                }
            }

            throw new HttpException(404, 'Route not found.');
        }

        return ($this->routes[$key])($request);
    }

    /**
     * @param callable(Request): Response $handler
     */
    private function add(string $method, string $path, callable $handler): void
    {
        $this->routes[$this->key($method, $path)] = $handler;
    }

    private function key(string $method, string $path): string
    {
        return strtoupper($method) . ' ' . $path;
    }
}
