<?php
namespace cwplugins\hyperbladePlugin;

use Blade;

/**
 * Extension to the Blade templating engine.
 */
class Hyperblade
{
  private static $ns = array();

  public static function register ()
  {
    /*
     * Short syntax for invoking a method and outputting its result.
     *
     *   Syntax: @class:method[(args)]
     *
     * Is equivalent to {{ Class::method (args) }}
     * Parenthesis are optional; ex: @a::b instead of @a::b()
     */
    Blade::extend (function ($view) {
      return preg_replace_callback ('/(?<!\w)(\s*)@(\w+)::?(\w+)\s*(?:\((.*)\)|$|\W)/m',
        function ($match) {
          list ($all, $space, $class, $method, $args) = $match;
          $class = ucfirst ($class);
          return "$space<?php echo $class::$method($args) ?>";
        }, $view);
    });

    /*
     * Blade components.
     *
     * Bind prefix to namespace:
     *   Syntax: xmlns:prefix="namespace\ClassName"
     * Can be an attribute of any tag in the template, or it may appear in a comment.
     *
     * Component element:
     *   Syntax: <prefix:method [attr1="", ...]>html markup</prefix:method>
     *
     * Invokes Class::method (array attributes, string html, string indentSpace),
     * where Class is a class previously bound to the prefix via a xml namespace declaration.
     *
     * Attribute directive:
     *   Syntax: <sometag prefix:method="value" prefix:method="(arg1,...)">
     * The first attribute syntax calls Class::method(string indentSpace,string value)
     * The second attribute syntax calls Class::method(string indentSpace,arg1,...)
     */
    Blade::extend (function ($view) {

      // Namespace declarations.

      $view = preg_replace_callback ('/\bxmlns:([\w\-]+)\s*=\s*(["\'])(.*?)\\2/', function ($match) {
        list ($all, $prefix, $quote, $value) = $match;
        self::$ns[$prefix] = $value;
      }, $view);

      // Attribute directives.

      $view = preg_replace_callback ('/(\s+)([\w\-]+):([\w\-]+)\s*=\s*(["\'])(.*?)\\4/', function ($match) {
        list ($all, $space, $prefix, $method, $quote, $value) = $match;
        $class = self::$ns[$prefix];
        if (!isset(self::$ns[$prefix]))
          throw new RuntimeException("Prefix $prefix: is not bound to a namespace.");
        // Convert dash-case to camel-case.
        $method = lcfirst (str_replace (' ', '', ucwords (str_replace ('-', ' ', $method))));
        if (!empty($value)) {
          switch ($value[0]) {
            case '(':
              $value = substr($value, 1, strlen ($value) - 2);
              break;
            default:
              $value = "\"$value\"";
          }
          $value = "\"$space\",$value";
        }
        else $value = "\"$space\"";
        return "<?php echo $class::$method($value) ?>";
      }, $view);

      // Components.

      return preg_replace_callback ('/([ \t]*)<(\w+):([\w\-]+)\s*(.*?)>\s*(.*?)<\/\\2:\\3>/s',
        function ($match) {
          list ($all, $space, $prefix, $tag, $attrs, $content) = $match;
          if (!isset(self::$ns[$prefix]))
            throw new RuntimeException("Prefix $prefix: is not bound to a namespace.");
          $class = self::$ns[$prefix];
          $attrs = preg_replace ('/(?<=["\'])\s+(?=\w)/', ',', $attrs);
          $attrs = preg_replace ('/([\w\-]+)\s*=\s*(["\'])(.*?)\\2/', '\'$1\'=>$2$3$2', $attrs);
          // Convert dash-case to camel-case.
          $method = lcfirst (str_replace (' ', '', ucwords (str_replace ('-', ' ', $tag))));
          return "$space<?php ob_start() ?>$content<?php echo $class::$method(array($attrs),ob_get_clean(),'$space') ?>";
        }, $view);
    });

  }
}