<?php

namespace App\Http\Middleware;

use App\Models\Master;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveCurrentMaster
{
    public function handle(Request $request, Closure $next): Response
    {
        $masterId = $request->header('X-Master-Id');

        if (!empty($masterId)) {
            $request->attributes->set('current_master', Master::find($masterId));
        }

        return $next($request);
    }
}
