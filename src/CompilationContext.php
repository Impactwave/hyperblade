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
  public $ns = array();
  /**
   * Compilation nesting level.
   * @var int
   */
  public $nestingLevel = 0;
  /**
   * Each view template can be split into many compilable blocks if it contains embedded PHP source code blocks.
   * This property tracks the ordinal index of current block being compiled.
   * @var int
   */
  public $blockIndex = 0;
  /**
   * The source template being compiled.
   * @var string
   */
  public $templatePath;
  /**
   * A list of PHP statements to be prepended to the view.
   * This is only output on the first nesting level.
   * @var array
   */
  public $prolog = array(
    'use contentwave\hyperblade as _h;'
  );
  /**
   * Sometimes Blade may try to compile the same template multiple times, and if it
   * repeats the same template twice in sequence it may cause errors because a context
   * will be reused when it should not.
   * @var array of string.
   */
  private $cachedBlocks = array();
  /**
   * List that corresponds to $cachedBlocks.
   * @var array of string.
   */
  private $cachedCompiledBlocks = array();


  /**
   * @param string $templatePath
   */
  public function __construct ($templatePath)
  {
    $this->templatePath = $templatePath;
  }

  public function cachedIndex ($source) {
    echo "\n\nSEARCH\n\n$source\n\nON\n\n";
    print_r($this->cachedBlocks);
    print_r($this->cachedCompiledBlocks);
    $z = array_search($source, $this->cachedBlocks);
    echo "GOT = $z\n\n";
    return $z;
  }

  public function getCachedCompiledBlock ($index) {
    return $this->cachedCompiledBlocks[$index];
  }

  public function cache ($source, $compiled) {
    $this->cachedBlocks[] = $source;
    $this->cachedCompiledBlocks[] = $compiled;
  }

  /**
   * Does additional transformations to the compiled view after each compilation step
   * @param string $compiledView
   * @return string
   */
  public function postProcess ($compiledView)
  {
    // Prepend the generated namespace declarations to the compiled view.
    $prepend = implode ("\n", $this->prolog) . "\n";
    $this->prolog = array();

    echo $this->blockIndex;
    if ($this->blockIndex) {
      if (count ($this->prolog)) {
        echo "##### INSERT ######\n$prepend\n";
        $p = strpos ($compiledView, '?>');
        $compiledView = substr ($compiledView, 0, $p) . $prepend . substr ($compiledView, $p);
      }
    } else {
      echo "##### PREPEND ######\n$prepend\n";
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
    echo "*****REGISTER $alias=$namespace ********\n";
    if (!preg_match ('/^[\w\\\]*$/', $namespace))
      throw new RuntimeException("'$namespace' is an invalid PHP class/namespace.");
    $key = strtolower (Str::camel ($alias));
    $aliasName = $alias ? "'$alias' alias" : 'default alias';
    if (isset($this->ns['']) && strtolower ($ns = $this->ns['']) == $key)
      throw new RuntimeException("The $aliasName conflicts with the default macro class name '$ns'.");
    if (isset($this->ns[$key])) {echo "#######ERROR#######";exit;}
      //throw new RuntimeException("Multiple declarations for the $aliasName are not allowed.");
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
      throw new RuntimeException("No class was found for <$prefix:$name> on the '$namespace' namespace.");
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
