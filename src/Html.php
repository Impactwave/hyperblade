<?php
namespace contentwave\hyperblade;

/**
 * Generic HTMLcomponent.
 * It simply echoes the source element.
 */
class Html extends component
{
  /**
   * @param array $attrs The component tag's attributes.
   * @param string $content The component tag's content.
   * @param array $scope All variables present in the host view's scope.
   */
  public function __construct (array $attrs, $content, array $scope)
  {
    parent::__construct ($attrs, $content, $scope);
  }

  protected function render ()
  {
    $tag = $this->attr->_tag;
    unset ($this->attr->_tag);
    $out = array('<' . $tag);
    foreach ($this->attr->toArray() as $k => $v)
      $out[] = " $k=\"$v\"";
    $out[] = ">\n$this->content\n</$tag>";
    return implode ('', $out);
  }

}