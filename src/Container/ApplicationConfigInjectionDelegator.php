<?php
/**
 * @see       https://github.com/zendframework/zend-expressive for the canonical source repository
 * @copyright Copyright (c) 2018 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   https://github.com/zendframework/zend-expressive/blob/master/LICENSE.md New BSD License
 */

namespace Zend\Expressive\Container;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use ReflectionProperty;
use SplPriorityQueue;
use Zend\Expressive\Application;
use Zend\Expressive\Exception\InvalidArgumentException;
use Zend\Expressive\Exception\MissingDependencyException;
use Zend\Expressive\Router\Middleware\DispatchMiddleware;
use Zend\Expressive\Router\Middleware\RouteMiddleware;
use Zend\Expressive\Router\RouterInterface;

class ApplicationConfigInjectionDelegator
{
    /**
     * Decorate an Application instance by injecting routes and/or middleware
     * from configuration.
     *
     * @param string $serviceName
     * @return Application
     * @throws Exception\InvalidServiceException if the $callback produces
     *     something other than an `Application` instance, as the delegator cannot
     *     proceed with its operations.
     */
    public function __invoke(ContainerInterface $container, $serviceName, callable $callback)
    {
        $application = $callback();
        if (! $application instanceof Application) {
            throw new Exception\InvalidServiceException(sprintf(
                'Delegator factory %s cannot operate on a %s; please map it only to the %s service',
                __CLASS__,
                is_object($application) ? get_class($application) . ' instance' : gettype($application),
                Application::class
            ));
        }

        if (! $container->has('config')) {
            return $application;
        }

        $config = $container->get('config');
        if (! isset($config['routes']) && ! isset($config['middleware_pipeline'])) {
            return $application;
        }

        self::injectPipelineFromConfig($application, $config);
        self::injectRoutesFromConfig($application, $config);

        return $application;
    }

    /**
     * Inject a middleware pipeline from the middleware_pipeline configuration.
     *
     * Inspects the configuration provided to determine if a middleware pipeline
     * exists to inject in the application.
     *
     * If no pipeline is defined, but routes *are*, then the method will inject
     * the routing and dispatch middleware.
     *
     * Use the following configuration format:
     *
     * <code>
     * return [
     *     'middleware_pipeline' => [
     *         // An array of middleware to register with the pipeline.
     *         // entries to register prior to routing/dispatching...
     *         // - entry for \Zend\Expressive\Router\Middleware\RouteMiddleware::class
     *         // - entry for \Zend\Expressive\Router\Middleware\DispatchMiddleware::class
     *         // entries to register after routing/dispatching...
     *     ],
     * ];
     * </code>
     *
     * Each item in the middleware_pipeline array must be of the following
     * specification:
     *
     * <code>
     * [
     *     // required:
     *     'middleware' => 'Name of middleware service, or a callable',
     *     // optional:
     *     'path'  => '/path/to/match',
     *     'priority' => 1, // integer
     * ]
     * </code>
     *
     * Note that the `path` element can only be a literal.
     *
     * `priority` is used to shape the order in which middleware is piped to the
     * application. Values are integers, with high values having higher priority
     * (piped earlier), and low/negative values having lower priority (piped last).
     * Default priority if none is specified is 1. Middleware with the same
     * priority are piped in the order in which they appear.
     *
     * Middleware piped may be either callables or service names.
     *
     * Additionally, you can specify an array of callables or service names as
     * the `middleware` value of a specification. Internally, this will create
     * a `Zend\Stratigility\MiddlewarePipe` instance, with the middleware
     * specified piped in the order provided.
     *
     * @return void
     */
    public static function injectPipelineFromConfig(Application $application, array $config)
    {
        if (empty($config['middleware_pipeline'])) {
            if (! isset($config['routes']) || ! is_array($config['routes'])) {
                return;
            }

            self::pipeRoutingMiddleware($application);
            self::pipeDispatchMiddleware($application);
            return;
        }

        // Create a priority queue from the specifications
        $queue = array_reduce(
            array_map(self::createCollectionMapper(), $config['middleware_pipeline']),
            self::createPriorityQueueReducer(),
            new SplPriorityQueue()
        );

        foreach ($queue as $spec) {
            $path = isset($spec['path']) ? $spec['path'] : '/';
            $application->pipe($path, $spec['middleware']);
        }
    }

