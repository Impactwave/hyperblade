<?php

namespace contentwave\hyperblade;

use RuntimeException,
    Illuminate\Support\Str,
    Illuminate\View\Compilers\BladeCompiler,
    Illuminate\Filesystem\Filesystem;

/**
 * This is an utility function for use within compiled templates.
 *
 * Adds the given space to the beginning of each line in the input string, except for the first line.
 * Then it outputs the resulting string.
 *
 * @param string $str
 * @param string $space
 */
function out ($str, $space)
{
  echo substr (preg_replace ('/^/m', $space, $str), strlen ($space));
}

/**
 * Escapes (secures) data for output.<br>
 * Hyperblade extends Blade's output escaping with support for additional data types.
 *
 * <p>Array attribute values are converted to space-separated value string lists.
 * > A useful use case for an array attribute is the `class` attribute.
 *
 * Object attribute values generate either:
 * - a space-separated list of keys who's corresponding value is truthy;
 * - a semicolon-separated list of key:value elements if at least one value is a string.
 *
 * Boolean values will generate the string "true" or "false".
 *
 * @param mixed $o
 * @return string
 */
function e ($o)
{
  if (!is_string ($o)) {
    switch (gettype ($o)) {
      case 'boolean':
        return $o ? 'true' : 'false';
      case 'integer':
      case 'double':
        return strval ($o);
      case 'array':
        $at = [];
        $s = ' ';
        foreach ($o as $k => $v)
          if (is_numeric ($k))
            $at[] = $v;
          else if (is_string ($v)) {
            $at[] = "$k:$v";
            $s = ';';
          }
          else
            $at[] = $k;
        $o = implode ($s, $at);
        break;
      case 'NULL':
        return '';
      default:
        throw new \InvalidArgumentException ("Can't output a value of type " . gettype ($o));
    }
  }
  return htmlentities ($o, ENT_QUOTES, 'UTF-8', false);
}

/**
 * Checks if a given PHP expression is syntatically valid.
 * @param string $exp
 * @return boolean
 */
function validatePHPExpressionSyntax ($exp)
{
  return eval ("return true;return $exp;");
}

/**
 * An extension to the Blade templating engine that provides macros and components.
 */
