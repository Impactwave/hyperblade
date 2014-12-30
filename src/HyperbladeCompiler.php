<?php
namespace contentwave\hyperblade;

use Blade, RuntimeException, Illuminate\Support\Str, Illuminate\View\Compilers\BladeCompiler;
use Illuminate\Filesystem\Filesystem;

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
 * An extension to the Blade templating engine that provides macros and components.
 */
class HyperbladeCompiler extends BladeCompiler
{
  /**
   * The current compilation context.
   * @var CompilationContext
   */
  protected $ctx;
  /**
   * Records which view templates were already compiled during the current request.
   * @var array Map of string => boolean.
   */
  protected $wasCompiled = array();

  /**
   * Create a new compiler instance.
   *
   * @param  Filesystem $files
   * @param  string $cachePath
   */
  public function __construct (Filesystem $files, $cachePath)
  {
    parent::__construct ($files, $cachePath);
    $this->compilers[] = 'HyperbladeDirectives';
  }

  /**
   * Compile the view at the given path.
   *
   * @param  string $path
   */
  public function compile ($path = null)
  {
    parent::compile ($path);

    if (!is_null ($this->cachePath))
      $this->wasCompiled[$path] = true;
  }

  /**
   * Compile the given Hyperblade template contents.
   *
   * @param  string $value
   * @return string
   */
  public function compileString ($view)
  {
    $this->ctx = new CompilationContext;
    $out = parent::compileString ($view);
    return $this->ctx->postProcess ($out);
  }

  public function compileHyperbladeDirectives ($view)
  {
    return $this->compileSegment ($view);
  }

  /**
   * Compiles a segment of a view template.
   *
   * Note: a view template can be split into multiple segments when there is embedded PHP code in the template.
   * If there is no embedded code, there is a single segment that encompasses the full template.
   *
   * @param string $segment
   * @param string $viewPath
   * @return string The compiled block.
   */
  public function compileSegment ($segment)
  {
    $ctx = $this->ctx;
    $out = $this->compileBlock ($segment, $ctx);
    return $out;
  }

