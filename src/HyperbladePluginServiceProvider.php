<?php
namespace cwplugins\hyperbladePlugin;

use App\Providers\PackageServiceProvider as ServiceProvider;

class HyperbladePluginServiceProvider extends ServiceProvider
{
  /**
   * Indicates if loading of the provider is deferred.
   *
   * @var bool
   */
  protected $defer = false;

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
    Hyperblade::register ();
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
