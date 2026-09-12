<?php

declare(strict_types=1);

/**
 * R16 QA：新闻/搜索四源（Google CSE / NewsAPI / Tavily / Exa）上游连通性 + 全链路自检
 *
 * Part A【上游直连】验证 env 中 4 个 API key 的真实有效性（生产可安全执行，只读上游）：
 *   - Google CSE 无 cx 时以「HTTP 400 缺 cx 参数」证明 key 认证通过（403 = key 无效）
 *   - 其余三源直接以搜索请求验证 200/401
 *   - 连接失败时自动回退 PEASE_NEWS_PROXY 代理重试（本地开发访问 Google 需代理）
 *
 * Part B【全链路】事务内建渠道/用户/令牌 → 直调 NewsController::search（attributes
 * 口径对齐 TokenAuth）→ 断言归一化结果、计费日志（model_name=news:*、other 审计）、
 * 失败退款 → 回滚零残留。
 *
 * key 从 env 读取：PEASE_NEWS_GOOGLE_KEY / PEASE_NEWS_NEWSAPI_KEY /
 * PEASE_NEWS_TAVILY_KEY / PEASE_NEWS_EXA_KEY（缺失则该源记 SKIP）
 */
require __DIR__.'/../vendor/autoload.php';

use App\Http\Controllers\NewsController;
use App\Models\Channel;
use App\Models\Log;
use App\Models\Token;
use App\Models\User;
use App\Services\NewsService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$fail = 0;
$skip = 0;

function check(string $name, bool $cond, string $extra = ''): void
{
    global $fail;
    echo ($cond ? '  ✓ ' : '  ✗ ').$name.($extra !== '' ? '（'.$extra.'）' : '')."\n";
    if (! $cond) {
        $fail++;
    }
}

function skipped(string $name, string $reason): void
{
    global $skip;
    echo '  - '.$name."（SKIP：{$reason}）\n";
    $skip++;
}

/** 带代理回退的 HTTP GET（连接异常时用代理重试一次） */
function httpGetRobust(string $url, array $params = [], array $headers = []): array
{
    try {
        $resp = Http::withHeaders($headers)->timeout(20)->get($url, $params);
    } catch (Throwable $e) {
        $proxy = (string) env('PEASE_NEWS_PROXY', '');
        if ($proxy === '') {
            throw $e;
        }
        $resp = Http::withHeaders($headers)->withOptions(['proxy' => $proxy])->timeout(20)->get($url, $params);
    }

    return ['status' => $resp->status(), 'body' => (string) $resp->body(), 'json' => $resp->json() ?? []];
}

/** 带代理回退的 HTTP POST */
function httpPostRobust(string $url, array $body = [], array $headers = []): array
{
    try {
        $resp = Http::withHeaders($headers)->timeout(20)->post($url, $body);
    } catch (Throwable $e) {
        $proxy = (string) env('PEASE_NEWS_PROXY', '');
        if ($proxy === '') {
            throw $e;
        }
        $resp = Http::withHeaders($headers)->withOptions(['proxy' => $proxy])->timeout(20)->post($url, $body);
    }

    return ['status' => $resp->status(), 'body' => (string) $resp->body(), 'json' => $resp->json() ?? []];
}

echo "========== Part A：上游直连 key 有效性 ==========\n";

$googleKey = (string) env('PEASE_NEWS_GOOGLE_KEY', '');
$newsapiKey = (string) env('PEASE_NEWS_NEWSAPI_KEY', '');
$tavilyKey = (string) env('PEASE_NEWS_TAVILY_KEY', '');
$exaKey = (string) env('PEASE_NEWS_EXA_KEY', '');

// [A1] Google CSE：key+q（无 cx）→ 400 缺 cx = key 认证通过；403 = key 无效
if ($googleKey === '') {
    skipped('A1 Google CSE key', 'PEASE_NEWS_GOOGLE_KEY 未配置');
} else {
    $r = httpGetRobust('https://www.googleapis.com/customsearch/v1', ['key' => $googleKey, 'q' => 'laravel']);
    $body = $r['body'];
    $invalidKey = stripos($body, 'API key not valid') !== false || $r['status'] === 403;
    $missingCx = $r['status'] === 400 && stripos($body, 'cx') !== false && ! $invalidKey;
    check('A1 Google CSE key 有效性', $missingCx || $r['status'] === 200,
        $invalidKey ? 'Google 明确拒绝：API key not valid（key 本身无效/被禁用）' : 'HTTP '.$r['status'].' '.mb_substr($body, 0, 120));

}

// [A2] NewsAPI：top-headlines q=ai
if ($newsapiKey === '') {
    skipped('A2 NewsAPI key', 'PEASE_NEWS_NEWSAPI_KEY 未配置');
} else {
    $r = httpGetRobust('https://newsapi.org/v2/top-headlines', ['apiKey' => $newsapiKey, 'q' => 'ai', 'pageSize' => 1]);
    $ok = $r['status'] === 200 && ($r['json']['status'] ?? '') === 'ok';
    check('A2 NewsAPI key 有效性', $ok, 'HTTP '.$r['status'].' '.mb_substr($r['body'], 0, 120));
    if ($ok) {
        check('A2b NewsAPI 返回结构（articles/totalResults）', isset($r['json']['articles'], $r['json']['totalResults']));
    }
}

