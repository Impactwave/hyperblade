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
   * Override this to set the view template for your custom component.
   * @var string
   */
  protected $templateName;

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

  public function debugScope ()
  {
    $this->scope->app = 'Application instance not shown...';
    $this->scope->__env = 'Factory instance not shown...';
    dd ((object)$this->scope->toArray ());
  }

  public function debugViewScope ()
  {
    $this->viewScope->app = 'Application instance not shown...';
    $this->viewScope->__env = 'Factory instance not shown...';
    $this->viewScope->__data = '__data instance not shown...';
    dd ((object)$this->viewScope->toArray ());
  }

  /**
   * @param array $attrs The component tag's attributes.
   * @param string $content The component tag's content.
   * @param array $scope All variables present in the host view's scope.
   */
  public function __construct (array $attrs, $content, array $scope)
  {
    $this->scope = new PropertyList;
    $this->attr = new PropertyList($attrs);
    $this->content = $content;
    $this->viewFactory = $scope['__env'];
    $this->viewPath = $scope['__path'];
    $this->viewScope = new PropertyList($scope);
  }

  public function config ($data)
  {
    $this->scope->extend ($data);
  }

  public function getContent ()
  {
    return $this->content;
  }

  public function run ()
  {
    $this->setViewModel ();
    return $this->render ();
  }

  protected function setViewModel ()
  {
    $this->scope->my = $this;
  }

  protected function render ()
  {
    if (!$this->templateName)
      throw new RuntimeException("No template is set for component " . get_called_class ());
    $result = $this->viewFactory->make (
      $this->templateName,
      $this->scope,
      array_except ($this->viewScope->toArray (), array('__data', '__path'))
    )->render ();
    return $result;
  }

}