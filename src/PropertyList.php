<?php
namespace contentwave\hyperblade;

use Countable;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Allow access to array data as object properties.
 *
 * <p>Features:
 * - Unset properties will return null.
 * - Property reads will return a reference to the stored array at the specified key, which allows you to mutate properties
 * having array values.
 * - The original array passed to the constructor will not be affected by mutations on instances of this class.
 * - Implements some useful operations (ex: extend).
 */
class PropertyList implements Arrayable, Countable
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

  function &__get ($name)
  {
    static $_null = null;
    if (isset($this->items[$name])) return $this->items[$name]; else return $_null;
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

  public function keys ()
  {
    return array_keys ($this->items);
  }

  public function values ()
  {
    return array_values ($this->items);
  }

  /**
   * Merges in the given data.
   * @param array|Arrayable $data Note: PropertyList values are also supported (because they are Arrayable).
   */
  public function extend ($data)
  {
    $this->items = array_merge ($this->items, $data instanceof Arrayable ? $data->toArray () : $data);
  }

  /**
   * Applies the specified default values to the corresponding properties that are unset.
   * @param array|Arrayable $data Note: PropertyList values are also supported (because they are Arrayable).
   */
  public function defaults ($data)
  {
    $this->items += $data instanceof Arrayable ? $data->toArray () : $data;
  }

}