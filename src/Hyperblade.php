<?php
namespace contentwave\hyperblade;

use Blade, RuntimeException, Illuminate\Support\Str;

/**
 * Each view has a compilation context. Recursive compilations in the same view reuse the same context.
 */
class CompilationContext
{
  public $MACRO_PREFIX = '@@';
  public $MACRO_ALIAS_DELIMITER = ':';
  public $MACRO_BODY_DELIMITER = ':';

  public $ns = array();
  public $alias = array();

  public function registerClass ($alias, $class)
  {
    $key = strtolower (Str::camel ($alias));
    if (isset($this->ns[$key]))
      throw new RuntimeException("Macro class alias '$alias' conflicts with an equivalent XML prefix.");
    if (isset($this->alias[$key]))
      throw new RuntimeException("Multiple declarations for the same macro class alias '$alias' are not allowed.");
    $this->alias[$key] = $class;
  }

  public function registerNamespace ($prefix, $namespace)
  {
    if (!preg_match ('/^[\w\\\]*$/', $namespace))
      throw new RuntimeException("xmlns:$prefix=\"$namespace\" declares an invalid PHP namespace.");
    $key = strtolower (Str::camel ($prefix));
    if (isset($this->alias['']) && strtolower ($alias = $this->alias['']) == $key)
      throw new RuntimeException("XML prefix '$prefix' conflicts with the default macro class name '$alias'.");
    if (isset($this->alias[$key]))
      throw new RuntimeException("XML prefix '$prefix' conflicts with an equivalent macro class alias.");
    if (isset($this->ns[$key]))
      throw new RuntimeException("Multiple declarations for the same XML prefix '$prefix' are not allowed.");
    $this->ns[$key] = $namespace;
  }

  public function getClass ($alias)
  {
    $key = $this->getClassAlias ($alias);
    return $this->alias[$key];
  }

  public function getClassAlias ($alias)
  {
    $key = strtolower (Str::camel ($alias));
    if (!isset($this->alias[$key])) {
      $alias = $alias ? "Alias '$alias'" : "The default alias";
      throw new RuntimeException("$alias is not bound to a class.");
    }
    return $key;
  }

  public function getNamespace ($prefix)
  {
    $key = strtolower (Str::camel ($prefix));
    if (!isset($this->ns[$key]))
      throw new RuntimeException("XML prefix '$prefix' is not bound to a namespace.");
    return $this->ns[$key];
  }

}

/**
 * An extension to the Blade templating engine.
 */
