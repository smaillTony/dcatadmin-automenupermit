<?php

namespace SmaillTony\DcatAdminAutomenuPermit;

use Closure;
use Dcat\Admin\Models\Menu;
use Dcat\Admin\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AutomenuPermit
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {

        $response = $next($request);

        $path = $request->path();
        $pathArr = explode('/', $path);
        $pathTotal = count($pathArr);
        $routeName = ($pathArr[0]??'') . '_' . ($pathArr[1]??'') . '_' . ($pathArr[2]??'');
        // 仅处理菜单路由
        if ($routeName === 'admin_auth_menu') {
            // 添加菜单触发
            if ($pathTotal === 3 &&
                $request->method() === 'POST' &&
                $request->post('title') &&
                $request->post('_token') &&
                $request->post('parent_id') >= 0
            ) {
                // 添加权限
                // 关联菜单和权限
                $latestMenu = Menu::orderBy('id', 'desc')->first();
                $latestMenuId = $latestMenu ? $latestMenu->id : null;
                if ($latestMenuId) {
                    $permission['name']       = $latestMenu['title'];
                    $permission['slug']       = (string)Str::uuid();
                    $permission['http_path']  = $this->getHttpPath($latestMenu['uri']);
                    $permission['order']      = $latestMenu['order'];
                    $permission['parent_id']  = $latestMenu['parent_id'];
                    $permission['created_at'] = $latestMenu['created_at'];
                    $permission['updated_at'] = $latestMenu['updated_at'];
                    DB::transaction(function () use ($permission, $latestMenuId) {
                        $permissionId = Permission::insertGetId($permission);
                        $insertData = [['permission_id' => $permissionId, 'menu_id' => $latestMenuId]];
                        if ($permission['parent_id'] != 0) {
                            $insertData[] = ['permission_id' => $permissionId, 'menu_id' => $permission['parent_id']];
                        }
                        DB::table('admin_permission_menu')->insert($insertData);
                    });
                }
            }
            // 修改菜单触发（不推荐）
            if ($pathTotal === 4 && $request->method() === 'PUT') {
                // $targetId = $pathArr[3];
                // 修改权限表
                // 修改关联菜单和权限表
                $apList = DB::table('admin_permissions')->select('id')->where('parent_id', '>', 0)->get();
                $apmList = DB::table('admin_permission_menu')->select('permission_id')->where('updated_at', '=', null)->get();
                $delApId = [];
                foreach ($apList as $apVal) {
                    $n = 0;
                    foreach ($apmList as $apmVal) {
                        if ($apVal->id == $apmVal->permission_id) {
                            ++$n;
                        }
                    }
                    if ($n < 2) {
                        $delApId[] = $apVal->id;
                    }
                }
                DB::transaction(function () use ($delApId) {
                    DB::table('admin_permissions')->whereIn('id', $delApId)->delete();
                    DB::table('admin_permission_menu')->whereIn('permission_id', $delApId)->delete();
                });
            }
            // 删除菜单触发（不推荐，因为权限和菜单是两种东西，删除时，本身就已经删除了关联关系）
            if ($pathTotal === 4 && $request->method() === 'DELETE') {
                //$targetId = $pathArr[3];
                // 删除权限
                // 删除菜单权限关联
                $amList = DB::table('admin_menu')->select('uri')->where('parent_id', '>', 0)->get();
                $apList = DB::table('admin_permissions')->select('id', 'http_path')->where('parent_id', '>', 0)->get();
                $hasApId = [];
                foreach ($amList as $amVal) {
                    foreach ($apList as $apVal) {
                        $http_path = explode(',', $apVal->http_path);
                        if (in_array($this->getHttpPath($amVal->uri), $http_path)) {
                            $hasApId[] = $apVal->id;
                            break;
                        }
                    }
                }
                $delApId = [];
                foreach ($apList as $apValue) {
                    if (!in_array($apValue->id, $hasApId)) {
                        $delApId[] = $apValue->id;
                    }
                }
                DB::transaction(function () use ($delApId) {
                    DB::table('admin_permissions')->whereIn('id', $delApId)->delete();
                    DB::table('admin_permission_menu')->whereIn('permission_id', $delApId)->delete();
                });
            }
        }

        return $response;
    }

    private function getHttpPath($uri)
    {
        if ($uri == '/') {
            return '';
        }

        if ($uri == '') {
            return '';
        }

        if (strpos($uri, '/') !== 0) {
            $uri = '/' . $uri;
        }

        return $uri . '*';
    }

}

