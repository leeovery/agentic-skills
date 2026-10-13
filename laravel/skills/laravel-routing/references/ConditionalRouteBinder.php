<?php

declare(strict_types=1);

namespace App\Http\Routing;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;

class ConditionalRouteBinder
{
    /** @var list<array{list<string>, Closure(string, Route): Model}> */
    private array $resolvers = [];

    public static function bind(string $parameter): self
    {
        $binder = new self;

        RouteFacade::bind($parameter, $binder->resolve(...));

        return $binder;
    }

    /**
     * @param  string|list<string>  $routeNames  full names or Str::is() patterns: 'api-v1.order.*'
     * @param  Closure(string, Route): Model  $resolver  looks up by the route's binding column and fails with a 404
     */
    public function forRoute(string|array $routeNames, Closure $resolver): self
    {
        $this->resolvers[] = [(array) $routeNames, $resolver];

        return $this;
    }

    /**
     * Returning the raw value on an unmatched route is deliberate: implicit binding runs next and
     * resolves it as usual, honouring the binding column, withTrashed() and parent scoping.
     */
    private function resolve(string $value, Route $route): Model|string
    {
        foreach ($this->resolvers as [$routeNames, $resolver]) {
            if (Str::is($routeNames, (string) $route->getName())) {
                return $resolver($value, $route);
            }
        }

        return $value;
    }
}
