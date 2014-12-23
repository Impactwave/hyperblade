<?php
namespace contentwave\hyperblade;

use Blade, RuntimeException, Illuminate\Support\Str;

/**
 * Adds the given space to the beginning of each line in the input string, except for the first line.
 * Then it outputs the resulting string.
 * @param string $str
 * @param string $space
 */
function out ($str, $space)
{
  echo substr (preg_replace ('/^/m', $space, $str), strlen ($space));
}

/**
 * An extension to the Blade templating engine.
 */
class Hyperblade
{
  public static function register ()
  {
    $compile = function ($view, CompilationContext $ctx) use (&$compile) {

      ++$ctx->nestingLevel;

      /*
       * @use directive.
       * Imports a class or namespace into the current view.
       *
       *   Syntax: @use(FQN[ as alias])
       *
       *   Ex: @use (my\neat\Util as util)
       *
       * `FQN` (Fully Qualified Name) is a fully qualified class name or a namespace; it is case insensitive and it can
       * be dash-cased (it will be converted to camel case internally).
       * ex: `my\namespace`, `my\namespace\myClass` or just `myClass`.
       * If no alias is specified, the default nameless alias will be set.
       */

      $view = preg_replace_callback ('/(?<!\w)(\s*)@use\s*\(\s*(\S+)\s*(?:as\s*([\w\-]+)\s*)?\)\s*$/m',
        function ($match) use ($ctx) {
          $match[] = ''; // the default namespace.
          list ($all, $space, $FQN, $alias) = $match;
          $ctx->registerNamespace ($alias, $FQN);
          if ($alias) {
            $alias = $ctx->getNormalizedPrefix ($alias);
            $ctx->prolog[] = "use $FQN as $alias;";
          }
          return $space;
        }, $view);

      /*
       * Namespace declarations bind a XML prefix to a PHP namespace. They are quivalent to @use directives.
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
       *   is equivalent to:
       *
       *   @use (ns1\ns2\forms as form)
       *
       * `prefix` can be dash-cased and it is case insensitive.
       */
      //
      $view = preg_replace_callback ('/\bxmlns:([\w\-]+)\s*=\s*(["\'])(.*?)\\2\s*/',
        function ($match) use ($ctx) {
          list ($all, $prefix, $quote, $namespace) = $match;
          $ctx->registerNamespace ($prefix, $namespace);
          $prefix = Str::camel ($prefix);
          $ctx->prolog[] = "use $namespace as $prefix;";
          return ''; //suppress attribute
        }, $view);

      // Prepend the generated namespace declarations to the compiled view.

      if ($ctx->nestingLevel == 1)
        $view = "<?php\n" . implode ("\n", $ctx->prolog) . "\n?>" . $view;

      /*
       * Simple macros invoke a static method on a class and output its result.
       *
       *   Syntax: @@[classAlias.]method [(args)]
       *
       * Generated code (simplified):
       *
       *   classAlias::method (args)
       *
       * Arguments and parenthesis are optional; ex: @@a-b instead of @@a-b()
       * `classAlias` must have been previously bound to a class using @use.
       * If an alias is not specified, the default nameless alias is assumed.
       */

      $allends = sprintf ($ctx->MACRO_END, '(?:[\w\-]+' . $ctx->MACRO_ALIAS_DELIMITER . ')?', '(?:[\w\-]+)');

      $view = preg_replace_callback ('/
        (?<! \w)(\s*)                                         # capture leading white space
        ' . $ctx->MACRO_PREFIX . '                            # macro begins
        (?! ' . $allends . ')                                 # do not match end tags
        ([\w\-]+' . $ctx->MACRO_ALIAS_DELIMITER . ')?         # capture optional alias
        ([\w\-]+)                                             # capture macro method
        (?= \( | \s )                                         # force capture full word
        (?:
          \s*\((.*?)\)                                        # capture optional arguments block
        )?
        (?! \s*' . $ctx->MACRO_BEGIN . ')                     # do not match macros with a content block
        (?! \s*\( )                                           # do not match macros with a content block
        /sx',
        function ($match) use ($ctx, $view, $allends) {
          //echo "SIMPLE";
          //dd ($match, $allends);
          array_push ($match, ''); // allow $args to be undefined.
          list ($all, $space, $alias, $method, $args) = $match;
          if ($alias)
            $alias = substr ($alias, 0, -strlen ($ctx->MACRO_ALIAS_DELIMITER));
          $method = Str::camel ($method);
          $class = $ctx->getNormalizedPrefix ($alias);

          $c = substr_count ($args, ',');
          $realClass = $ctx->getNamespace ($alias);
          $info = new \ReflectionMethod($realClass, $method);
          $r = $info->getNumberOfRequiredParameters ();
          if ($c < $r)
            throw new RuntimeException ("Error on macro call: $all\n\nThe corresponding method $realClass::$method must have at least $r arguments, this call generates $c arguments.\nPlease check the method/call signatures.");

          if (!$class) $class = $ctx->getNamespace ('');
          return "$space<?php echo $class::$method($args) ?> "; //trailing space is needed for formatting.
        }, $view);

      /*
       * Block macros invoke a static method on a class and output its result, and they also support a block of content
       * and source code indentation.
       *
       * Syntax:
       *
       *   @@[classAlias.]method [(args)]:
       *     html markup
       *   @@end[alias.]method
       *
       * Generated code (simplified):
       *
       *   classAlias::method (indentSpace,html,args...)
       *
       * Arguments and parenthesis are optional; ex: @@a:b instead of @@a:b()
       * `alias` must have been previously bound to a class using @use.
       * If an alias is not specified, the default alias/namespace is assumed.
       * indentSpace is a white space string corresponding to the indentation level of this block.
       */

      $end = $ctx->MACRO_PREFIX . sprintf ($ctx->MACRO_END, '\3', '\4');

      $view = preg_replace_callback ('/
        (?<! \w)(\s*)                                         # capture white space before the line where the macro lies
        ^([\ \t]*)                                            # capture indentation
        ' . $ctx->MACRO_PREFIX . '                            # macro begins
        ([\w\-]+' . $ctx->MACRO_ALIAS_DELIMITER . ')?         # capture optional alias
        ([\w\-]+)                                             # capture macro method
        (?:
          \s*\((.*?)\)                                        # capture optional arguments block
        )?
        \s* ' . $ctx->MACRO_BEGIN . '                         # only match macros with a content block
        \s*                                                   # supress leading white space on the content
        (                                                     # capture macro content
          (?:                                                 # loop begin
            (?R)                                              # either recurse
          |                                                   # or
            (?! ' . $end . ').                                # consume one char until the closing tag is reached
          )*                                                  # repeat
        )' .
        $end . '                                              # match the macro end tag
        /sxm',
        function ($match) use (&$compile, $ctx) {
          //echo "BLOCK";
          //dd ($match);
          list ($all, $space, $indentSpace, $alias, $method, $args, $content) = $match;
          $alias = substr ($alias, 0, -strlen ($ctx->MACRO_ALIAS_DELIMITER));
          $method = Str::camel ($method);
          $class = $ctx->getNormalizedPrefix ($alias);
          if ($args != '')
            $args = ",$args";
          $content = trim ($compile ($content, $ctx));

          $c = 2 + substr_count ($args, ',');
          $realClass = $ctx->getNamespace ($alias);
          $info = new \ReflectionMethod($realClass, $method);
          $r = $info->getNumberOfRequiredParameters ();
          if ($c < $r)
            throw new RuntimeException ("Error on macro call:\n$all\n\nThe corresponding method $realClass::$method must have at least $r arguments, this call generates $c arguments.\nPlease check the method/call signatures.");

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
       *   (new namespace\class(array attributes, string html, array scopeVars, string indentSpace))->render();
       *
       * Creates a new instance of a namespace\class where:
       *   - `namespace` is a previously registered namespace for the given `prefix` (via xmlns or @use declarations).
       *   - `class` is a class name written in dash-case.
       *   - `scopeVars` are all the variables accesible in the calling PHP scope.
       *
       * If you want automatic support for converting attributes to class properties and other advanced functionality,
       * your class should subclass `contentwave\hyperblade\Component`.
       * `prefix` can be dash-cased.
       */
      $view = preg_replace_callback ('/
        (^\s*)?                 # capture white space from the beginning of the line
        < ([\w\-]+) : ([\w\-]+) # match and capture <prefix:tag
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
          $class = $ctx->getClass ($prefix, $tag);

          $realClass = $ctx->getFQClass ($prefix, $tag);
          $info = new \ReflectionMethod($realClass, '__construct');
          if ($info->getNumberOfParameters () != 3)
            throw new RuntimeException ("Component class $realClass's constructor must have 3 arguments.");

          $attrs = preg_replace ('/(?<=["\'])\s+(?=\w)/', ',', $attrs);
          $attrs = preg_replace ('/([\w\-]+)\s*=\s*(["\'])(.*?)\\2/', '\'$1\'=>$2$3$2', $attrs);
          $content = $compile ($content, $ctx);
          return "$space<?php ob_start() ?>$content<?php _h\\out((new $class(array($attrs),ob_get_clean(),get_defined_vars()))->render(),'$space'); ?>";
        }, $view);

      --$ctx->nestingLevel;

      return $view;
    };

    Blade::extend (function ($view) use (&$compile) {
      return $compile ($view, new CompilationContext);
    });

  }
}