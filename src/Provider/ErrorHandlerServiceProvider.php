<?php declare(strict_types=1);

namespace Gmo\Web\Provider;

use Bolt\Common\Ini;
use Gmo\Common\Console\Application as ConsoleApplication;
use Gmo\Web\EventListener\DebugHandlersListener;
use Pimple\Container;
use Pimple\ServiceProviderInterface;
use Psr\Log\LoggerInterface;
use Silex\Api\BootableProviderInterface;
use Silex\Application;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Debug\BufferingLogger;
use Symfony\Component\Debug\DebugClassLoader;
use Symfony\Component\Debug\ErrorHandler;
use Symfony\Component\Debug\ExceptionHandler;

/**
 * Error/Exception handler configuration.
 *
 * @author Carson Full <carsonfull@gmail.com>
 */
class ErrorHandlerServiceProvider implements ServiceProviderInterface, BootableProviderInterface
{
    private const CLI = PHP_SAPI === 'cli';

    /**
     * {@inheritdoc}
     */
    public function register(Container $app)
    {
        // Thrown errors in an integer bit field of E_* constants
        $app['error_handler.throw_at'] = function ($app) {
            return $app['debug'] ? E_ALL : 0;
        };

        // Logged errors in an integer bit field of E_* constants.
        $app['error_handler.log_at'] = E_ALL;

        $app['error_handler.logger'] = function ($app) {
            return $app['logger'];
        };

        // Enable handlers for web and cli, but not test runners since they have their own.
        $app['error_handler.enabled'] =
        $app['exception_handler.enabled'] =
            !self::CLI || !defined('PHPUNIT_COMPOSER_INSTALL');

        $app['error_handler'] = function () {
            return new ErrorHandler(new BufferingLogger());
        };

        // Exception Handler is registered when this service is invoked if enabled.
        // This is only for bootstrapping. The real one gets set on kernel request / console command event.
        $app['exception_handler.early'] = $app->factory(function ($app) {
            static $handler;

            if ($handler !== null) {
                return $handler;
            }

            if ($app['error_handler.enabled']) {
                // Register the ExceptionHandler on the ErrorHandler as well.
                $handler = ExceptionHandler::register($app['debug'], $app['charset'], $app['code.file_link_format']);
            } else {
                $handler = new ExceptionHandler($app['debug'], $app['charset'], $app['code.file_link_format']);
            }

            // The ExceptionHandler by default renders HTML.
            // If we are on CLI, change it to render for CLI.
            if (self::CLI) {
                $handler->setHandler(function ($e) use ($app) {
                    $console = new ConsoleApplication();
                    if (isset($app['root_path'])) {
                        $console->setProjectDirectory($app['root_path']);
                    }
                    $verbosity = ($app['exception_handler.cli_print_trace'] ?? $app['debug'])
                        ? OutputInterface::VERBOSITY_VERBOSE : OutputInterface::VERBOSITY_NORMAL;
                    $console->renderException($e, new ConsoleOutput($verbosity));
                    ob_clean();
                });
            }

            return $handler;
        });

        // Listener to set the exception handler from HttpKernel or Console App.
        $app['debug.handlers_listener'] = function ($app) {
            return new DebugHandlersListener($app['exception_handler.cli_print_trace'] ?? $app['debug']);
        };

        // Added by WebProviderServiceProvider
        if (!isset($app['code.file_link_format'])) {
            $app['code.file_link_format'] = null;
        }

        // Move this SP to be first in the provider list, so
        // error handlers are configured before anything else.
        if ($app instanceof Application) {
            (function () {
                $sp = array_pop($this->providers);
                array_unshift($this->providers, $sp);
            })->call($app);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function boot(Application $app)
    {
        if ($app['debug']) {
            DebugClassLoader::enable();
        }

        if ($errorHandlerEnabled = $app['error_handler.enabled']) {
            // Report all errors since the error handler has
            // its own logging / throwing errors logic.
            error_reporting(E_ALL);
            // Disable built-in PHP error displaying logic. Errors are:
            // 1. Logged to the logger.
            // 2. Converted to an exception and thrown to the user that way.
            // Both of these are regardless of HTML or CLI output.
            // This makes the built-in display_errors redundant.
            Ini::set('display_errors', false);

            $handler = $app['error_handler'];
            ErrorHandler::register($handler);
            // Set throw at value based on config value during 2nd phase.
            // (Has to be after register() as that resets the level.)
            $handler->throwAt($app['error_handler.throw_at'], true);
        }

        if ($app['exception_handler.enabled']) {
            $app['exception_handler.early']; // Invoke to register
        }

        if ($errorHandlerEnabled) {
            $this->configureLogger($handler, $app['error_handler.logger'], $app['error_handler.log_at']);
        }

        // Can be subscribed regardless of enabled, because it won't do anything
        // if there is no error handler or exception handler registered.
        $app['dispatcher']->addSubscriber($app['debug.handlers_listener']);
    }

    /**
     * Configure the error handler to log types given to the logger given and to ignore all types not specified.
     *
     * It's important that the BufferingLogger is completely replaced for all error types with either a real logger
     * or null, otherwise a memory leak could occur.
     *
     * @param ErrorHandler    $handler
     * @param LoggerInterface $logger
     * @param array|int       $loggedAt An array map of E_* to LogLevel::* or an integer bit field of E_* constants
     */
    private function configureLogger(ErrorHandler $handler, LoggerInterface $logger, $loggedAt)
    {
        // Set real logger for the levels specified.
        $handler->setDefaultLogger($logger, $loggedAt);

        // For all the levels not logged, tell the handler not to log them.
        $notLoggedLevels = [];
        $defaults = [
            E_DEPRECATED        => null,
            E_USER_DEPRECATED   => null,
            E_NOTICE            => null,
            E_USER_NOTICE       => null,
            E_STRICT            => null,
            E_WARNING           => null,
            E_USER_WARNING      => null,
            E_COMPILE_WARNING   => null,
            E_CORE_WARNING      => null,
            E_USER_ERROR        => null,
            E_RECOVERABLE_ERROR => null,
            E_COMPILE_ERROR     => null,
            E_PARSE             => null,
            E_ERROR             => null,
            E_CORE_ERROR        => null,
        ];
        if (is_array($loggedAt)) {
            $notLoggedLevels = array_diff_key($defaults, $loggedAt);
        } else {
            if ($loggedAt === 0) { // shortcut for no logging.
                $notLoggedLevels = $defaults;
            } elseif ($loggedAt === E_ALL) { // shortcut for all logging.
                // Do nothing. Leave notLoggedLevels empty.
            } else {
                foreach ($defaults as $type => $logger) {
                    if (!($loggedAt & $type)) {
                        $notLoggedLevels[$type] = null;
                    }
                }
            }
        }
        if ($notLoggedLevels) {
            $handler->setLoggers($notLoggedLevels);
        }
    }
}
