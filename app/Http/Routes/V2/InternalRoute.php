<?php

namespace App\Http\Routes\V2;

use App\Http\Controllers\V2\Internal\NodeUserSyncController;
use Illuminate\Contracts\Routing\Registrar;

/** Hop Java 与当前 Xboard 节点控制面之间的受签名内部路由。 */
class InternalRoute
{
    public function map(Registrar $router): void
    {
        $router->group([
            'prefix' => 'internal',
            'middleware' => 'java.node-sync',
        ], function ($route) {
            $route->post('node-user-sync', [NodeUserSyncController::class, 'store']);
        });
    }
}
