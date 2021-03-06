<?php declare(strict_types=1);

namespace Gmo\Web\Provider;

use Pimple\Container;
use Pimple\ServiceProviderInterface;
use ProxyManager\Autoloader\Autoloader;
use ProxyManager\Configuration;
use ProxyManager\Factory\LazyLoadingValueHolderFactory;
use ProxyManager\FileLocator\FileLocator;
use ProxyManager\GeneratorStrategy\FileWriterGeneratorStrategy;
use ProxyManager\Inflector\ClassNameInflector;
use ProxyManager\Proxy\LazyLoadingInterface;
use Symfony\Component\Filesystem\Filesystem;
use Twig;
use Webmozart\PathUtil\Path;

/**
 * Service Provider for ocramius/proxy-manager.
 *
 * @author Carson Full <carsonfull@gmail.com>
 */
class ProxyServiceProvider implements ServiceProviderInterface
{
    public function register(Container $app)
    {
        $app['twig.lazy'] = function ($app) {
            return $app['lazy']('twig', Twig\Environment::class);
        };

        $app['proxy.target_dir'] = function ($app) {
            if (!isset($app['root_path'])) {
                return sys_get_temp_dir();
            }

            return Path::join($app['root_path'], 'var/proxies');
        };

        $app['proxy.namespace'] = Configuration::DEFAULT_PROXY_NAMESPACE;

        /**
         * Shortcut to create a lazy proxy to a service.
         *
         * Example:
         *
         *     $app['url_generator.lazy'] = $app->share(function ($app) {
         *         return $app['lazy']('url_generator', UrlGeneratorInterface::class);
         *     });
         *
         * @param string $service The container service name holding the real service.
         * @param string $class   The class or interface name of the service.
         */
        $app['lazy'] = $app->protect(function ($service, $class) use ($app) {
            /** @var LazyLoadingValueHolderFactory $factory */
            $factory = $app['proxy.lazy_factory'];

            $initializer = function (
                &$wrappedObject,
                LazyLoadingInterface $proxy,
                $method,
                array $parameters,
                &$initializer
            ) use ($app, $service) {
                $initializer = null; // disable initialization

                $wrappedObject = $app[$service]; // initialize real object

                return true; // confirm that initialization occurred correctly
            };

            return $factory->createProxy($class, $initializer);
        });

        $app['proxy.lazy_factory'] = function ($app) {
            $app['proxy.init'];

            return new LazyLoadingValueHolderFactory($app['proxy.config']);
        };

        $app['proxy.init'] = function ($app) {
            /** @var Configuration $config */
            $config = $app['proxy.config'];

            spl_autoload_register($config->getProxyAutoloader());
        };

        $app['proxy.config'] = function ($app) {
            $config = new Configuration();

            $config->setProxiesTargetDir($app['proxy.target_dir']);
            $config->setProxiesNamespace($app['proxy.namespace']);
            $config->setClassNameInflector($app['proxy.class_name_inflector']);
            $config->setProxyAutoloader($app['proxy.autoloader']);
            $config->setGeneratorStrategy($app['proxy.generator_strategy']);

            return $config;
        };

        $app['proxy.autoloader'] = function ($app) {
            return new Autoloader($app['proxy.file_locator'], $app['proxy.class_name_inflector']);
        };

        $app['proxy.class_name_inflector'] = function ($app) {
            return new ClassNameInflector($app['proxy.namespace']);
        };

        $app['proxy.file_locator'] = function ($app) {
            $dir = $app['proxy.target_dir'];

            if (!file_exists($dir)) {
                (new Filesystem())->mkdir($dir);
            }

            return new FileLocator($dir);
        };

        $app['proxy.generator_strategy'] = function ($app) {
            return new FileWriterGeneratorStrategy($app['proxy.file_locator']);
        };
    }
}
