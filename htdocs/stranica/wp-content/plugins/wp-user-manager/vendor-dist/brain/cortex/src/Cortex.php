<?php

/*
 * This file is part of the Cortex package.
 *
 * (c) Giuseppe Mazzapica <giuseppe.mazzapica@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace WPUM\Brain;

use WPUM\Brain\Cortex\Group\GroupCollection;
use WPUM\Brain\Cortex\Group\GroupCollectionInterface;
use WPUM\Brain\Cortex\Route\PriorityRouteCollection;
use WPUM\Brain\Cortex\Route\RouteCollectionInterface;
use WPUM\Brain\Cortex\Router\ResultHandler;
use WPUM\Brain\Cortex\Router\ResultHandlerInterface;
use WPUM\Brain\Cortex\Router\Router;
use WPUM\Brain\Cortex\Router\RouterInterface;
use WPUM\Brain\Cortex\Uri\PsrUri;
use WPUM\Brain\Cortex\Uri\WordPressUri;
use WPUM\Brain\Cortex\Uri\UriInterface;
use WPUM\Psr\Http\Message\RequestInterface;
/**
 * @author  Giuseppe Mazzapica <giuseppe.mazzapica@gmail.com>
 * @license http://opensource.org/licenses/MIT MIT
 * @package Cortex
 */
class Cortex
{
    /**
     * @var bool
     */
    private static $booted = \false;
    /**
     * @var bool
     */
    private static $late = \false;
    /**
     * @param  \Psr\Http\Message\RequestInterface $request
     * @return bool
     * @throws \Exception
     */
    public static function boot(RequestInterface $request = null)
    {
        try {
            if (self::$booted) {
                return \false;
            }
            if (did_action('parse_request')) {
                throw new \BadMethodCallException(\sprintf('%s must be called before "do_parse_request".', __METHOD__));
            }
            self::$booted = add_filter('do_parse_request', function ($do, \WP $wp) use($request) {
                self::$late = \true;
                try {
                    $instance = new static();
                    $do = $instance->doBoot($wp, $do, $request);
                    unset($instance);
                    if (!$do) {
                        $wp->query_posts();
                    }
                    return $do;
                } catch (\Exception $e) {
                    if (\defined('WP_DEBUG') && WP_DEBUG) {
                        throw $e;
                    }
                    do_action('cortex.fail', $e);
                    return $do;
                }
            }, 100, 2);
        } catch (\Exception $e) {
            if (\defined('WP_DEBUG') && WP_DEBUG) {
                throw $e;
            }
            do_action('cortex.fail', $e);
        }
        return \true;
    }
    /**
     * @return bool
     */
    public static function late()
    {
        return self::$late;
    }
    /**
     * @param  \WP                                     $wp
     * @param  bool                                    $do
     * @param  \Psr\Http\Message\RequestInterface|null $request
     * @return bool
     */
    private function doBoot(\WP $wp, $do, RequestInterface $request = null)
    {
        $uri = $this->factoryUri($request);
        $method = $this->getMethod($request);
        $routes = $this->factoryRoutes($uri, $method);
        $groups = $this->factoryGroups();
        $router = $this->factoryRouter($routes, $groups);
        $handler = $this->factoryHandler();
        add_filter('cortex.match.done', function ($result) {
            remove_all_filters('cortex.routes');
            remove_all_filters('cortex.groups');
            return $result;
        });
        $do = $handler->handle($router->match($uri, $method), $wp, $do);
        \is_bool($do) or $do = \true;
        return $do;
    }
    /**
     * @param  string        $name
     * @param  string|null   $abstract
     * @param  callable|null $default
     * @return object
     */
    private function factoryByHook($name, $abstract = null, callable $default = null)
    {
        $thing = apply_filters("cortex.{$name}.instance", null);
        if (\is_string($abstract) && (\class_exists($abstract) || \interface_exists($abstract)) && (!\is_object($thing) || !\is_subclass_of($thing, $abstract, \true))) {
            $thing = \is_callable($default) ? $default() : null;
        }
        if (!\is_object($thing)) {
            throw new \RuntimeException(\sprintf('Impossible to factory "%s".', $name));
        }
        return $thing;
    }
    /**
     * @param  \Psr\Http\Message\RequestInterface $request
     * @return \Brain\Cortex\Uri\UriInterface
     */
    private function factoryUri(RequestInterface $request = null)
    {
        $psrUri = \is_null($request) ? null : $request->getUri();
        /** @var UriInterface $uri */
        $uri = $this->factoryByHook('uri', UriInterface::class, function () use($psrUri) {
            \is_null($psrUri) and $psrUri = new PsrUri();
            return new WordPressUri($psrUri);
        });
        return $uri;
    }
    /**
     * @param  \Psr\Http\Message\RequestInterface|null $request
     * @return string
     */
    private function getMethod(RequestInterface $request = null)
    {
        if ($request) {
            return $request->getMethod();
        }
        return empty($_SERVER['REQUEST_METHOD']) ? 'GET' : \strtoupper($_SERVER['REQUEST_METHOD']);
    }
    /**
     * @return \Brain\Cortex\Group\GroupCollectionInterface
     */
    private function factoryGroups()
    {
        /** @var \Brain\Cortex\Group\GroupCollectionInterface $groups */
        $groups = $this->factoryByHook('group-collection', GroupCollectionInterface::class, function () {
            return new GroupCollection();
        });
        do_action('cortex.groups', $groups);
        return $groups;
    }
    /**
     * @param  \Brain\Cortex\Uri\UriInterface $uri
     * @param  string                         $method
     * @return \Brain\Cortex\Route\RouteCollectionInterface
     */
    private function factoryRoutes(UriInterface $uri, $method)
    {
        /** @var \Brain\Cortex\Route\RouteCollectionInterface $routes */
        $routes = $this->factoryByHook('route-collection', RouteCollectionInterface::class, function () {
            return new PriorityRouteCollection();
        });
        do_action('cortex.routes', $routes, $uri, $method);
        return $routes;
    }
    /**
     * @param  \Brain\Cortex\Route\RouteCollectionInterface $routes
     * @param  \Brain\Cortex\Group\GroupCollectionInterface $groups
     * @return \Brain\Cortex\Router\RouterInterface
     */
    private function factoryRouter(RouteCollectionInterface $routes, GroupCollectionInterface $groups)
    {
        /** @var \Brain\Cortex\Router\RouterInterface $router */
        $router = $this->factoryByHook('router', RouterInterface::class, function () use($routes, $groups) {
            return new Router($routes, $groups);
        });
        return $router;
    }
    /**
     * @return \Brain\Cortex\Router\ResultHandlerInterface
     */
    private function factoryHandler()
    {
        /** @var ResultHandlerInterface $handler */
        $handler = $this->factoryByHook('result-handler', ResultHandlerInterface::class, function () {
            return new ResultHandler();
        });
        return $handler;
    }
}
