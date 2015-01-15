<?php
namespace contentwave\hyperblade;

/**
 * Base class for components.
 * Using this class is not mandatory. You can create your classes for handling components without extending this class.
 * What this class gives you is a set of standard behaviors that make it easier to develop components.
 */
abstract class Component
{
  /**
   * The component's tag name.
   * This should be set on each component class, unless it's not applicable.
   * @var string
   */
  public static $tagName = '';
  /**
   * The component's tag name.
   * This is initially set to `static::$tagName`.
   * It can be dinamically set at runtime to change the way the component is output, for some component types.
   * @var string
   */
  public $tag;
  /**
   * The component's attribute names and values.
   * @var PropertyList
   */
  public $attr;
  /**
   * The component's content block.
   * @var string
   */
  protected $content;
  /**
   * The containing view's factory instance.
   * @var \Illuminate\Contracts\View\Factory
   */
  protected $viewFactory;
  /**
   * The containing view's compiled file path.
   * @var string
   */
  protected $viewPath;
  /**
   * The containing view's data scope.
   * @var PropertyList
   */
  protected $viewScope;
  /**
   * The view data scope for the component instance.
   *
   * When rendering the component's view, this property's data will be merged with `viewScope`, added some utility
   * properties and then published to the component's view.
   *
   * The added properties are:
   * <table>
   *   <tr><td> content <td> contains the component's content block
   *   <tr><td> attr    <td> array containing the component's attributes
   * </table>
   *
   * @var PropertyList
   */
  protected $scope;

  /**
   * Adds the given space to the beginning of each line in the input string, except for the first line.
   * @param string $str
   * @param string $space
   * @return string
   */
  public static function indent ($str, $space)
  {
    return substr (preg_replace ('/^/m', $space, $str), strlen ($space));
  }

  /**
   * Removes the given space from the beginning of each line in the input string.
   * @param string $str
   * @param string $space
   * @return string
   */
  public static function unindent ($str, $space)
  {
    return preg_replace ("/^$space/m", '', $str);
  }

  /**
   * Creates a component instance.
   *
   * This is called by the compiled view.
   *
   * @param array $attrs The component tag's attributes.
   * @param string $content The component tag's content.
   * @param array $scope All variables present in the host view's scope.
   */
  public function __construct (array $attrs, $content, array $scope)
  {
    $this->tag = static::$tagName;
    $this->scope = new PropertyList;
    $this->attr = new PropertyList($attrs);
    $this->attr->class = self::attrToArray ($this->attr->class);
    $this->attr->style = self::attrToArray ($this->attr->style);
    $this->content = $content;
    $this->viewFactory = $scope['__env'];
    $this->viewPath = $scope['__path'];
    $this->viewScope = new PropertyList($scope);
  }

  /**
   * Outputs the current scope's content for debugging purposes and stops execution.
   */
  public function debugScope ()
  {
    $this->scope->app = 'Application instance not shown...';
    $this->scope->__env = 'Factory instance not shown...';
    dd ((object)$this->scope->toArray ());
  }

  /**
   * Outputs the encolsing view scope's content for debugging purposes and stops execution.
   */
  public function debugViewScope ()
  {
    $this->viewScope->app = 'Application instance not shown...';
    $this->viewScope->__env = 'Factory instance not shown...';
    $this->viewScope->__data = '__data instance not shown...';
    dd ((object)$this->viewScope->toArray ());
  }

  /**
   * Allows merging additional data into the scope directly from the view's template.
   * This is used by the '@ config' directive.
   * @param array|\Illuminate\Contracts\Support\Arrayable $data
   */
  public function config ($data)
  {
    $this->scope->extend ($data);
  }

  /**
   * Returns the component's dinamically generated content or, if none is provided by the component subclass, it
   * returns the original transcluded content from the view.
   *
   * This is called by the '@ content' directive.
   *
   * @return string
   */
  public function getContent ()
  {
    return $this->content;
  }

  /**
   * Runs the component and returns the generated output.
   * This is called by the view after instantiating the component.
   * @return string
   */
  public function run ()
  {
    $this->setViewModel ();
    return $this->render ();
  }

  /**
   * Applies the given mixins to the component.
   * This is called by the view after instantiating the component if it has one or more mixins.
   *
   * @param Mixin ...$args One or more mixin instances.
   * @return Component self for chaining.
   */
  public function mixin ()
  {
    $mixins = func_get_args ();
    foreach ($mixins as $mixin)
      $mixin->run ($this);
    return $this;
  }

  /**
   * Adds a class name to the component's class attribute.
   * @param string $className
   */
  public function addCssClass ($className)
  {
    if (array_search ($className, $this->attr->class) === false)
      array_push ($this->attr->class, $className);
  }

  /**
   * Generates a textual representation of all the component's attributes.
   *
   * @return string
   */
  public function generateAttributes ()
  {
    $o = '';
    foreach ($this->attr->toArray () as $k => $v) {
      $v = self::toAttribute ($k, $v);
      if (isset($v))
        $o .= " $v";
    }
    return $o;
  }

  /**
   * Override on subclasses to set data on the scope to be used by the view for rendering.
   * By default, it sets the 'my' variable that can be used to refer nack to the component instance from the view.
   */
  protected function setViewModel ()
  {
    $this->scope->my = $this;
  }

  /**
   * Generates the component's output.
   * Override to provide custom content generation.
   * @return string
   */
  abstract protected function render ();

  /**
   * Generates a textual representation of an attribute.
   *
   * <p>Array attribute values are converted to space-separated value string lists.
   * > A useful use case for an array attribute is the `class` attribute.
   *
   * Object attribute values generate either:
   * - a space-separated list of keys who's corresponding value is truthy;
   * - a semicolon-separated list of key:value elements if at least one value is a string.
   *
   * Boolean values will generate a valueless attribute if true, otherwise no attribute is output.
   *
   * @param string $name
   * @param mixed $value
   * @return string|null If null, the attribute should not be output by the caller (ex: by <code>generateAttributes()</code>).
   */
  public static function toAttribute ($name, $value)
  {
    switch (gettype ($value)) {
      case 'string':
        return e ($value); // Calls contentwave\hyperblade\e
      case 'boolean':
        return $value ? $name : '';
      case 'integer':
      case 'double':
        return "$name=$value";
      case 'array':
        if (empty($value)) return null; //see doc-comment.
        $value = e ($value); // Calls contentwave\hyperblade\e
        return "$name=\"$value\"";
      default:
        throw new \InvalidArgumentException("Invalid value of type " . gettype ($value) . " for attribute $name ");
    }
  }

  /**
   * Converts an attribute value into an array.
   *
   * This is useful for attributes that always store their values as arrays (ex: class, style).
   *
   * @param mixed $value
   * @return array
   */
  public static function attrToArray ($value)
  {
    if (is_null ($value)) return [];
    if (is_array ($value)) return $value;
    if (is_object ($value)) {
      $at = [];
      foreach ($value as $k => $v)
        if ($v) $at[] = $k;
      return $at;
    }
    return explode (' ', strval ($value));
  }
}