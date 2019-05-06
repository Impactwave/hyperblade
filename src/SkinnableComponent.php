<?php
namespace contentwave\hyperblade;

use RuntimeException;

/**
 * Base class for components that render a template.
 */
class SkinnableComponent extends Component
{
  /**
   * Override this to set the view template for your custom component.
   * That can be done statically for the class or dynamically for each instance.
   * @var string
   */
  protected $templateName;

  /**
   * Renders the component's template.
   * @return string
   */
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