    /**
     * Inject routes from configuration.
     *
     * Introspects the provided configuration for routes to inject in the
     * application instance.
     *
     * The following configuration structure can be used to define routes:
     *
     * <code>
     * return [
     *     'routes' => [
     *         [
     *             'path' => '/path/to/match',
     *             'middleware' => 'Middleware Service Name or Callable',
     *             'allowed_methods' => ['GET', 'POST', 'PATCH'],
     *             'options' => [
     *                 'stuff' => 'to',
     *                 'pass'  => 'to',
     *                 'the'   => 'underlying router',
     *             ],
     *         ],
     *         // etc.
     *     ],
     * ];
     * </code>
     *
     * Each route MUST have a path and middleware key at the minimum.
     *
     * The "allowed_methods" key may be omitted, can be either an array or the
     * value of the Zend\Expressive\Router\Route::HTTP_METHOD_ANY constant; any
     * valid HTTP method token is allowed, which means you can specify custom HTTP
     * methods as well.
     *
     * The "options" key may also be omitted, and its interpretation will be
     * dependent on the underlying router used.
     *
     * @return void
     * @throws InvalidArgumentException
     */
    public static function injectRoutesFromConfig(Application $application, array $config)
    {
        if (! isset($config['routes']) || ! is_array($config['routes'])) {
            return;
        }

        foreach ($config['routes'] as $spec) {
            if (! isset($spec['path']) || ! isset($spec['middleware'])) {
                continue;
            }

            $methods = null;
            if (isset($spec['allowed_methods'])) {
                $methods = $spec['allowed_methods'];
                if (! is_array($methods)) {
                    throw new InvalidArgumentException(sprintf(
                        'Allowed HTTP methods for a route must be in form of an array; received "%s"',
                        gettype($methods)
                    ));
                }
            }

            $name  = isset($spec['name']) ? $spec['name'] : null;
            $route = $application->route(
                $spec['path'],
                $spec['middleware'],
                $methods,
                $name
            );

            if (isset($spec['options'])) {
                $options = $spec['options'];
                if (! is_array($options)) {
                    throw new InvalidArgumentException(sprintf(
                        'Route options must be an array; received "%s"',
                        gettype($options)
                    ));
                }

                $route->setOptions($options);
            }
        }
    }

    /**
     * Create the collection mapping function.
     *
     * Returns a callable with the following signature:
     *
     * <code>
     * function (array|string $item) : array
     * </code>
     *
     * If the 'middleware' value is missing, or not viable as middleware, it
     * raises an exception, to ensure the pipeline is built correctly.
     *
     * @return callable
     * @throws InvalidArgumentException
     */
    private static function createCollectionMapper()
    {
        $appMiddleware = [
            Application::ROUTING_MIDDLEWARE,
            Application::DISPATCH_MIDDLEWARE,
        ];

        return function ($item) use ($appMiddleware) {
            if (in_array($item, $appMiddleware, true)) {
                return ['middleware' => $item];
            }

            if (! is_array($item) || ! array_key_exists('middleware', $item)) {
                throw new InvalidArgumentException(sprintf(
                    'Invalid pipeline specification received; must be an array'
                    . ' containing a middleware key; received %s',
                    is_object($item) ? get_class($item) : gettype($item)
                ));
            }

            return $item;
        };
    }

    /**
     * Create reducer function that will reduce an array to a priority queue.
     *
     * Creates and returns a function with the signature:
     *
     * <code>
     * function (SplQueue $queue, array $item) : SplQueue
     * </code>
     *
     * The function is useful to reduce an array of pipeline middleware to a
     * priority queue.
     *
     * @return callable
     */
    private static function createPriorityQueueReducer()
    {
        // $serial is used to ensure that items of the same priority are enqueued
        // in the order in which they are inserted.
        $serial = PHP_INT_MAX;
        return function ($queue, $item) use (&$serial) {
            $priority = isset($item['priority']) && is_int($item['priority'])
                ? $item['priority']
                : 1;
            $queue->insert($item, [$priority, $serial]);
            $serial -= 1;
            return $queue;
        };
    }

    /**
     * Pipe routing middleware into the application.
     *
     * Attempts to pull both the router and response interface from the application
     * container, falling back to reflection of the application instance
     * when unable to find either one.
     *
     * @return void
     */
    private static function pipeRoutingMiddleware(Application $application)
    {
        $container = $application->getContainer();
        if ($container->has(RouteMiddleware::class)) {
            $application->pipe(RouteMiddleware::class);
            return;
        }

        $application->pipe(new RouteMiddleware(
            self::getRouter($application, $container),
            self::getResponsePrototype($application, $container)
        ));
    }

    /**
     * Pipe dispatch middleware into the application.
     *
     * If the DispatchMiddleware is present in the application's container, it
     * pipes that service. Otherwise, instantiates it directly and pipes it.
     *
     * @return void
     * @throws MissingDependencyException if the RouterInterface service is not
     *     found when the RouteMiddleware is not present.
     */
    private static function pipeDispatchMiddleware(Application $application)
    {
        $container = $application->getContainer();
        $application->pipe(
            $container->has(DispatchMiddleware::class)
            ? $container->get(DispatchMiddleware::class)
            : new DispatchMiddleware()
        );
    }

    /**
     * Get the router.
     *
     * If not available in the container, uses reflection to pull it from the
     * application.
     *
     * @return RouterInterface
     */
    private static function getRouter(Application $application, ContainerInterface $container)
    {
        if (! $container->has(RouterInterface::class)) {
            $r = new ReflectionProperty($application, 'router');
            $r->setAccessible(true);
            return $r->getValue($application);
        }

        return $container->get(RouterInterface::class);
    }

    /**
     * Get the response prototype.
     *
     * If not available in the container, uses reflection to pull it from the
     * application.
     *
     * If in the container, fetches it. If the value is callable, uses it as
     * a factory to generate and return the response.
     *
     * @return ResponseInterface
     */
    private static function getResponsePrototype(Application $application, ContainerInterface $container)
    {
        if (! $container->has(ResponseInterface::class)) {
            $r = new ReflectionProperty($application, 'responsePrototype');
            $r->setAccessible(true);
            return $r->getValue($application);
        }

        $response = $container->get(ResponseInterface::class);
        return is_callable($response) ? $response() : $response;
    }
}
