<?php
namespace contentwave\hyperblade;

use Blade, RuntimeException;

/**
 * Extension to the Blade templating engine.
 */
class Hyperblade
{
  public static $MACRO_PREFIX = '@@';
  public static $ALIAS_DELIMITER = ':';

  private static $ns = array();

  public static function register ()
  {
    /*
     * Defines a class alias for use with macros or components/mixins.
     * This is an alternate syntax to a xmlns:alias="className" declaration.
     *
     *   Syntax: @use(className[ as alias])
     *
     * 'className' is a fully qualified class name; ex: my\namespace\myClass or just myClass.
     * If no alias is specified, the default alias/namespace will be set.
     */
    Blade::extend (function ($view) {
      return preg_replace_callback ('/(?<!\w)(\s*)@use\s*\(\s*(\S+)\s*(?:as\s*(\w+)\s*)?\)/m',
        function ($match) {
          $match[] = ''; // the default namespace.
          list ($all, $space, $class, $alias) = $match;
          self::$ns[$alias] = $class;
          return $space;
        }, $view);
    });

    /*
     * Short macro syntax; invokes a method and outputs its result.
     *
     *   Syntax: @@[alias:]method [(args)]
     *
     * Generated code:
     *
     *   {{ class::method (args) }}
     *
     * Parenthesis are optional; ex: @@a::b instead of @@a::b()
     * 'alias' must have been set previously using @use or a xmlns declaration.
     * If an alias is not specified, the default alias/namespace is assumed.
     */
    Blade::extend (function ($view) {
      return preg_replace_callback ('/(?<!\w)(\s*)' . self::$MACRO_PREFIX . '((?:([\w\\\\]+)' . self::$ALIAS_DELIMITER .
        ')?(\w+))\s*(?:\((.*?)\))?\s*(:)?\s*$(.*?' . self::$MACRO_PREFIX . 'end\2)?/ms',
        function ($match) {
          array_push ($match, 0); // allow $args. $colon and $close to be undefined.
          array_push ($match, 0);
          array_push ($match, 0);
          list ($all, $space, $fullName, $alias, $method, $args, $colon, $close) = $match;
          if ($colon) return $all; // Skip macro blocks.
          if ($close)
            throw new RuntimeException ("Missing colon after macro block start @@$fullName($args)");
          if (!isset(self::$ns[$alias])) {
            $alias = $alias ? "Alias '$alias'" : "The default alias";
            throw new RuntimeException("$alias is not bound to a class.");
          }
          $class = self::$ns[$alias];
          return "$space<?php echo $class::$method($args) ?>";
        }, $view);
    });

    /*
     * Hyperblade macros.
     *
     * Syntax:
     *
     *   @@[alias:]method [(args)]:
     *     html markup
     *   @@end[alias:]method
     *
     * Generated code:
     *
     *   {{ class::method (indentSpace,html,args...) }}
     *
     * Args (and parenthesis) are optional.
     * 'alias' must have been set previously using @use or a xmlns declaration.
     * If an alias is not specified, the default alias/namespace is assumed.
     * indentSpace is a white space string corresponding to the indentation level of this block.
     */
    Blade::extend (function ($view) {
      return preg_replace_callback ('/(?<!\w)(\s*)^([ \t]*)' . self::$MACRO_PREFIX . '((?:([\w\\\]+)' .
        self::$ALIAS_DELIMITER . ')?(\w+))\s*(?:\((.*?)\))?\s*:\s*(.*?)' . self::$MACRO_PREFIX . 'end\3/sm',
        function ($match) {
          list ($all, $space, $indentSpace, $fullName, $alias, $method, $args, $content) = $match;
          if (!isset(self::$ns[$alias])) {
            $alias = $alias ? "Alias '$alias'" : "The default alias";
            throw new RuntimeException("$alias is not bound to a class.");
          }
          $class = self::$ns[$alias];
          if ($args != '')
            $args = ",$args";
          $content = trim ($content);
          return "$space<?php ob_start() ?>$content<?php echo $class::$method('$indentSpace',ob_get_clean()$args) ?>";
        }, $view);
    });

    /*
     * Hyperblade components and mixins.
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
     * Mixins:
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

      // Mixins

      $view = preg_replace_callback ('/(\s+)([\w\-]+):([\w\-]+)\s*=\s*(["\'])(.*?)\\4/', function ($match) {
        list ($all, $space, $prefix, $method, $quote, $value) = $match;
        if (!isset(self::$ns[$prefix]))
          throw new RuntimeException("XML prefix '$prefix' is not bound to a class.");
        $class = self::$ns[$prefix];
        // Convert dash-case to camel-case.
        $method = lcfirst (str_replace (' ', '', ucwords (str_replace ('-', ' ', $method))));
        if (!empty($value)) {
          switch ($value[0]) {
            case '(':
              $value = substr ($value, 1, strlen ($value) - 2);
              break;
            default:
              $value = "\"$value\"";
          }
          $value = "\"$space\",$value";
        } else $value = "\"$space\"";
        return "<?php echo $class::$method($value) ?>";
      }, $view);

      // Components

      return preg_replace_callback ('/(?<!\w)(\s*)^([ \t]*)<(\w+):([\w\-]+)\s*(.*?)>\s*(.*?)<\/\\3:\\4>/sm',
        function ($match) {
          list ($all, $space, $indentSpace, $prefix, $tag, $attrs, $content) = $match;
          if (!isset(self::$ns[$prefix]))
            throw new RuntimeException("XML prefix '$prefix' is not bound to a class.");
          $class = self::$ns[$prefix];
          $attrs = preg_replace ('/(?<=["\'])\s+(?=\w)/', ',', $attrs);
          $attrs = preg_replace ('/([\w\-]+)\s*=\s*(["\'])(.*?)\\2/', '\'$1\'=>$2$3$2', $attrs);
          // Convert dash-case to camel-case.
          $method = lcfirst (str_replace (' ', '', ucwords (str_replace ('-', ' ', $tag))));
          $content = trim ($content);
          return "$space<?php ob_start() ?>$content<?php echo $class::$method('$indentSpace',ob_get_clean(),array($attrs)) ?>";
        }, $view);
    });

  }
}