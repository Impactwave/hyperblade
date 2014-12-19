<?php
namespace contentwave\hyperblade;

/**
 * Generic HTMLcomponent.
 * It simply echoes the source element.
 */
class Html
{
  private $attrs;
  private $html;  //content
  private $space; //indent

  public function __construct ($attrs, $html, $space)
  {
    $this->attrs = $attrs;
    $this->html = $html;
    $this->space = $space;
  }

  public function render() {
    return $this->space.$this->html;
  }

}