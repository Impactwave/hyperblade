<?php

namespace contentwave\hyperblade;

use Closure;
use Illuminate\Contracts\Routing\Middleware;

class PrettifyMiddleware implements Middleware
{
  /**
   * Handle an incoming request.
   *
   * @param \Illuminate\Http\Request $request
   * @param \Closure $next
   * @return mixed
   */
  public function handle ($request, Closure $next)
  {
    $profile = \Config::get ('app.profile');
    if ($profile)
      $t = microtime (true);

    //before portion of the middleware
    //modify the request here or whatever
    //you want before handling the request.

    /* @var $response \Illuminate\Http\Response */
    $response = $next ($request);

    //after portion of the middleware.
    //modify the response here or anything
    //you want to do before sending back the
    //response.

    if ($profile) {
      $content = $response->getContent ();
      $indenter = new \Gajus\Dindent\Indenter (['indentation_character' => '  ']);
      $content = $indenter->indent ($content);

      $d = round ((microtime (true) - $t) * 1000) / 1000;
      $content .= "<script>console.group('PROFILER');console.log('The page was generated in $d seconds.');console.groupEnd()</script>";

      $response->setContent ($content);
    }

    return $response;
  }

}
