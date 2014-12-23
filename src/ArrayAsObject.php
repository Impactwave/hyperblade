<?php
namespace contentwave\hyperblade;

use Countable;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Allow access to array data as object properties.
 * Unset properties will return null.
 */
class ArrayAsObject implements Arrayable, Countable
{

  /**
   * The original array data that will be exposed as object properties.
   *
   * @var array
   */
  protected $items;

  function __construct (array $items = null)
  {
    $this->items = $items ?: array();
  }

  function __get ($name)
  {
    return isset($this->items[$name]) ? $this->items[$name] : null;
  }

  function __set ($name, $value)
  {
    $this->items[$name] = $value;
  }

  function __isset ($name)
  {
    return isset($this->items[$name]);
  }

  function __unset ($name)
  {
    unset($this->items[$name]);
  }

  function __toString ()
  {
    return json_encode ($this->items);
  }


  public function toArray ()
  {
    return $this->items;
  }

  public function count ()
  {
    return count ($this->items);
  }


}