class HyperbladeCompiler extends BladeCompiler
{
  /**
   * Regular Expression pattern fragment for extracting one attribute from a tag.
   * Insert this after the tag name capture.
   * The host regex must have /sx options.
   * Two capture groups will be added to the regex match containing the attribute name and valie.
   * @var string
   */
  protected static $attributeCaptureRegEx = '
    \s*                 # match leading white space
    ([\w\-\:]+) \s*     # capture attribute name and eventuak prefix
    = \s*               # match = and eventual white space
    (                   # capture attribute value
      (?:               # switch
        \$? \w+         # either match attribute=\$var or attribute=constant (without quotes)
      |                 # or
        "               # the next char is a double quote
        [^"]*           # so consume everything until the next double quote
        "
      |                 # or
        \'              # the next char is a single quote
        [^\']*          # so consume everything until the next single quote (note: do not remove the space; PCRE *bug*)
        \'
      )                 # end switch
    )                   # end capture
    \s*                 # match trailing white space
';

  /**
   * The current compilation context.
   * @var CompilationContext
   */
  protected $ctx;

  /**
   * Records which view templates were already compiled during the current request.
   * @var array Map of string => boolean.
   */
  protected $wasCompiled = array ();

  /**
   * If true the output will retain the original indentation and templates are always compiled (no caching).
   * If false, the output will discard indentation and compiled templates are cached.
   *
   * @var bool
   */
  protected $debugMode = false;

  /**
   * Create a new compiler instance.
   *
   * @param Filesystem $files
   * @param string $cachePath
   * @param bool $debugMode If true, the output will retain the original indentation, otherwise it will be minified.
   */
  public function __construct (Filesystem $files, $cachePath, $debugMode = false, $profile = false)
  {
    parent::__construct ($files, $cachePath);
    $this->debugMode = $debugMode;
    $this->profile = $profile;
    $this->compilers = array_merge (
      [
        'Uses',
        'Namespaces',
        'Components',
      ], $this->compilers,
      [
        'ConfigBlocks',
        'ContentInjectors',
        'SimpleMacros',
        'BlockMacros',
    ]);
    $this->setEchoFormat ('_h\e(%s)');
  }

  /**
   * Applies formatting for wrapping macros/components code invocation.
   * When running in debug mode, it wraps the component with a _h\out call and prepends indentation space.
   *
   * @param string $space
   * @param string $exp
   * @return string
   */
  protected function decorateOutputCode ($space, $exp)
  {
    return $this->debugMode ? sprintf ($exp, $space, '_h\\out(', ",'$space')") : sprintf ($exp, '', 'echo ', '');
  }

  /**
   * Compiles the view at the given path.
   *
   * @param  string $path
   */
  public function compile ($path = null)
  {
    if ($this->profile) {
      $t = microtime (true);
      parent::compile ($path);
      $d = round ((microtime (true) - $t) * 1000) / 1000;
      $p = substr ($path, strlen (base_path ()));
      echo "<script>console.group('PROFILER');console.log('$p was compiled in $d seconds.');console.groupEnd()</script>";
    }
    else
      parent::compile ($path);

    if (!is_null ($this->cachePath))
      $this->wasCompiled[$path] = true;
  }

  /**
   * Compiles the given string template.
   * This is also called by compile().
   * Note that this method my perform multiple compilation passes, one for each PHP block on the string.
   *
   * @param  string $view
   * @return string
   */
  public function compileString ($view)
  {
    $this->ctx = new CompilationContext;
    $out = parent::compileString ($view);
    return $this->ctx->postProcess ($out);
  }

  /**
   * Compiles &#64;use directives.
   *
   * Imports a class or namespace into the current view.
   *
   * Syntax:
   *
   *       &#64;use(FQN[ as alias])
   *
   * Ex:
   *
   *       &#64;use (my\neat\Util as util)
   *
   * `FQN` (Fully Qualified Name) is a fully qualified class name or a namespace; it is case insensitive and it can
   * be dash-cased (it will be converted to camel case internally).
   *
   * ex: `my\namespace`, `my\namespace\myClass` or just `myClass`.
   *
   * If no alias is specified, the default nameless alias will be set.
   *
   * @param string $view
   * @return string
   */
  protected function compileUses ($view)
  {
    return preg_replace_callback ('/(?<!\w)(\s*)@use\s*\(\s*(\S+)\s*(?:as\s*([\w\-]+)\s*)?\)\s*$/m',
      function ($match) {
      $match[] = ''; // the default namespace.
      list (, $space, $FQN, $alias) = $match;
      $this->ctx->registerNamespace ($alias, $FQN);
      if ($alias) {
        $alias = $this->ctx->getNormalizedPrefix ($alias);
        $this->ctx->prolog[] = "use $FQN as $alias;";
      }
      return $space;
    }, $view);
  }

  /**
   * Compiles XML namespace declarations.
   *
   * Namespace declarations bind a XML prefix to a PHP namespace. They are equivalent to &#64;use directives.
   * They can be an attribute of any tag in the template, but they also may appear in comments (or even strings,
   * so beware!).
   *
   * Syntax:
   *
   *       &lt;sometag ... xmlns:prefix="namespace" ...>
   *
   * Ex:
   *
   *       &lt;div xmlns:form="ns1\ns2\forms">
   *
   *   is equivalent to:
   *
   *       &#64;use (ns1\ns2\forms as form)
   *
   * `prefix` can be dash-cased and it is case insensitive.
   *
   * @param string $view
   * @return string
   */
  protected function compileNamespaces ($view)
  {
    return preg_replace_callback ('/ \b xmlns:([\w\-]+) \s* = \s* (["\']) (.*?) \\2 \s* /x',
      function ($match) {
      list (, $prefix,, $namespace) = $match;
      $this->ctx->registerNamespace ($prefix, $namespace);
      $prefix = Str::camel ($prefix);
      $this->ctx->prolog[] = "use $namespace as $prefix;";
      return ''; //suppress attribute
    }, $view);
  }

  /**
   * Compiles &#64;config directives.
   *
   * Configuration blocks allow setting values in the component's scope directly from the view template.
   * These blocks can be used to configure the component's dynamically generated content, which usually is inserted
   * with the &#64;content directive.
   *
   * Syntax:
   *
   *       &#64;config
   *         property: value
   *         property: value
   *         ...
   *       &#64;endconfig
   *
   * `value` is a PHP expression.
   *
   * @param string $view
   * @return string
   */
  protected function compileConfigBlocks ($view)
  {
    return preg_replace_callback ('/@config\s*^(.*?)^\s*@endconfig\s*/sm',
      function ($match) {
      list (, $content) = $match;
      $content = substr (preg_replace ('/^(\s*)(\S+?):(\s*)(\S+)\s*$/m', '$1\'$2\'=>$3$4,', $content), 0, -1);
      $this->ctx->prolog[] = "\$my->config(array(\n$content\n));";
      return ''; //suppress directive
    }, $view);
  }

  /**
   * Compiles &#64;content directives.
   *
   * This directive inserts at its location the component's dynamically generated content.
   * If no content is generated, the component instance's content will be used.
   *
   * Syntax:
   *
   *       &#64;content
   *
   * @param string $view
   * @return string
   */
  protected function compileContentInjectors ($view)
  {
    return preg_replace_callback ($this->createPlainMatcher ('content'),
      function ($match) {
      list (, $leadSpace, $trailSpace) = $match;
      return "$leadSpace<?php echo \$my->getContent() ?>$trailSpace";
    }, $view);
  }

  /**
   * Compiles simple macros.
   *
   * Simple macros invoke a static method on a class and output its result.
   *
   * Syntax:
   *
   *       &#64;&#64;[classAlias.]method [(args)]
   *
   * Generated code (simplified):
   *
   *       classAlias::method (args)
   *
   * Arguments and parenthesis are optional; ex: &#64;&#64;a.b instead of &#64;&#64;a.b()
   *
   * `classAlias` must have been previously bound to a class using &#64;use.
   *
   * If an alias is not specified, the default nameless alias is assumed.
   *
   * @param string $view
   * @return string
   */
  protected function compileSimpleMacros ($view)
  {
    $allends = sprintf ($this->ctx->MACRO_END, '(?:[\w\-]+' . $this->ctx->MACRO_ALIAS_DELIMITER . ')?', '(?:[\w\-]+)');

    return preg_replace_callback ('/
        (?<! \w)                                              # make sure the macro prefix is not inside a text string
        (\s*)                                                 # capture leading white space
        ' . $this->ctx->MACRO_PREFIX . '                      # macro begins
        (?! ' . $allends . ')                                 # do not match end tags
        ([\w\-]+' . $this->ctx->MACRO_ALIAS_DELIMITER . ')?   # capture optional alias
        ([\w\-]+)                                             # capture macro method
        (?= \( | \s )                                         # force capture full word
        (?:
          \s*\((.*?)\)                                        # capture optional arguments block
        )?
        (?! \s*' . $this->ctx->MACRO_BEGIN . ')               # do not match macros with a content block
        (?! \s*\( )                                           # must not have skipped the arguments list
        /sxm',
      function ($match) {
      array_push ($match, ''); // allow $args to be undefined.
      list ($all, $space, $alias, $method, $args) = $match;

      preg_match ('/[\n\r]([ \t]*)$/', $space, $m);
      $indent = isset ($m[1]) ? $m[1] : '';

      if ($alias)
        $alias = substr ($alias, 0, -strlen ($this->ctx->MACRO_ALIAS_DELIMITER));
      $method = Str::camel ($method);
      $class = $this->ctx->getNormalizedPrefix ($alias);

      $c = substr_count ($args, ',');
      $realClass = $this->ctx->getNamespace ($alias);
      $info = new \ReflectionMethod ($realClass, $method);
      $r = $info->getNumberOfRequiredParameters ();
      if ($c < $r)
        throw new RuntimeException ("Error on macro call: $all\n\nThe corresponding method $realClass::$method must have at least $r arguments, this call generates $c arguments.\nPlease check the method/call signatures.");

      if (!$class)
        $class = $this->ctx->getNamespace ('');
      return $this->decorateOutputCode ($space, "%s<?php %s$class::$method($args)%s ?> "); //trailing space is needed for formatting.
    }, $view);
  }

  /**
   * Compiles block macros.
   *
   * Block macros invoke a static method on a class and output its result, and they also support a block of content
   * and source code indentation.
   *
   * Syntax:
   *
   *       &#64;&#64;[classAlias.]method [(args)]:
   *         html markup
   *       &#64;&#64;end[alias.]method
   *
   * Generated code (simplified):
   *
   *       classAlias::method (html,args...)
   *
   * Arguments and parenthesis are optional; ex: &#64;&#64;a.b instead of &#64;&#64;a.b()
   *
   * `alias` must have been previously bound to a class using &#64;use.
   *
   * If an alias is not specified, the default alias/namespace is assumed.
   *
   * @param string $view
   * @return string
   */
  protected function compileBlockMacros ($view)
  {
    $end = $this->ctx->MACRO_PREFIX . sprintf ($this->ctx->MACRO_END, '\3', '\4');

    return preg_replace_callback ('/
        (?<! \w)                                              # make sure the macro prefix is not inside a text string
        (\s*)                                                 # capture leading white space
        ' . $this->ctx->MACRO_PREFIX . '                      # macro begins
        ([\w\-]+' . $this->ctx->MACRO_ALIAS_DELIMITER . ')?   # capture optional alias
        ([\w\-]+)                                             # capture macro method
        (?:
          \s*\((.*?)\)                                        # capture optional arguments block
        )?
        \s* ' . $this->ctx->MACRO_BEGIN . '                   # only match macros with a content block
        \s*                                                   # supress leading white space on the content
        (                                                     # capture macro content
          (?:                                                 # loop begin
            (?=' . $this->ctx->MACRO_PREFIX . '\2\3)          # either the same tag is opened again
            (?R)                                              # and we must recurse
          |                                                   # or
            (?! ' . $end . ').                                # consume one char until the closing tag is reached
          )*                                                  # repeat
        )
        ' . $end . '                                          # match the macro end tag
        /sxm',
      function ($match) {
      list ($all, $space, $alias, $method, $args, $content) = $match;
      preg_match ('/[\n\r]([ \t]*)$/', $space, $m);

      $indent = isset ($m[1]) ? $m[1] : '';
      $alias = substr ($alias, 0, -strlen ($this->ctx->MACRO_ALIAS_DELIMITER));

      $method = Str::camel ($method);
      $class = $this->ctx->getNormalizedPrefix ($alias);
      if ($args != '')
        $args = ",$args";
      $content = $this->compileBlock (rtrim ($content), $this->ctx);

      $c = 1 + substr_count ($args, ',');
      $realClass = $this->ctx->getNamespace ($alias);
      $info = new \ReflectionMethod ($realClass, $method);
      $r = $info->getNumberOfRequiredParameters ();
      if ($c < $r)
        throw new RuntimeException ("Error on macro call:\n$all\n\nThe corresponding method $realClass::$method must have at least $r arguments, this call generates $c arguments.\nPlease check the method/call signatures.");

      if (strpos ($content, '<?') === false) {
        $content = str_replace ("'", "\\'", $content);
        return $this->decorateOutputCode ($space, "%s<?php %s$class::$method('$content'$args)%s ?> "); //trailing space is needed for formatting.
      }
      return $this->decorateOutputCode ($space,
        "<?php ob_start() ?>$content<?php %s$class::$method(ob_get_clean()$args)%s ?> "); //trailing space is needed for formatting.
    }, $view);
  }

  /**
   * Compiles components.
   *
   * Hyperblade components transform tags and their contents into something else.
   *
   * Syntax:
   *
   *       &lt;prefix:class [attr1="", ...]>html markup&lt;/prefix:class>
   *
   * Ex:
   *
   *       &lt;form:super-field name="field1" my:super-mixin="test">
   *         &lt;input type="text">
   *       &lt;/form:super-field>
   *
   *   will be compiled to (simplified example):
   *
   *       (new namespace\SuperField(['name'=>'field1'], '&lt;input type="text">', array scopeVars))->mixin(new my\SuperMixin('test'))->render();
   *
   *   this will create a new instance of a namespace\class where:
   *     - `namespace` is a previously registered namespace for the given `prefix` (via xmlns or &#64;use declarations).
   *     - `class` is a class name written in dash-case.
   *     - `scopeVars` are all the variables accesible in the calling PHP scope.
   *
   * Note: if you want automatic support for converting attributes to class properties and other advanced functionality,
   * your class should subclass `contentwave\hyperblade\Component`.
   *
   * Note: `prefix` can be dash-cased.
   *
   * Note: This method may call itself recursively to process the generated output from a component.
   *
   * @param string $view
   * @return string The compiled template.
   */
  public function compileComponents ($view)
  {
    ++$this->ctx->nestingLevel;

    $view = preg_replace_callback ('/
        (^\s*)?                                 # capture white space from the beginning of the line
        < ([\w\-]+) : ([\w\-]+)                 # match and capture <prefix:tag
        (                                       # capture the tag\'s attributes
          (?:                                   # loop begin
          ' . self::$attributeCaptureRegEx . '  # match next attribute
          )*                                    # repeat for each attribute found
        )                                       # end capture
        >                                       # match the tag closing delimiter
        (?: \n\s*)?                             # if present, supress line break and additional space
        (                                       # capture the tag\'s content
          (?:                                   # loop begin
            (?=<\2:\3[\s>])                     # either the same tag is opened again
            (?R)                                # and we must recurse
          |                                     # or
            (?! <\/\2:\3>).                     # consume one char until the closing tag is reached
          )*                                    # repeat
        )
        <\/\2:\3>                               # consume the closing tag
      /smx',
      function ($match) {
      list (, $indent, $prefix, $tag, $attrList,,, $content) = $match;

      // The reserved predefined prefix 'html' is used to promote a simple tag to a generic html component.
      // This allows mixins on that tag to be recognized and applied.
      // No PHP namespace needs to be associated with this special prefix, neither is the prefix registered.

      if ($prefix == 'html') {
        $attrList = " tag=\"$tag\"$attrList";
        $tag = "html";
        $prefix = '_h';
      }

      // Find the component implementation.

      $class = $this->ctx->getClass ($prefix, $tag);
      $realClass = $this->ctx->getFQClass ($prefix, $tag);
      $info = new \ReflectionMethod ($realClass, '__construct');
      if ($info->getNumberOfParameters () != 3)
        throw new RuntimeException ("Component class $realClass's constructor must have 3 arguments.");

      // Collect the tag's attributes.

      $attrs = [];
      $mixins = [];
      $attrsAsStr = '';
      $mixinsList = '';

      if ($attrList) {
        preg_match_all ('/' . self::$attributeCaptureRegEx . '/sxm', $attrList, $match2, PREG_SET_ORDER);

        // Build the attribute map.

        foreach ($match2 as $m) {
          $m[] = null; // supply last match group if it's absent.
          list (, $name, $val) = $m;
          $quote = $val[0];
          if ($quote == "'" || $quote == '"') {
            $unquoted = html_entity_decode (substr ($val, 1, -1), ENT_QUOTES | ENT_HTML5);
            $val = $this->compileInterpolator ($unquoted, $quote);
          }

          // Process attribute directives.
          // These are excluded from the $attrs map and included in the $mixins list.

          $s = explode (':', $name, 2);
          if (count ($s) == 2) {
            list ($prefix, $name) = $s;
            $mixinClass = $this->ctx->getClass ($prefix, $name);
            $mixins[] = "new $mixinClass($val)";
          } /*
            If it's not a directive attribute, store it on the attributes map.
           */
          else
            $attrs[$name] = $val;
        }

        // Generate a textual representation of the tag's attributes.

        $attrsAsStr = implode (',',
          array_map (function ($k, $v) {
            return "'$k'=>$v";
          }, array_keys ($attrs), array_values ($attrs)));

        // Generate code for invoking all applicable plugins.

        if (!empty ($mixins))
          $mixinsList = "->mixin(" . implode (',', $mixins) . ")";
      } // End of attributes processing block.
      //
      // Unindent the tag's content, subtracting from it the tag's indentation level.
      $content = preg_replace ("/^$indent/m", '', $content);

      // Recursively compile the content.
      $content = $this->compileComponents ($content);

      // Avoid using output buffers if not really necessary. If the content has no PHP code, pass it to the component
      // as a simple string.
      if (strpos ($content, '<?') === false && strpos ($content, $this->contentTags[0]) === false) {
        // Escape single quotes.
        $content = str_replace ("'", "\\'", $content);
        $content = trim ($content);
        return $this->decorateOutputCode ($indent, "%s<?php %s(new $class([$attrsAsStr],'$content',get_defined_vars())){$mixinsList}->run()%s ?> "); //trailing space is needed for formatting.
      }

      // Otherwise, use buffering to capture dynamic content and pass it to the component.
      return $this->decorateOutputCode ($indent, "%s<?php ob_start() ?>$content$indent<?php %s(new $class([$attrsAsStr],ob_get_clean(),get_defined_vars())){$mixinsList}->run()%s ?> "); //trailing space is needed for formatting.
    }, $view);

    --$this->ctx->nestingLevel;

    return $view;
  }

  /**
   * Compile an attribute value that is an interpolated expression.
   *
   * Note: Static html tags do not need interpolated attribute transformations, so standard Blade output code will be
   * generated for those interpolations.
   * On the other hand, components with attribute interpolations must be handled differently.
   *
   * @param string $value A string containing interpolated expressions delimited with {{ }}.
   *                      The attribute's value should be enclosed in double quotes.
   * @param string $quote Either `"` or `'`.
   * @return string
   */
  protected function compileInterpolator ($value, $quote)
  {
    $open = preg_quote ($this->contentTags[0]);
    $close = preg_quote ($this->contentTags[1]);
    $segments = array ();
    // Create an array of literal strings or interpolated expressions. Try to make it as small as possible by
    // merging adjacent literal strings or by inserting native PHP interpolations into literal strings.
    // Return value is irrelevant.
    preg_replace_callback ("/
        $open \\s* (.*?) \\s* $close  # capture interpolator
        |                             # or
        (?:(?!$open).)+               # capture literal text
      /x",
      // For each interpolation segment.
      function ($match) use (&$segments) {
      $match[] = '';
      list ($all, $exp) = $match;
      if (empty ($segments))
        $last = null;
      else
        $last = &$segments[count ($segments) - 1];

      // If it's not an interpolator, it's plain text.

      if ($exp == '') {
        // Escape double quotes and dollars.
        $seg = str_replace (array (
            '"',
            '$'), array (
            '\\"',
            '\$'), $all);
        // It the previous segment is a string, merge this string with it (optimize).
        if (isset ($last) && $last[0] == '"')
          $last = substr ($last, 0, -1) . $seg . '"';
        // If this is the first segment or the last one is not a string, add this to the segment list.
        else
          $segments[] = '"' . $seg . '"';
      } /*
        If it's a simple interpolation {{$VAR}}, transform it into a PHP string interpolation.
       */
      else if (preg_match ('/^\$?\w+$/', $exp)) {
        if (!isset ($last))
        // If it's the first segment, just add it to the list.
          $segments[] = $exp;
        else {
          // If the previous segment is a string, merge this one with it.
          if ($last[0] == '"')
            $last = substr ($last, 0, -1) . $exp . '"';
          // The previous segment is not a string, so just append this one the list.
          else
            $segments[] = $exp;
        }
      } /*
        Otherwise, it's a complex expression, so enclose it in quotes.
       */
      else
        $segments[] = '(' . $exp . ')';
    }, $value);

    // Simplify a single expression like `(exp)` to `exp`.
    if (count ($segments) == 1 && preg_match ('/^\(.+\)$/', $segments[0]))
      $segments = array (
          substr ($segments[0], 1, -1));

    // At this point $segments contains items either starting with '"' (literal string), '(' (complex expressions)
    // or '$' (variable name).
    // Generate PHP string interpolator.
    $exp = implode ('.', $segments);
    if (!validatePHPExpressionSyntax ($exp))
      throw new RuntimeException ("Syntax error on interpolated attribute value: $value\nCompiled PHP expression: $exp");
    return $exp;
  }

  /**
   * Get the path to the compiled version of a view.
   * This override appends a php extension to facilitate debugging in an IDE.
   *
   * @param  string $path
   * @return string
   */
  public function getCompiledPath ($path)
  {
    return parent::getCompiledPath ($path) . '.php';
  }

  /**
   * Determine if the view at the given path is expired.
   *
   * This method overrides the one provided by the Blade compiler to implement better expiration detection, therefore
   * avoiding redundant template compilations when the same component is rendered multiple times in the same view,
   * due to the time granularity limitations of the operating system's file system's modification timestamps.
   *
   * Also, when running in debug mode, templates are always expired.
   *
   * @param  string $path
   * @return bool
   */
  public function isExpired ($path)
  {
    return $this->debugMode ? : (isset ($this->wasCompiled[$path]) ? false : parent::isExpired ($path));
  }

}
