<?php
namespace contentwave\hyperblade;

use RuntimeException;

/**
 * Base class for components.
 * Using this class is not mandatory. You can create your classes for handling components without extending this class.
 * What this class gives you is a set of standard behaviors that make it easier to develop components.
 */
class Component
{
  protected $attrs;
  protected $html;  //content
  protected $space; //indent
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
   * @var array
   */
  protected $viewScope;
  /**
   * The view data scope for the component instance.
   * When rendering the component's view, this property's data will be merged with `viewScope` and added a
   * `_content property` (containing the component's content block) and published to the component's view.
   * @var array
   */
  protected $scope = array();

  /**
   * Override this to set the view template for your custom component.
   * @var string
   */
  protected $templateName;

  /**
   * Adds the given space to the beginning of each line in the input string.
   * @param string $str
   * @param string $space
   * @return string
   */
  public static function indent ($str, $space)
  {
    return preg_replace ('/^/m', $space, $str);
  }

  public static function argToStr ($v)
  {
    if (is_string ($v)) return "'" . strreplace ("'", "\\'", $v) . "'";
    if (is_numeric ($v)) return $v;
    if (is_bool ($v)) return $v ? 'true' : 'false';
    if (is_null ($v)) return 'null';
    throw new RuntimeException ("Component argument type is not supported: " . gettype ($v));
  }

  public function debugScope ()
  {
    $this->scope['app'] = 'Application instance not shown...';
    $this->scope['__env'] = 'Factory instance not shown...';
    dd ($this->scope);
  }

  public function __construct (array $attrs, $html, array $env, $space)
  {
    $this->attrs = $attrs;
    $this->html = $html;
    $this->space = $space;
    $this->viewFactory = $env['__env'];
    $this->viewPath = $env['__path'];
    $this->viewScope = $env['__data'];
  }

  public function render ()
  {
    if (!$this->templateName)
      throw new RuntimeException("No template is set for component " . get_called_class ());
    $data = $this->scope;
    $data ['_content'] = $this->html;
    $result = $this->viewFactory->make (
      $this->templateName,
      $data,
      array_except ($this->viewScope, array('__data', '__path'))
    )->render ();
    return $this->space . $result;
  }

}