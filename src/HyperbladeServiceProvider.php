<?php
namespace contentwave\hyperblade;

use App\Providers\PackageServiceProvider as ServiceProvider;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\Foundation\AliasLoader;
use Illuminate\View\Factory;

class HyperbladeServiceProvider extends ServiceProvider
{
  /**
   * Indicates if loading of the provider is deferred.
   *
   * @var bool
   */
  protected $defer = false;
  /**
   * @var EngineResolver
   */
  private $resolver;

  /*  public function __construct (EngineResolver $resolver) {
      $this->resolver = $resolver;
    }*/

  /**
   * Bootstrap the application events.
   *
   * @return void
   */
  public function boot ()
  {
  }

  /**
   * Register the service provider.
   *
   * @return void
   */
  public function register ()
  {
    $app = $this->app;

    AliasLoader::getInstance ()->alias ("Hyperblade", 'contentwave\hyperblade\HyperbladeCompiler');

    // The Compiler engine requires an instance of the CompilerInterface, which in
    // this case will be the Hyperblade compiler, so we'll first create the compiler
    // instance to pass into the engine so it can compile the views properly.
    $app->singleton ('hyperblade.compiler', function ($app) {
      $cache = $app['config']['view.compiled'];

      return new HyperbladeCompiler($app['files'], $cache, $app->config['app.debug']);
    });

    /** @var EngineResolver $resolver */
    $resolver = $app['view.engine.resolver'];
    $resolver->register ('hyperblade', function () use ($app) {
      return new CompilerEngine($app['hyperblade.compiler'], $app['files']);
    });

    /** @var Factory $factory */
    $factory = $app['view'];
    $factory->addExtension ('hyper.php', 'hyperblade');
  }

  /**
   * Get the services provided by the provider.
   *
   * @return array
   */
  public function provides ()
  {
    return array();
  }

}