class Hyperblade
{
  public static function register ()
  {
    $compile = function ($view, CompilationContext $ctx) use (&$compile) {

      $prolog = array();

      /*
       * Defines a class alias for use with macros.
       *
       *   Syntax: @use(className[ as alias])
       *
       * `className` is a fully qualified class name; ex: my\namespace\myClass or just myClass.
       * If no alias is specified, the default nameless alias will be set.
       */

      $view = preg_replace_callback ('/(?<!\w)(\s*)@use\s*\(\s*(\S+)\s*(?:as\s*(\w+)\s*)?\)\s*$/m',
        function ($match) use ($ctx, &$prolog) {
          $match[] = ''; // the default namespace.
          list ($all, $space, $class, $alias) = $match;
          $ctx->registerClass ($alias, $class);
          if ($alias)
            $prolog[] = "use $class as $alias;";
          return $space;
        }, $view);

      /*
       * Namespace declarations bind a XML prefix to a PHP namespace.
       * They can be an attribute of any tag in the template, but they also may appear in comments (or even strings,
       * so beware!).
       *
       * Syntax:
       *
       *   <sometag ... xmlns:prefix="namespace" ...>
       *
       * Ex:
       *
       *   <div xmlns:form="ns1\ns2\forms">
       *
       * `prefix` can be dash-cased.
       */
      //
      $view = preg_replace_callback ('/\bxmlns:([\w\-]+)\s*=\s*(["\'])(.*?)\\2\s*/',
        function ($match) use ($ctx, &$prolog) {
          list ($all, $prefix, $quote, $namespace) = $match;
          $ctx->registerNamespace ($prefix, $namespace);
          $prolog[] = "use $namespace as $prefix;";
          return ''; //suppress attribute
        }, $view);

      // Prepend the namespace declarations to the compiled view.

      if (!empty($prolog))
        $view = "<?php\n" . implode ("\n", $prolog) . "\n?>" . $view;

      /*
       * Simple macros invoke a method and output its result.
       *
       *   Syntax: @@[alias:]method [(args)]
       *
       * Generated code (simplified):
       *
       *   class::method (args)
       *
       * Arguments and parenthesis are optional; ex: @@a:b instead of @@a:b()
       * `alias` must have been previously bound to a class using @use.
       * If an alias is not specified, the default nameless alias is assumed.
       */

      $view =
        preg_replace_callback ('/(?<!\w)(\s*)' . $ctx->MACRO_PREFIX . '((?:([\w\\\\]+)' . $ctx->MACRO_ALIAS_DELIMITER .
          ')?(\w+))(?:\s*\((.*?)\))?(?!\s*' . $ctx->MACRO_BODY_DELIMITER . ')/s',
          function ($match) use ($ctx) {
            array_push ($match, ''); // allow $args to be undefined.
            list ($all, $space, $fullName, $alias, $method, $args) = $match;
            $class = $ctx->getClassAlias ($alias) ?: $ctx->getClass ('');
            return "$space<?php echo $class::$method($args) ?> "; //trailing space is needed for formatting.
          }, $view);

      /*
       * Block macros invoke a method and output its result, but they also support a block of content and source code
       * indentation.
       *
       * Syntax:
       *
       *   @@[alias:]method [(args)]:
       *     html markup
       *   @@end[alias:]method
       *
       * Generated code (simplified):
       *
       *   class::method (indentSpace,html,args...)
       *
       * Arguments and parenthesis are optional; ex: @@a:b instead of @@a:b()
       * `alias` must have been previously bound to a class using @use.
       * If an alias is not specified, the default alias/namespace is assumed.
       * indentSpace is a white space string corresponding to the indentation level of this block.
       */

      $view = preg_replace_callback ('/(?<!\w)(\s*)^([ \t]*)' . $ctx->MACRO_PREFIX . '((?:([\w\\\]+)' .
        $ctx->MACRO_ALIAS_DELIMITER . ')?(\w+))(?:\s*\((.*?)\))?\s*' . $ctx->MACRO_BODY_DELIMITER . '\s*(.*?)' .
        $ctx->MACRO_PREFIX .
        'end\3/sm',
        function ($match) use (&$compile, $ctx) {
          list ($all, $space, $indentSpace, $fullName, $alias, $method, $args, $content) = $match;
          if (!isset($ctx->alias[$alias])) {
            $alias = $alias ? "Alias '$alias'" : "The default alias";
            throw new RuntimeException("$alias is not bound to a class.");
          }
          $class = $ctx->alias[$alias];
          if ($args != '')
            $args = ",$args";
          $content = trim ($compile ($content));
          return "$space<?php ob_start() ?>$content<?php echo $class::$method('$indentSpace',ob_get_clean()$args) ?>";
        }, $view);

      /*
       * Hyperblade mixins transform attributes into something else.
       *
       * Syntax:
       *
       *   <tag prefix:method="value" prefix:method="(arg1,...)">
       *
       * The first attribute syntax calls Class::method(string indentSpace,string value)
       * The second attribute syntax calls Class::method(string indentSpace,arg1,...)
       * The method name should be specified in dash case;; it will be converted to a camel cased method name.
       * `prefix` can be dash-cased.
       */

      $view = preg_replace_callback ('/(\s+)([\w\-]+):([\w\-]+)\s*=\s*(["\'])(.*?)\\4/', function ($match) use ($ctx) {
        list ($all, $space, $prefix, $method, $quote, $value) = $match;
        if (!isset($ctx->ns[$prefix]))
          throw new RuntimeException("XML prefix '$prefix' is not bound to a class.");
        $class = $ctx->ns[$prefix];
        $method = Str::camel ($method);
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


      /*
       * Hyperblade components transform tags and their contents into something else.
       *
       * Syntax:
       *
       *   <prefix:class [attr1="", ...]>html markup</prefix:class>
       *
       * Ex:
       *
       *   <form:super-field name="field1">
       *     <input type="text">
       *   </form:super-field>
       *
       *   will be compiled to (simplified example):
       *
       *   (new namespace\class(array attributes, string html, string indentSpace))->render();
       *
       * Creates a new instance of a namespace\class where:
       *   - `namespace` is a previously registered namespace for the given `prefix`.
       *   - `class` is a class name written in dash-case.
       *
       * If you want automatic support for converting attributes to class properties and other advanced functionality,
       * your class should subclass `contentwave\hyperblade\Component`.
       * `prefix` can be dash-cased.
       */
      $view = preg_replace_callback ('/
        (^\s*)?                 # capture white space from the beginning of the line
        <([\w\-]+):([\w\-]+)    # match and capture <prefix:tag
        \s*(.*?)                # capture attributes
        >\s*                    # match > and remaining white space
        (                       # capture tag content
          (?:                   # loop begin
            (?R)                # either recurse
          |                     # or
            (?! <\/\\2:\\3>).   # consume one char until the closing tag is reached
          )*                    # repeat
        )
        <\/\\2:\\3>             # consume the closing tag
      /smx',
        function ($match) use (&$compile, $ctx) {
          list ($all, $space, $prefix, $tag, $attrs, $content) = $match;
          $class = Str::camel ($prefix) . '\\' . ucfirst (Str::camel ($tag));
          $attrs = preg_replace ('/(?<=["\'])\s+(?=\w)/', ',', $attrs);
          $attrs = preg_replace ('/([\w\-]+)\s*=\s*(["\'])(.*?)\\2/', '\'$1\'=>$2$3$2', $attrs);
          $content = $compile ($content, $ctx);
          return "$space<?php ob_start() ?>$content<?php echo (new $class(array($attrs),ob_get_clean(),'$space'))->render(); ?>";
        }, $view);

      return $view;
    };

    Blade::extend (function ($view) use (&$compile) {
      return $compile ($view, new CompilationContext);
    });

  }
}