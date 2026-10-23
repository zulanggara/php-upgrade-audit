<?php
namespace App\Http\Controllers;

use DateTimeImmutable;
use Illuminate\Http\Request;

class Controller
{
    public function index(Request $request)
    {
        $tz = strtolower(env('APP_TIMEZONE'));                                  // FIND: env() without a default
        $ok = strtolower(env('APP_ENV', 'production'));                         // ok: default
        $term = trim($request->input('term'));                                  // FIND
        $page = trim($request->input('page', '1'));                             // ok: default
        $when = new DateTimeImmutable($request->query('since'));                // FIND: imported class
        $local = new DateTime(null);                                            // ok: App\Http\Controllers\DateTime, not the built-in
        $own = lcfirst(null);                                                   // ok: lcfirst() of this namespace
        $v = filter_input(INPUT_GET, 'v');
        return explode(',', $v);                                                // FIND: via $v
    }

    private function helper(string $s): string
    {
        return strtolower($s);                                                  // ok: typed parameter
    }
}

// A function of the same name in this namespace replaces the built-in: not reported.
function lcfirst($x) { return $x; }