// [A3] Tavily：POST /search
if ($tavilyKey === '') {
    skipped('A3 Tavily key', 'PEASE_NEWS_TAVILY_KEY 未配置');
} else {
    $r = httpPostRobust('https://api.tavily.com/search', ['api_key' => $tavilyKey, 'query' => 'laravel framework', 'max_results' => 1]);
    $ok = $r['status'] === 200 && isset($r['json']['results']);
    check('A3 Tavily key 有效性', $ok, 'HTTP '.$r['status'].' '.mb_substr($r['body'], 0, 120));
}

// [A4] Exa：POST /search（x-api-key）
if ($exaKey === '') {
    skipped('A4 Exa key', 'PEASE_NEWS_EXA_KEY 未配置');
} else {
    $r = httpPostRobust('https://api.exa.ai/search', ['query' => 'laravel framework', 'numResults' => 1], ['x-api-key' => $exaKey]);
    $ok = $r['status'] === 200 && isset($r['json']['results']);
    check('A4 Exa key 有效性', $ok, 'HTTP '.$r['status'].' '.mb_substr($r['body'], 0, 120));
}

echo "\n========== Part B：全链路（事务内，回滚零残留） ==========\n";

DB::beginTransaction();
try {
    $user = User::create([
        'username' => 'qa_news_'.substr(md5((string) microtime(true)), 0, 8),
        'email' => 'qa_news_'.substr(md5((string) microtime(true)), 0, 8).'@test.local',
        'password' => password_hash('Qa#12345', PASSWORD_DEFAULT),
        'display_name' => 'qa_news',
        'role' => 1,
        'status' => 1,
        'quota' => 1000,
        'used_quota' => 0,
        'request_count' => 0,
        'group' => 'default',
        'aff_code' => 'QA'.random_int(100000, 999999),
        'created_at' => time(),
    ]);

    $token = Token::create([
        'user_id' => $user->id,
        'name' => 'qa_news_token',
        'key' => 'qa'.bin2hex(random_bytes(16)),
        'status' => 1,
        'created_time' => time(),
        'accessed_time' => time(),
        'expired_time' => -1,
        'remain_quota' => 100,
        'unlimited_quota' => false,
        'used_quota' => 0,
    ]);

    $mkChannel = function (int $type, string $name, string $key, array $setting = []) {
        return Channel::create([
            'type' => $type,
            'name' => $name,
            'key' => $key,
            'status' => 1,
            'group' => 'default',
            'priority' => 0,
            'weight' => 0,
            'models' => '',
            'setting' => $setting ?: null,
            'created_time' => time(),
        ]);
    };

    $chNewsapi = $mkChannel(81, 'qa-newsapi', $newsapiKey !== '' ? $newsapiKey : 'placeholder');
    $chTavily = $mkChannel(82, 'qa-tavily', $tavilyKey !== '' ? $tavilyKey : 'placeholder');
    $chExa = $mkChannel(83, 'qa-exa', $exaKey !== '' ? $exaKey : 'placeholder');
    // Google 渠道带无效 cx：验证上游错误被正确包装（key 有效性已在 Part A 验证）
    $chGoogle = $mkChannel(80, 'qa-google-cse', $googleKey !== '' ? $googleKey : 'placeholder', ['cx' => 'qa_missing_cx']);

    $controller = new NewsController(app(NewsService::class));

    $callSearch = function (array $params) use ($controller, $user, $token): array {
        $req = Request::create('/news/search', 'POST', $params);
        $req->attributes->set('token', $token);
        $req->attributes->set('api_user', $user);
        $req->attributes->set('user_id', $user->id);
        $req->attributes->set('token_id', $token->id);
        $req->attributes->set('user_group', 'default');

        $resp = $controller->search($req);

        return ['status' => $resp->getStatusCode(), 'body' => json_decode((string) $resp->getContent(), true) ?? []];
    };

    // [B1] NewsAPI 全链路：news 模式 auto（无 provider 参数 → 命中 news_api 渠道）
    if ($newsapiKey !== '') {
        $r = $callSearch(['query' => 'artificial intelligence', 'max_results' => 3]);
        $ok = $r['status'] === 200 && ($r['body']['success'] ?? false) && ($r['body']['data']['provider'] ?? '') === 'news_api'
            && count($r['body']['data']['articles'] ?? []) > 0;
        check('B1 /news/search auto→news_api（HTTP 200 + articles>0）', $ok, 'HTTP '.$r['status'].' '.mb_substr(json_encode($r['body'], JSON_UNESCAPED_UNICODE), 0, 150));
    } else {
        skipped('B1 /news/search auto→news_api', 'NewsAPI key 未配置');
    }

    // [B2] Tavily 全链路（指定 provider，配额 2）
    if ($tavilyKey !== '') {
        $r = $callSearch(['query' => 'laravel 11 release', 'max_results' => 3, 'provider' => 'tavily']);
        $ok = $r['status'] === 200 && ($r['body']['data']['provider'] ?? '') === 'tavily' && count($r['body']['data']['articles'] ?? []) > 0;
        check('B2 provider=tavily（HTTP 200 + results>0）', $ok, 'HTTP '.$r['status'].' '.mb_substr(json_encode($r['body'], JSON_UNESCAPED_UNICODE), 0, 150));
        $tavilyQuota = (int) (Log::where('token_id', $token->id)->where('model_name', 'news:tavily')->value('quota') ?? 0);
        check('B2b Tavily 计费日志 quota=2', $tavilyQuota === 2, "actual={$tavilyQuota}");

    } else {
        skipped('B2 provider=tavily', 'Tavily key 未配置');
    }

    // [B3] Exa 全链路（指定 provider，配额 2）
    if ($exaKey !== '') {
        $r = $callSearch(['query' => 'php framework benchmark', 'max_results' => 3, 'provider' => 'exa']);
        $ok = $r['status'] === 200 && ($r['body']['data']['provider'] ?? '') === 'exa' && count($r['body']['data']['articles'] ?? []) > 0;
        check('B3 provider=exa（HTTP 200 + results>0）', $ok, 'HTTP '.$r['status'].' '.mb_substr(json_encode($r['body'], JSON_UNESCAPED_UNICODE), 0, 150));
    } else {
        skipped('B3 provider=exa', 'Exa key 未配置');
    }

    // [B4] Google CSE：cx 无效 → 上游错误包装为 502 upstream_error（不 500 崩、配额退款）
    if ($googleKey !== '') {
        $r = $callSearch(['query' => 'laravel', 'max_results' => 3, 'provider' => 'google_custom_search']);
        $ok = $r['status'] === 502 && ($r['body']['success'] ?? true) === false && ($r['body']['message'] ?? '') !== '';
        check('B4 provider=google（cx 无效 → 502 upstream_error 包装）', $ok, 'HTTP '.$r['status'].' '.mb_substr(json_encode($r['body'], JSON_UNESCAPED_UNICODE), 0, 150));
    } else {
        skipped('B4 provider=google', 'Google key 未配置');
    }

    // [B5] 计费日志：model_name=news:*、other 审计、channel/token 冗余
    $expectedSuccess = ($newsapiKey !== '' ? 1 : 0) + ($tavilyKey !== '' ? 1 : 0) + ($exaKey !== '' ? 1 : 0);
    $newsLogs = Log::where('token_id', $token->id)->get()->filter(fn ($l) => str_starts_with((string) $l->model_name, 'news:'));
    check('B5 计费日志条数 = 成功请求数', $newsLogs->count() === $expectedSuccess, 'model_name=news:* 共 '.$newsLogs->count().' 条');
    if ($tavilyKey !== '' && $newsLogs->count() > 0) {
        $tavilyLog = $newsLogs->firstWhere('model_name', 'news:tavily');
        if ($tavilyLog) {
            $other = json_decode((string) $tavilyLog->other, true) ?? [];
            check('B5b 日志审计明细（other.provider=tavily + query + results_count）',
                ($other['provider'] ?? '') === 'tavily' && isset($other['query'], $other['results_count']));
            check('B5c 日志冗余列（channel_name=qa-tavily + token_name）',
                $tavilyLog->channel_name === 'qa-tavily' && $tavilyLog->token_name === 'qa_news_token',
                'actual channel_name=['.$tavilyLog->channel_name.'] token_name=['.$tavilyLog->token_name.']');

        } else {
            check('B5b 日志审计明细', false, '未找到 model_name=news:tavily 日志行');
        }
    }

    // [B6] 失败退款：失败请求配额全额退回（remain = 100 - 成功日志 quota 之和）
    $expectedQuota = (int) $newsLogs->sum('quota');
    $token->refresh();
    check('B6 失败请求配额全额退款（remain_quota='.(100 - $expectedQuota).'）', (int) $token->remain_quota === 100 - $expectedQuota, 'actual='.$token->remain_quota.' 扣减='.$expectedQuota);

    // [B7] 回滚零残留
    DB::rollBack();
    $left = Channel::whereIn('id', [$chNewsapi->id, $chTavily->id, $chExa->id, $chGoogle->id])->count()
        + User::where('id', $user->id)->count() + Token::where('id', $token->id)->count();
    check('B7 回滚零残留（渠道/用户/令牌全清）', $left === 0, "left={$left}");
} catch (Throwable $e) {
    DB::rollBack();
    $fail++;
    echo '  ✗ 异常 → '.get_class($e).': '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine()."\n";
}

echo "\n========== 结果 ==========\n";
echo "SKIP={$skip} FAIL={$fail}\n";
echo $fail === 0 ? "✅ 新闻/搜索四源自检全部通过\n" : "❌ 存在失败项\n";
exit($fail === 0 ? 0 : 1);
