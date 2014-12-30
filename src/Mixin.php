<?php
namespace contentwave\hyperblade;

/**
 * Mixins are lightweight behaviors that can be applied to a component instance.
 *
 * <p>They are defined in markup as directive attributes with the syntax `prefix:attr=value`.
 */
abstract class Mixin {

  protected $value;

  function __construct ($value)
  {
    $this->value = $value;
  }


  abstract public function run (Component $component);
}