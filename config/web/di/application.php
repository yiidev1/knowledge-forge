<?php

declare(strict_types=1);

use App\Environment;
use App\Shared\Web\Middleware\BasePathMiddleware;
use App\Shared\Web\Middleware\CorrelationIdMiddleware;
use App\Shared\Web\Middleware\SecurityHeadersMiddleware;
use App\Web\NotFound\NotFoundHandler;
use Yiisoft\Csrf\CsrfTokenMiddleware;
use Yiisoft\Definitions\DynamicReference;
use Yiisoft\Definitions\Reference;
use Yiisoft\ErrorHandler\Middleware\ErrorCatcher;
use Yiisoft\Input\Http\HydratorAttributeParametersResolver;
use Yiisoft\Input\Http\RequestInputParametersResolver;
use Yiisoft\Middleware\Dispatcher\CompositeParametersResolver;
use Yiisoft\Middleware\Dispatcher\MiddlewareDispatcher;
use Yiisoft\Middleware\Dispatcher\ParametersResolverInterface;
use Yiisoft\RequestProvider\RequestCatcherMiddleware;
use Yiisoft\Router\Middleware\Router;
use Yiisoft\Session\SessionMiddleware;
use Yiisoft\Yii\Http\Application;

/** @var array $params */

return [
    Application::class => [
        '__construct()' => [
            'dispatcher' => DynamicReference::to([
                'class' => MiddlewareDispatcher::class,
                'withMiddlewares()' => [
                    [
                        // ErrorCatcher first so even an error response passes back out through the
                        // header/correlation middleware below it.
                        ErrorCatcher::class,
                        SecurityHeadersMiddleware::class,
                        CorrelationIdMiddleware::class,
                        // Before everything that reads the path or generates a URL — which, once the
                        // application is mounted on a prefix, is the session cookie, the error pages
                        // and every action below.
                        BasePathMiddleware::class,
                        SessionMiddleware::class,
                        CsrfTokenMiddleware::class,
                        RequestCatcherMiddleware::class,
                        Router::class,
                    ],
                ],
            ]),
            'fallbackHandler' => Reference::to(NotFoundHandler::class),
        ],
    ],

    // Read straight from the environment rather than through a parameter object: the mount point is a
    // deployment fact of the same kind as the debug flag, and it has to be known before routing.
    BasePathMiddleware::class => [
        '__construct()' => [
            'basePath' => Environment::appBasePath(),
        ],
    ],

    ParametersResolverInterface::class => [
        'class' => CompositeParametersResolver::class,
        '__construct()' => [
            Reference::to(HydratorAttributeParametersResolver::class),
            Reference::to(RequestInputParametersResolver::class),
        ],
    ],
];
