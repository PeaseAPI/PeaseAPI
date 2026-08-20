<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuthFlow;
use App\Models\CustomOAuthProvider;
use App\Models\User;
use App\Models\UserOAuthBinding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class OAuthController extends Controller
{
    public function generateState(Request $request)
    {
        $state = Str::random(64);

        AuthFlow::create([
            'flow_token' => $state,
            'action' => 'oauth_state',
            'payload' => ['redirect_uri' => $request->input('redirect_uri')],
            'expires_at' => now()->addMinutes(10),
        ]);

        return response()->json(['success' => true, 'data' => ['state' => $state]]);
    }

    public function redirectToGithub(Request $request)
    {
        $clientId = config('services.github.client_id');
        $redirectUri = config('services.github.redirect');
        $url = "https://github.com/login/oauth/authorize?client_id={$clientId}&redirect_uri={$redirectUri}&scope=read:user";

        if ($request->query('state')) {
            $url .= '&state='.$request->query('state');
        }

        return redirect($url);
    }

    public function handleGithubCallback(Request $request)
    {
        $code = $request->query('code');

        if (! $code) {
            return redirect('/login?error=no_code');
        }

        $response = Http::post('https://github.com/login/oauth/access_token', [
            'client_id' => config('services.github.client_id'),
            'client_secret' => config('services.github.client_secret'),
            'code' => $code,
        ]);

        parse_str($response->body(), $data);

        if (! isset($data['access_token'])) {
            return redirect('/login?error=no_token');
        }

        $userResponse = Http::withHeaders([
            'Authorization' => 'Bearer '.$data['access_token'],
        ])->get('https://api.github.com/user');

        $githubUser = $userResponse->json();

        $binding = UserOAuthBinding::where('provider', 'github')
            ->where('provider_user_id', (string) $githubUser['id'])
            ->first();

        if ($binding) {
            $user = $binding->user;
        } else {
            $user = User::create([
                'username' => $githubUser['login'] ?? 'github_user_'.$githubUser['id'],
                'email' => $githubUser['email'] ?? '',
                'password' => Hash::make(Str::random(32)),
                'github_id' => (string) $githubUser['id'],
            ]);

            UserOAuthBinding::create([
                'user_id' => $user->id,
                'provider' => 'github',
                'provider_user_id' => (string) $githubUser['id'],
                'email' => $githubUser['email'] ?? '',
                'username' => $githubUser['login'],
                'avatar' => $githubUser['avatar_url'] ?? '',
            ]);
        }

        Auth::login($user);

        return redirect('/dashboard');
    }

    public function redirectToDiscord(Request $request)
    {
        $clientId = config('services.discord.client_id');
        $redirectUri = config('services.discord.redirect');
        $url = "https://discord.com/api/oauth2/authorize?client_id={$clientId}&redirect_uri=".urlencode($redirectUri).'&response_type=code&scope=identify%20email';

        return redirect($url);
    }

    public function handleDiscordCallback(Request $request)
    {
        $code = $request->query('code');

        if (! $code) {
            return redirect('/login?error=no_code');
        }

        $response = Http::post('https://discord.com/api/oauth2/token', [
            'client_id' => config('services.discord.client_id'),
            'client_secret' => config('services.discord.client_secret'),
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => config('services.discord.redirect'),
        ]);

        $data = $response->json();

        if (! isset($data['access_token'])) {
            return redirect('/login?error=no_token');
        }

        $userResponse = Http::withHeaders([
            'Authorization' => 'Bearer '.$data['access_token'],
        ])->get('https://discord.com/api/users/@me');

        $discordUser = $userResponse->json();

        $binding = UserOAuthBinding::where('provider', 'discord')
            ->where('provider_user_id', (string) $discordUser['id'])
            ->first();

        if ($binding) {
            $user = $binding->user;
        } else {
            $user = User::create([
                'username' => $discordUser['username'].'#'.$discordUser['discriminator'],
                'email' => '',
                'password' => Hash::make(Str::random(32)),
                'discord_id' => (string) $discordUser['id'],
            ]);

            UserOAuthBinding::create([
                'user_id' => $user->id,
                'provider' => 'discord',
                'provider_user_id' => (string) $discordUser['id'],
                'username' => $discordUser['username'],
            ]);
        }

        Auth::login($user);

        return redirect('/dashboard');
    }

    public function redirectToOIDC(Request $request)
    {
        $state = Str::random(64);
        session(['oidc_state' => $state]);

        $clientId = config('services.oidc.client_id');
        $redirectUri = config('services.oidc.redirect');

        $authUrl = config('services.oidc.auth_url').'?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid profile email',
            'state' => $state,
        ]);

        return redirect($authUrl);
    }

    public function handleOIDCCallback(Request $request)
    {
        $code = $request->query('code');

        if (! $code) {
            return redirect('/login?error=no_code');
        }

        $response = Http::post(config('services.oidc.token_url'), [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => config('services.oidc.client_id'),
            'client_secret' => config('services.oidc.client_secret'),
            'redirect_uri' => config('services.oidc.redirect'),
        ]);

        $data = $response->json();

        if (! isset($data['access_token'])) {
            return redirect('/login?error=no_token');
        }

        $userResponse = Http::withHeaders([
            'Authorization' => 'Bearer '.$data['access_token'],
        ])->get(config('services.oidc.userinfo_url'));

        $oidcUser = $userResponse->json();

        $binding = UserOAuthBinding::where('provider', 'oidc')
            ->where('provider_user_id', $oidcUser['sub'])
            ->first();

        if ($binding) {
            $user = $binding->user;
        } else {
            $user = User::create([
                'username' => $oidcUser['name'] ?? $oidcUser['preferred_username'] ?? 'oidc_user',
                'email' => $oidcUser['email'] ?? '',
                'password' => Hash::make(Str::random(32)),
                'oidc_id' => $oidcUser['sub'],
            ]);

            UserOAuthBinding::create([
                'user_id' => $user->id,
                'provider' => 'oidc',
                'provider_user_id' => $oidcUser['sub'],
                'email' => $oidcUser['email'] ?? '',
            ]);
        }

        Auth::login($user);

        return redirect('/dashboard');
    }

    public function redirectToLinuxDO(Request $request)
    {
        return $this->redirectToOIDC($request);
    }

    public function handleLinuxDOCallback(Request $request)
    {
        return $this->handleOIDCCallback($request);
    }

    public function redirectToWeChat(Request $request)
    {
        $appId = config('services.wechat.app_id');
        $redirectUri = config('services.wechat.redirect');

        $url = "https://open.weixin.qq.com/connect/qrconnect?appid={$appId}&redirect_uri=".urlencode($redirectUri).'&response_type=code&scope=snsapi_login#wechat_redirect';

        return redirect($url);
    }

    public function handleWeChatCallback(Request $request)
    {
        return redirect('/login?error=wechat_not_implemented');
    }

    public function bindWeChat(Request $request)
    {
        return response()->json(['success' => false, 'message' => __('Not implemented')]);
    }

    public function redirectToTelegram(Request $request)
    {
        $botToken = config('services.telegram.bot_token');

        return redirect("https://t.me/{$botToken}?start=auth");
    }

    public function startTelegramBind(Request $request)
    {
        $flowToken = Str::random(64);

        AuthFlow::create([
            'flow_token' => $flowToken,
            'action' => 'telegram_bind',
            'payload' => ['user_id' => $request->user()->id],
            'expires_at' => now()->addMinutes(10),
        ]);

        return response()->json(['success' => true, 'data' => ['flow_token' => $flowToken]]);
    }

    public function finishTelegramBind(string $flowToken)
    {
        $authFlow = AuthFlow::where('flow_token', $flowToken)->first();

        if (! $authFlow || $authFlow->expires_at->isPast()) {
            return redirect('/settings?error=expired');
        }

        $authFlow->delete();

        return redirect('/settings?success=bound');
    }

    public function bindEmail(Request $request)
    {
        $request->validate(['email' => 'required|email|unique:users,email']);

        $user = $request->user();
        $user->email = $request->input('email');
        $user->save();

        return response()->json(['success' => true, 'message' => __('Email bound successfully')]);
    }

    public function listBindings(Request $request)
    {
        $bindings = $request->user()->oauthBindings;

        return response()->json(['success' => true, 'data' => $bindings]);
    }

    public function unbind(Request $request, string $providerId)
    {
        $binding = $request->user()->oauthBindings()
            ->where('provider', $providerId)
            ->first();

        if ($binding) {
            $binding->delete();

            $user = $request->user();
            if ($providerId === 'github') {
                $user->github_id = '';
            }
            if ($providerId === 'discord') {
                $user->discord_id = '';
            }
            if ($providerId === 'wechat') {
                $user->wechat_id = '';
            }
            if ($providerId === 'telegram') {
                $user->telegram_id = '';
            }
            if ($providerId === 'oidc') {
                $user->oidc_id = '';
            }
            if ($providerId === 'linuxdo') {
                $user->linuxdo_id = '';
            }
            $user->save();
        }

        return response()->json(['success' => true, 'message' => __('Unbound successfully')]);
    }

    public function discovery(Request $request)
    {
        $url = $request->input('url');

        if (! $url) {
            return response()->json(['success' => false, 'message' => __('URL required')]);
        }

        try {
            $response = Http::get($url.'/.well-known/openid-configuration');

            return response()->json(['success' => true, 'data' => $response->json()]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => __('Failed to fetch')]);
        }
    }

    public function listCustomProviders()
    {
        return response()->json(['success' => true, 'data' => CustomOAuthProvider::all()]);
    }

    public function showCustomProvider(int $id)
    {
        return response()->json(['success' => true, 'data' => CustomOAuthProvider::findOrFail($id)]);
    }

    public function createCustomProvider(Request $request)
    {
        $request->validate(['name' => 'required', 'client_id' => 'required', 'client_secret' => 'required']);

        $provider = CustomOAuthProvider::create($request->only([
            'name', 'client_id', 'client_secret', 'scopes',
            'authorize_url', 'token_url', 'userinfo_url', 'well_known_url', 'icon',
        ]));

        return response()->json(['success' => true, 'data' => $provider]);
    }

    public function updateCustomProvider(Request $request, int $id)
    {
        $provider = CustomOAuthProvider::findOrFail($id);
        $provider->update($request->only([
            'name', 'client_id', 'client_secret', 'scopes',
            'authorize_url', 'token_url', 'userinfo_url', 'well_known_url', 'icon',
        ]));

        return response()->json(['success' => true, 'data' => $provider]);
    }

    public function deleteCustomProvider(int $id)
    {
        CustomOAuthProvider::findOrFail($id)->delete();

        return response()->json(['success' => true, 'message' => __('Deleted')]);
    }

    public function adminListBindings(int $userId)
    {
        return response()->json(['success' => true, 'data' => UserOAuthBinding::where('user_id', $userId)->get()]);
    }

    public function adminUnbind(Request $request, int $userId, string $providerId)
    {
        UserOAuthBinding::where('user_id', $userId)->where('provider', $providerId)->delete();

        return response()->json(['success' => true, 'message' => __('Unbound')]);
    }

    public function adminClearBindings(Request $request, int $userId, string $bindingType)
    {
        UserOAuthBinding::where('user_id', $userId)->where('provider', $bindingType)->delete();

        return response()->json(['success' => true, 'message' => __('Cleared')]);
    }
}
