<?php
namespace contentwave\hyperblade;

use RuntimeException, Illuminate\Support\Str;

/**
 * Each view has a compilation context. Recursive compilations in the same view reuse the same context.
 */
class CompilationContext
{
  /**
   * Prefix reserved for internal use by the compiler.
   */
  const RESERVED_PREFIX = '_h';
  /**
   * A marker that signals the beginning of a macro tag.
   * This value must be escaped for RegExp.
   * @var string
   */
  public $MACRO_PREFIX = '@@';
  /**
   * The separator between a prefix and a tag name. Ex: @@form-field
   * This value must be escaped for RegExp.
   * @var string
   */
  public $MACRO_ALIAS_DELIMITER = '\\.';
  /**
   * The marker for the beginning of a macro's content block.
   * This value must be escaped for RegExp.
   * @var string
   */
  public $MACRO_BEGIN = ':';
  /**
   * The marker for the end of a macro's content block.
   * You can use two %s placeholders for the classAlias (including the trailing delimiter) and tag, respectively.
   * This value must be escaped for RegExp.
   * @var string
   */
  public $MACRO_END = '%send%s';
  /**
   * A map of prefixes to namespaces or of alias to classes.
   * @var array
   */
  public $ns = array(
    self::RESERVED_PREFIX => 'contentwave\hyperblade'
  );
  /**
   * Compilation nesting level.
   * @var int
   */
  public $nestingLevel = 0;
  /**
   * A list of PHP statements to be prepended to the view.
   * This is only output on the first nesting level.
   * @var array
   */
  public $prolog;

  function __construct ()
  {
    $this->prolog = array(
      'use contentwave\hyperblade as ' . self::RESERVED_PREFIX . ';'
    );
  }


  /**
   * Does additional transformations to the compiled view after each compilation step.
   * @param string $compiledView
   * @return string
   */
  public function postProcess ($compiledView)
  {
    if (count ($this->prolog)) {
      // Prepend the generated namespace declarations to the compiled view.
      $prepend = implode ("\n", $this->prolog) . "\n";
      $this->prolog = array();
      $compiledView = "<?php\n$prepend?>$compiledView";
    }

    return $compiledView;
  }

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
    if (isset($this->ns[$key]) && $this->ns[$key] != $namespace)
      throw new RuntimeException("Different declarations for the same $aliasName alias/prefix are not allowed.");
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
   * @param string $prefix Dash-cased prefix. Must NOT start with dashes, or they will be lost.
   * @param string $name
   * @return string
   */
  public function getClass ($prefix, $name)
  {
    $namespace = $this->getNamespace ($prefix);
    $class = ucfirst (Str::camel ($name));
    if (!class_exists ("$namespace\\$class"))
      throw new RuntimeException("Class '$namespace\\$class' was not found for <$prefix:$name>.");
    // Perform dash-case to camel-case conversion. Do NOT use Str::camel !
    $prefix = lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $prefix))));
    return "$prefix\\$class";
  }

  /**
   * Gets the PHP fully aqualified class name for the given xml `prefix:name` tag or attribute.
   * @param string $prefix
   * @param string $name
   * @return string
   */
  public function getFQClass ($prefix, $name)
  {
    $namespace = $this->getNamespace ($prefix);
    $class = ucfirst (Str::camel ($name));
    if (!class_exists ("$namespace\\$class"))
      throw new RuntimeException("Class '$namespace\\$class' was not found for <$prefix:$name>.");
    return "$namespace\\$class";
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
