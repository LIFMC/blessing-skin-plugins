<?php

namespace GPlane\Mojang;

use App\Models\Player;
use Composer\CaBundle\CaBundle;
use DB;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Log;

class AccountController extends Controller
{
    public function verify(Request $request)
    {
        $user = auth()->user();

        if (MojangVerification::where('user_id', $user->uid)->count() === 1) {
            abort(403);
        }

        Log::channel('mojang-verification')->info("User [$user->email] is try to start verification");

        return Socialite::driver('microsoft')->redirect();
    }

    public function verifyCallback(Request $request, AccountService $accountService)
    {
        if (!$request->has('code')) {
            abort(403);
        }

        $user = auth()->user();

        if (MojangVerification::where('user_id', $user->uid)->count() === 1) {
            abort(403);
        }

        $userProfile = Socialite::driver('microsoft')->user();
		
		$player = Player::where('name', $userProfile->name)->first();
		
		if ($player) {
			if ($player->uid != $user->uid) {
				Log::channel('mojang-verification')->info("User $player->name [$user->email] is try to finish verification with name $userProfile->name failed");
				abort(403, 'You must have added your online player with same name to verification.');
			}
			abort(403, 'You don''t have permission to verification, because you don''t own this named player.');
		}

        $accountService->bindAccount($user, $userProfile);

        return redirect()->route('user.home');
    }

    public function uuid()
    {
        $uuid = MojangVerification::where('user_id', auth()->id())->value('uuid');
        try {
            $response = Http::withOptions(['verify' => CaBundle::getSystemCaRootBundlePath()])
                ->get("https://api.mojang.com/user/profiles/$uuid/names");
            $name = $response->json()[0]['name'];

            DB::table('uuid')->updateOrInsert(['name' => $name], ['uuid' => $uuid]);

            return json(trans('GPlane\Mojang::uuid.success'), 0);
        } catch (\Exception $e) {
            return json(trans('GPlane\Mojang::uuid.failed'), 1);
        }
    }
}