  /**
   * Compiles the given string.
   * This method may call itself recursively.
   *
   * Note: a block can be a segment or it can be the generated output from a component.
   *
   * @param string $view
   * @param CompilationContext $ctx A context that can be reused between recursive calls and consecutive blocks.
   * @return string The compiled template.
   */
  public function compileBlock ($view, CompilationContext $ctx)
  {
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

    $view = preg_replace_callback ('/\bxmlns:([\w\-]+)\s*=\s*(["\'])(.*?)\\2\s*/',
      function ($match) use ($ctx) {
        list ($all, $prefix, $quote, $namespace) = $match;
        $ctx->registerNamespace ($prefix, $namespace);
        $prefix = Str::camel ($prefix);
        $ctx->prolog[] = "use $namespace as $prefix;";
        return ''; //suppress attribute
      }, $view);

    /*
     * Configuration blocks allow setting values in the component's scope directly from the view template.
     * These blocks can be used to configure the component's dynamically generated content, which usually is inserted
     * with the @content directive.
     *
     * Syntax:
     *
     *   @config
     *     property: value
     *     property: value
     *     ...
     *   @endconfig
     *
     * `value` is a PHP expression.
     */

    $view = preg_replace_callback ('/@config\s*^(.*?)^\s*@endconfig\s*/sm',
      function ($match) use ($ctx) {
        list ($all, $content) = $match;
        $content = substr (preg_replace ('/^(\s*)(\S+?):(\s*)(\S+)\s*$/m', '$1\'$2\'=>$3$4,', $content), 0, -1);
        $ctx->prolog[] = "\$my->config(array(\n$content\n));";
        return ''; //suppress directive
      }, $view);

    /*
     * Inserts at its location the component's dynamically generated content.
     * If no content is generated, the component instance's content will be used.
     *
     * Syntax:
     *
     *   @content
     */

    $view = preg_replace_callback ($this->createPlainMatcher ('content'),
      function ($match) use ($ctx) {
        list ($all, $leadSpace, $trailSpace) = $match;
        return "$leadSpace<?php echo \$my->getContent() ?>$trailSpace";
      }, $view);

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
        (?<! \w)                                              # make sure the macro prefix is not inside a text string
        (\s*)                                                 # capture leading white space
        ' . $ctx->MACRO_PREFIX . '                            # macro begins
        (?! ' . $allends . ')                                 # do not match end tags
        ([\w\-]+' . $ctx->MACRO_ALIAS_DELIMITER . ')?         # capture optional alias
        ([\w\-]+)                                             # capture macro method
        (?= \( | \s )                                         # force capture full word
        (?:
          \s*\((.*?)\)                                        # capture optional arguments block
        )?
        (?! \s*' . $ctx->MACRO_BEGIN . ')                     # do not match macros with a content block
        (?! \s*\( )                                           # must not have skipped the arguments list
        /sxm',
      function ($match) use ($ctx, $view, $allends) {
        array_push ($match, ''); // allow $args to be undefined.
        list ($all, $space, $alias, $method, $args) = $match;

        preg_match ('/[\n\r]([ \t]*)$/', $space, $m);
        $indent = isset($m[1]) ? $m[1] : '';

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
        return "$space<?php _h\\out($class::$method($args),'$indent') ?> "; //trailing space is needed for formatting.
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
     *   classAlias::method (html,args...)
     *
     * Arguments and parenthesis are optional; ex: @@a:b instead of @@a:b()
     * `alias` must have been previously bound to a class using @use.
     * If an alias is not specified, the default alias/namespace is assumed.
    */

    $end = $ctx->MACRO_PREFIX . sprintf ($ctx->MACRO_END, '\3', '\4');

    $view = preg_replace_callback ('/
        (?<! \w)                                              # make sure the macro prefix is not inside a text string
        (\s*)                                                 # capture leading white space
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
            (?=' . $ctx->MACRO_PREFIX . '\2\3)                # either the same tag is opened again
            (?R)                                              # and we must recurse
          |                                                   # or
            (?! ' . $end . ').                                # consume one char until the closing tag is reached
          )*                                                  # repeat
        )
        ' . $end . '                                          # match the macro end tag
        /sxm',
      function ($match) use ($ctx) {
        list ($all, $space, $alias, $method, $args, $content) = $match;
        preg_match ('/[\n\r]([ \t]*)$/', $space, $m);

        $indent = isset($m[1]) ? $m[1] : '';
        $alias = substr ($alias, 0, -strlen ($ctx->MACRO_ALIAS_DELIMITER));

        $method = Str::camel ($method);
        $class = $ctx->getNormalizedPrefix ($alias);
        if ($args != '')
          $args = ",$args";
        $content = $this->compileBlock (rtrim ($content), $ctx);

        $c = 1 + substr_count ($args, ',');
        $realClass = $ctx->getNamespace ($alias);
        $info = new \ReflectionMethod($realClass, $method);
        $r = $info->getNumberOfRequiredParameters ();
        if ($c < $r)
          throw new RuntimeException ("Error on macro call:\n$all\n\nThe corresponding method $realClass::$method must have at least $r arguments, this call generates $c arguments.\nPlease check the method/call signatures.");

        if (strpos ($content, '<?') === false) {
          $content = str_replace ("'", "\\'", $content);
          return "$space<?php _h\\out($class::$method('$content'$args),'$indent') ?> "; //trailing space is needed for formatting.
        }
        return "$space<?php ob_start() ?>$content<?php _h\\out($class::$method(ob_get_clean()$args),'$indent') ?> "; //trailing space is needed for formatting.
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
     *   <form:super-field name="field1" my:super-mixin="test">
     *     <input type="text">
     *   </form:super-field>
     *
     *   will be compiled to (simplified example):
     *
     *   (new namespace\SuperField(['name'=>'field1'], '<input type="text">', array scopeVars))->mixin(new my\SuperMixin('test'))->render();
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
        >
        (                       # capture tag content
          (?:                   # loop begin
            (?=<\1:\2[\s>])     # either the same tag is opened again
            (?R)                # and we must recurse
          |                     # or
            (?! <\/\2:\3>).     # consume one char until the closing tag is reached
          )*                    # repeat
        )
        <\/\2:\3>               # consume the closing tag
      /smx',
      function ($match) use ($ctx) {
        list ($all, $indent, $prefix, $tag, $attrs, $content) = $match;
        $class = $ctx->getClass ($prefix, $tag);

        $realClass = $ctx->getFQClass ($prefix, $tag);
        $info = new \ReflectionMethod($realClass, '__construct');
        if ($info->getNumberOfParameters () != 3)
          throw new RuntimeException ("Component class $realClass's constructor must have 3 arguments.");

        // Transform the tag's attributes.

        if ($attrs) {
          // Convert white space separating attributes into commas.
          $attrs = preg_replace ('/([\w\-\:]+)\s*=\s*("|\')(.*?)\2\s*(?=\w)/', '$1=$2$3$2,', $attrs);
          $attrs = preg_replace ('/§end\s+(?=\w)/', '§end,', $attrs);
          // Compile interpolated attributes.
          $attrs = preg_replace ('/([\w\-\:]+)=§begin(.*?)§end/', '\'$1\'=>$2', $attrs);
          // Convert attributes into PHP array key=>value tuplets.
          $attrs = preg_replace ('/([\w\-\:]+)\s*=\s*("|\')(.*?)\\2/', '\'$1\'=>$2$3$2', $attrs);
          $attrs = $this->parseAttributeDirectives ($attrs);
        }

        // Unindent the tag's content, subtracting from it the tag's indentation level.
        $content = preg_replace ("/^$indent/m", '', $content);
        // Recursively compile the content.
        $content = $this->compileBlock ($content, $ctx);
        // Avoid using output buffers if not really necessary. If the content has no PHP code, pass it to the component
        // as a simple string.
        if (strpos ($content, '<?') === false) {
          // Escape single quotes.
          $content = str_replace ("'", "\\'", $content);
          return "$indent<?php _h\\out((new $class(array($attrs),'$content',get_defined_vars()))->run(),'$indent'); ?> "; //trailing space is needed for formatting.
        }
        // Use buffering to capture dynamic content and pass it to the component.
        return "$indent<?php ob_start() ?>$content<?php _h\\out((new $class(array($attrs),ob_get_clean(),get_defined_vars()))->run(),'$indent'); ?> "; //trailing space is needed for formatting.
      }, $view);

    --$ctx->nestingLevel;

    return $view;
  }

  /**
   * Converts
   * @param string $src
   * @return string
   */
  protected function parseAttributeDirectives ($src) {
    $attrs = preg_replace_callback ('/
      \'([\w\-]+)(:[\w\-]+)?    # match and capture prefix:attribute (prefix being optional)
      (.*?)               # capture attributes
      >
      (                   # capture tag content
        (?:               # loop begin
          (?=<\1[\s>])    # either the same tag is opened again
          (?R)            # and we must recurse
        |                 # or
          (?! <\/\1>).    # consume one char until the closing tag is reached
        )*                # repeat
      )
      <\/\1>             # consume the closing tag
      /sx',
      function ($match) {
        list ($all, $tag, $attrs, $content) = $match;
      }, $src);

    return $attrs;

    $attrs = eval("return array($src);");
    if (!$attrs)
      throw new RuntimeException ("Invalid attributes for tag. Generated code is: $src");
    $newAttrs = array();
    $found = false;
    foreach ($attrs as $k => $v)
      if (strpos($k, ':') !== false) {
        $found = true;
      }
    var_dump($newAttrs);
    if (!$found)
      return $src;
    $out = array();
    foreach ($attrs as $k => $v)
      $out[] = self::toAttribute ($k, $v);
    return $src;
  }

  /**
   * Compile Blade echos into valid PHP.
   * Special care is taken with echo expressions inside component attributes. In that case, only regular echo tags are
   * supported and they are converted into a special syntax that components recognize and that they use to embed PHP
   * expressions in the component instantiation.
   *
   * @param  string $view
   * @return string
   */
  protected function compileEchos ($view)
  {

    $view = preg_replace_callback ('/
      < ([\w\-\:]+)       # match and capture <tag
      (.*?)               # capture attributes
      >
      (                   # capture tag content
        (?:               # loop begin
          (?=<\1[\s>])    # either the same tag is opened again
          (?R)            # and we must recurse
        |                 # or
          (?! <\/\1>).    # consume one char until the closing tag is reached
        )*                # repeat
      )
      <\/\1>             # consume the closing tag
      /sx',
      function ($match) {
        list ($all, $tag, $attrs, $content) = $match;
        list ($openRaw, $closeRaw) = $this->contentTags;
        $open = preg_quote ($openRaw);
        $close = preg_quote ($closeRaw);
        /** @var boolean $convertToComponent Set to true when a simple tag must be converted to a component tag */
        $convertToComponent = false;
        $isComponent = Str::contains ($tag, ':');

        // Simple tags that contain attribute directives must be converted to components
        // (except for the reserved attribute xmlns).

        if (!$isComponent && preg_match ('/\b(?!xmlns:)[\w\-]+:[\w\-]+\s*=/', $attrs))
          $convertToComponent = true;

        // Handle attributes with interpolators.

        $attrs = preg_replace ('/
          ([\w\-\:]+) \s* = \s* (\$?\w+) # match attribute=$var or attribute=constant
          /x',
          "$1=\"$openRaw$2$closeRaw\"", $attrs); // Convert it to attribute="{{var_or_constant}}"

        if ($isComponent) // static html tags do not need interpolated attribute transformations

          $attrs = preg_replace_callback ('/
            ([\w\-\:]+) \s* = \s* ("|\')   # match attribute="
            (                              # capture the attribute value
              (?:                          # for each mixed text and interpolator fragment
                (?:
                  (?!' . $open . ')        # while no next interpolator opening tag
                  (?!\2)                   # and no attribute value end quote
                  .                        # consume text
                )*?                        # until interpolator opening tag, optional
                ' . $open . '              # must have an interpolator
                (?!' . $close . ').*?      # consume text until interpolator closing tag
                ' . $close . '             # consume the interpolator closing tag
                (?:                        # while
                  (?!' . $open . ')        # no next interpolator opening tag
                  (?!\2)                   # and no attribute value end quote
                  .                        # consume text
                )*?                        # repeat, optional
              )+                           # loop (consume remaining fragments)
            )
            \2                             # match the attribute value ending quote
            /sx',
            function ($match) use ($open, $close) {
              list ($all, $attr, $quote, $value) = $match;

              $atl = array();
              // Create an array of literal strings or interpolated expressions. Try to make it as small as possible by
              // merging adjacent literal strings or inserting simple interpolations into literal strings
              preg_replace_callback ("/
              $open \\s* (.*?) \\s* $close  # capture interpolator
              |                             # or
              (?:(?!$open).)+               # capture literal text
            /x", function ($match) use (&$atl) {
                if (!isset($match[1])) { // it's not an interpolator
                  $seg = str_replace (array('"', '$'), array('\\"', '\$'), $match[0]);
                  if (empty($atl) || $atl[count ($atl) - 1][0] != '"')
                    $atl[] = '"' . $seg . '"';
                  else $atl[count ($atl) - 1] = substr ($atl[count ($atl) - 1], 0, -1) . $seg . '"';
                } else if (preg_match ('/^\$?\w+$/', $match[1])) { // if simple interpolation ($VAR)
                  if (empty($atl)) $atl[] = '"' . $match[1] . '"';
                  else {
                    $e = $atl[count ($atl) - 1];
                    if ($e[0] == '"')
                      $atl[count ($atl) - 1] = substr ($e, 0, -1) . $match[1] . '"';
                    else $atl[] = $match[1];
                  }
                } else // complex interpolator
                  $atl[] = '(' . $match[1] . ')';
              }, $value);
              // Simplify a single expression like "$var" to $var, or (exp) to exp.
              if (count ($atl) == 1 && preg_match ('/^"\$?\w+"$|^\(.+\)$/', $atl[0]))
                $atl = array(substr ($atl[0], 1, -1));
              // At this point $atl contains items either starting with '"' (literal string), '(' (complex expressions)
              // or '$' (variable name).
              // Generate PHP string interpolator.
              $value = implode ('.', $atl);
              // Mark attribute as being interpolated, it will be handled in a special way on the component processing stage.
              return "{$attr}=§begin{$value}§end";
            }, $attrs);

        if ($convertToComponent) {
          $attrs = " tag=\"$tag\"$attrs"; //NOTE: do not swap this statement with the next one!
          $tag = "_h:html";
        }
        $content = $this->compileEchos ($content);

        return "<$tag$attrs>$content</$tag>";
      }, $view);

    return parent::compileEchos ($view);
  }

  /**
   * Determine if the view at the given path is expired.
   *
   * This method overrides the one provided by the Blade compiler to implement better expiration detection, therefore
   * avoiding redundant template compilations when the same component is rendered multiple times in the same view,
   * due to the time granularity limitations of the operating system's file system's modification timestamps.
   *
   * @param  string $path
   * @return bool
   */
  public function isExpired ($path)
  {
    return isset($this->wasCompiled[$path]) ? false : parent::isExpired ($path);
  }

}