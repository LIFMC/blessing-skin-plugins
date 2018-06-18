<?php

use App\Events;
use App\Models\Player;
use App\Services\Hook;
use Illuminate\Contracts\Events\Dispatcher;

return function (Dispatcher $events) {

    // 在 users 表上添加 player_name 字段
    if (! Schema::hasColumn('users', 'player_name')) {
        Schema::table('users', function ($table) {
            $table->string('player_name')->default('')->comment = "Added by single-player-limit plugin.";
        });
    }

    if (! Option::has('allow_change_player_name')) {
        Option::set('allow_change_player_name', true);
    }

    $events->listen(Events\UserAuthenticated::class, function ($event) {
        $user = $event->user;

        if (request()->is('user/bind-player-name')) {
            if ($user->player_name) {
                return redirect('user')->send();
            }

            return;
        }

        // 要求绑定唯一角色名
        if (! $user->player_name) {
            return redirect('user/bind-player-name')->send();
        }

        // 确保用户拥有该角色
        $player = Player::where('player_name', $user->player_name)->first();

        if (! $player) {
            $player = new Player;

            $player->uid           = $user->uid;
            $player->player_name   = $user->player_name;
            $player->preference    = 'default';
            $player->last_modified = Utils::getTimeFormatted();
            $player->save();

            Log::info("[SinglePlayerLimit] New player [$player->player_name] was allocated to user [$user->email]");
            event(new Events\PlayerWasAdded($player));
        }

        if ($player->uid != $user->uid) {
            $player->uid = $user->uid;
            $player->save();

            Log::info("[SinglePlayerLimit] Player [$player->player_name] was transfered to user [$user->email]");
        }

        // 释放该用户拥有的其他角色
        $players = Player::where('uid', $user->uid)->get();
        foreach ($players as $player) {
            if ($player->player_name != $user->player_name) {
                $player->delete();
            }
        }

        // 将昵称修改为与绑定角色名一致
        $user->nickname = $user->player_name;
        $user->save();
    });

    // 禁止用户添加或删除角色
    $events->listen([
        Events\PlayerWillBeAdded::class,
        Events\PlayerWillBeDeleted::class
    ], function ($event) {
        exit(json('由于本站设置，你无法添加或删除角色', 1)->getContent());
    });

    // 禁止用户修改昵称
    $events->listen(Events\UserProfileUpdated::class, function ($event) {
        if ($event->type != 'nickname') {
            return;
        }

        $event->user->setNickName($event->user->player_name);
        exit(json('由于本站设置，你无法修改昵称（昵称将被设定为与绑定角色名一致）', 1)->getContent());
    });

    // 删除用户中心菜单中的「角色管理」项目
    $events->listen(Events\ConfigureUserMenu::class, function ($event) {
        $event->menu['user'] = collect($event->menu['user'])->reject(function ($item) {
            return $item['link'] == 'user/player';
        })->all();
    });

    // 替换材质上传页面，提供「自动应用皮肤」功能
    View::alias('SinglePlayerLimit::upload', 'skinlib.upload');

    // 添加路由
    Hook::addRoute(function ($router) {
        $router->group([
            'middleware' => ['web'],
            'namespace'  => 'SinglePlayerLimit\Controllers',
        ], function ($router) {
            $router->get('auth/register',  'AuthController@register');
            $router->post('auth/register', 'AuthController@handleRegister');
        });

        $router->group([
            'middleware' => ['web', 'auth'],
            'namespace'  => 'SinglePlayerLimit\Controllers',
        ], function ($router) {
            $router->any('user', 'UserController@index');
            $router->any('user/closet', 'UserController@closet');
            $router->get('user/bind-player-name', 'UserController@showBindPage');
            $router->post('user/bind-player-name', 'UserController@bindPlayerName');
            $router->post('user/change-player-name', 'UserController@changePlayerName');

            $router->any('user/player', function () {
                return abort(403, '由于本站设置，角色管理页面已被禁用，请前往用户中心首页管理您的角色。');
            });
        });

        $router->group([
            'middleware' => ['web', 'auth', 'admin'],
            'namespace'  => 'SinglePlayerLimit\Controllers',
        ], function ($router) {
            $router->post('admin/query-player-name-by-uid', 'AdminController@queryByUid');
            $router->post('admin/query-uid-by-player-name', 'AdminController@queryByPlayerName');
            $router->post('admin/change-user-bind-player-name', 'AdminController@changeUserBindPlayerName');
        });
    });
};
