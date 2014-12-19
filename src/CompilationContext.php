<?php
namespace contentwave\hyperblade;

use RuntimeException, Illuminate\Support\Str;

/**
 * Each view has a compilation context. Recursive compilations in the same view reuse the same context.
 */
class CompilationContext
{
  /**
   * A marker that signals the beginning of a macro tag.
   * @var string
   */
  public $MACRO_PREFIX = '@@';
  /**
   * The separator between a prefix and a tag name.
   * @var string
   */
  public $MACRO_ALIAS_DELIMITER = ':';
  /**
   * The marker for the end of a macro's content.
   * You can use two %s placeholders for the prefix and tag, respectively.
   * @var string
   */
  public $MACRO_END = '@@%send%s';

  public $ns = array(
    'html' => 'contentwave\hyperblade'
  );

  /**
   * Registers a namespace for a given alias/prefix.
   * @param string $alias
   * @param string $namespace
   */
  public function registerNamespace ($alias, $namespace)
  {
    if (!preg_match ('/^[\w\\\]*$/', $namespace))
      throw new RuntimeException("'$namespace' is an invalid PHP class/namespace.");
    $key = strtolower (Str::camel ($alias));
    $aliasName = $alias ? "'$alias' alias" : 'default alias';
    if (isset($this->ns['']) && strtolower ($ns = $this->ns['']) == $key)
      throw new RuntimeException("The $aliasName conflicts with the default macro class name '$ns'.");
    if (isset($this->ns[$key]))
      throw new RuntimeException("Multiple declarations for the $aliasName are not allowed.");
    $this->ns[$key] = $namespace;
  }

  /**
   * Gets and validates a case-insensitive and dash-insensitive alias key that can be used to access the registered
   * namespaces array.
   * @param string $alias
   * @return string
   */
  public function getNormalizedAlias ($alias)
  {
    $key = strtolower (str_replace ('-', '', $alias));
    if (!isset($this->ns[$key])) {
      $alias = $alias ? "Alias '$alias'" : "The default alias";
      throw new RuntimeException("$alias is not bound to a class/namespace.");
    }
    return $key;
  }

  /**
   * Gets the namespace bound to the given alias.
   * @param string $alias
   * @return string
   */
  public function getNamespace ($alias)
  {
    $key = $this->getNormalizedAlias ($alias);
    if (!isset($this->ns[$key]))
      throw new RuntimeException("Alias '$alias' is not bound to a namespace.");
    return $this->ns[$key];
  }

  /**
   * Gets the PHP class name for the given xml `prefix:name` tag or attribute.
   * @param string $prefix
   * @param string $name
   * @return string
   */
  public function getClass ($prefix, $name)
  {
    $namespace = $this->getNamespace ($prefix);
    $class = ucfirst (Str::camel ($name));
    if (!class_exists ("$namespace\\$class"))
      throw new RuntimeException("No class was found for <$prefix:$name> on the '$namespace' namespace.");
    return Str::camel ($prefix) . "\\$class";
  }

  /**
   * Gets the prefix that sould be used for macro calls.
   * @param string alias
   * @return string
   */
  public function getNormalizedPrefix ($alias)
  {
    $ns = $this->getNamespace ($alias);
    $e = explode ('\\', $ns);
    $last = array_pop ($e);
    $camel = Str::camel ($alias);
    return ctype_upper ($last[0]) ? ucfirst ($camel) : $camel;
  }

}
