<?php
namespace contentwave\hyperblade;

/**
 * Generic HTML component.
 *
 * It simply echoes the source element.
 * It is useful as a way to run mixins over arbitrary HTML elements.
 *
 * An instance of this component is created by the view compiler for every HTML tag that contains one or more
 * attribute directives.
 *
 * The `tag` attribute sets the tag to be outputted.
 */
class Html extends Component
{
  public function __construct (array $attrs, $content, array $scope)
  {
    parent::__construct ($attrs, $content, $scope);
    $this->tag = $this->attr->tag;
    unset ($this->attr->tag);
  }

  protected function render ()
  {
    $out = array("<$this->tag");
    $out[] = $this->generateAttributes ();
    $out[] = '>';
    $out[] = $this->getContent ();
    $out[] = "</$this->tag>";
    return implode ('', $out);
  }

}