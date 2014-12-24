<?php
namespace contentwave\hyperblade;

/**
 * @see HyperbladeCompiler
 */
class Blade extends Facade
{
  /**
   * Get the registered name of the component.
   * @return string
   */
  protected static function getFacadeAccessor ()
  {
    return static::$app['view']->getEngineResolver ()->resolve ('hyperblade')->getCompiler ();
  }

}
