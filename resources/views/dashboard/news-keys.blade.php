@extends('layouts.dashboard')
@section('title', '中转 Key 设置')

@section('content')
<div class="max-w-3xl mx-auto">
    <div class="mb-6">
        <h2 class="text-xl font-bold text-gray-900">中转 Key 设置</h2>
        <p class="text-sm text-gray-500 mt-1">配置新闻搜索等服务的 API Key，保存后即可使用对应功能</p>
    </div>
        <form id="newsKeysForm" class="space-y-5">
        @csrf

        <!-- News Keys Section -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-4 bg-gradient-to-r from-blue-600 to-indigo-600">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 bg-white/20 rounded-lg flex items-center justify-center">
                        <i class="fas fa-newspaper text-white text-sm"></i>
                    </div>
                    <div>
                        <h3 class="text-base font-semibold text-white">新闻服务 Key</h3>
                        <p class="text-xs text-blue-100">配置后可使用新闻中转兼容协议工具 API</p>
                    </div>
                </div>
            </div>
            <div class="p-6 space-y-4">
                <div>
                    <label class="flex items-center gap-2 text-sm font-medium text-gray-700 mb-1.5">
                        <img src="https://www.google.com/favicon.ico" class="w-4 h-4 rounded" alt="" onerror="this.style.display='none'">
                        Google News Key
                    </label>
                    <div class="relative">
                        <input type="password" id="news_google_key" name="news_google_key"
                            class="w-full h-11 px-4 pr-10 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition text-sm bg-gray-50 hover:bg-white focus:bg-white"
                            placeholder="输入 Google News API Key" autocomplete="off">
                        <button type="button" onclick="toggleKeyVisibility('news_google_key')"
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 transition">
                            <i class="fas fa-eye text-sm"></i>
                        </button>
                    </div>
                </div>
                <div>
                    <label class="flex items-center gap-2 text-sm font-medium text-gray-700 mb-1.5">
                        <img src="https://newsapi.org/favicon.ico" class="w-4 h-4 rounded" alt="" onerror="this.style.display='none'">
                        NewsAPI Key
                    </label>
                    <div class="relative">
                        <input type="password" id="news_newsapi_key" name="news_newsapi_key"
                            class="w-full h-11 px-4 pr-10 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition text-sm bg-gray-50 hover:bg-white focus:bg-white"
                            placeholder="输入 NewsAPI Key" autocomplete="off">
                        <button type="button" onclick="toggleKeyVisibility('news_newsapi_key')"
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 transition">
                            <i class="fas fa-eye text-sm"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

                <!-- Search Keys Section -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-4 bg-gradient-to-r from-purple-600 to-violet-600">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 bg-white/20 rounded-lg flex items-center justify-center">
                        <i class="fas fa-search text-white text-sm"></i>
                    </div>
                    <div>
                        <h3 class="text-base font-semibold text-white">搜索服务 Key</h3>
                        <p class="text-xs text-purple-100">配置后可使用搜索中转兼容协议工具 API</p>
                    </div>
                </div>
            </div>
            <div class="p-6 space-y-4">
                <div>
                    <label class="flex items-center gap-2 text-sm font-medium text-gray-700 mb-1.5">
                        <img src="https://tavily.com/favicon.ico" class="w-4 h-4 rounded" alt="" onerror="this.style.display='none'">
                        Tavily Key
                    </label>
                    <div class="relative">
                        <input type="password" id="news_tavily_key" name="news_tavily_key"
                            class="w-full h-11 px-4 pr-10 border border-gray-200 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 outline-none transition text-sm bg-gray-50 hover:bg-white focus:bg-white"
                            placeholder="输入 Tavily API Key" autocomplete="off">
                        <button type="button" onclick="toggleKeyVisibility('news_tavily_key')"
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 transition">
                            <i class="fas fa-eye text-sm"></i>
                        </button>
                    </div>
                </div>
                <div>
                    <label class="flex items-center gap-2 text-sm font-medium text-gray-700 mb-1.5">
                        <img src="https://exa.ai/favicon.ico" class="w-4 h-4 rounded" alt="" onerror="this.style.display='none'">
                        Exa Key
                    </label>
                    <div class="relative">
                        <input type="password" id="news_exa_key" name="news_exa_key"
                            class="w-full h-11 px-4 pr-10 border border-gray-200 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 outline-none transition text-sm bg-gray-50 hover:bg-white focus:bg-white"
                            placeholder="输入 Exa API Key" autocomplete="off">
                        <button type="button" onclick="toggleKeyVisibility('news_exa_key')"
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 transition">
                            <i class="fas fa-eye text-sm"></i>
                        </button>
                    </div>
                </div>
                <div>
                    <label class="flex items-center gap-2 text-sm font-medium text-gray-700 mb-1.5">
                        <img src="https://brave.com/static-assets/images/brave-favicon.png" class="w-4 h-4 rounded" alt="" onerror="this.style.display='none'">
                        Brave Search Key
                    </label>
                    <div class="relative">
                        <input type="password" id="news_brave_key" name="news_brave_key"
                            class="w-full h-11 px-4 pr-10 border border-gray-200 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 outline-none transition text-sm bg-gray-50 hover:bg-white focus:bg-white"
                            placeholder="输入 Brave Search API Key" autocomplete="off">
                        <button type="button" onclick="toggleKeyVisibility('news_brave_key')"
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 transition">
                            <i class="fas fa-eye text-sm"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Save Button -->
        <div class="flex items-center justify-end gap-3 pt-2">
            <button type="submit" id="saveBtn"
                class="inline-flex items-center gap-2 px-6 py-2.5 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-lg shadow-sm hover:shadow transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed">
                <i class="fas fa-save text-xs"></i>
                保存配置
            </button>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
function showToast(msg, type) {
    var t = document.createElement('div');
    t.className = 'fixed top-4 right-4 z-50 px-4 py-3 rounded-lg shadow-lg text-sm font-medium transition-all duration-300 transform translate-x-full ' + (type === 'error' ? 'bg-red-500 text-white' : 'bg-green-500 text-white');
    t.textContent = msg;
    document.body.appendChild(t);
    requestAnimationFrame(function() { t.classList.remove('translate-x-full'); });
    setTimeout(function() { t.classList.add('translate-x-full'); setTimeout(function() { t.remove(); }, 300); }, 2500);
}

async function loadNewsKeys() {
    try {
        var res = await fetch('/web-api/me', { credentials: 'same-origin', headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
        if (res.status === 401) { window.location.href = '/login'; return; }
        var data = res.ok ? await res.json() : {};
        var masked = data.news_keys_masked || {};
        ['news_google_key','news_newsapi_key','news_tavily_key','news_exa_key','news_brave_key'].forEach(function(f) {
            var el = document.getElementById(f);
            if (el && masked[f]) { el.value = masked[f]; el.dataset.masked = 'true'; el.classList.add('bg-blue-50'); }
        });
    } catch (e) { console.error('加载 Key 失败', e); }
}

function toggleKeyVisibility(fieldId) {
    var el = document.getElementById(fieldId);
    if (!el) return;
    var btn = el.parentElement.querySelector('button i');
    if (el.type === 'password') { el.type = 'text'; btn.classList.replace('fa-eye','fa-eye-slash'); }
    else { el.type = 'password'; btn.classList.replace('fa-eye-slash','fa-eye'); }
}

document.querySelectorAll('#newsKeysForm input').forEach(function(el) {
    el.addEventListener('focus', function() {
        if (this.dataset.masked === 'true') { this.value = ''; this.dataset.masked = 'false'; this.classList.remove('bg-blue-50'); }
    });
});

document.getElementById('newsKeysForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    var btn = document.getElementById('saveBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin text-xs"></i> 保存中...';
    var fields = ['news_google_key','news_newsapi_key','news_tavily_key','news_exa_key','news_brave_key'];
    var payload = {};
    fields.forEach(function(f) {
        var el = document.getElementById(f);
        var val = el ? el.value.trim() : '';
        if (val && el.dataset.masked !== 'true') payload[f] = val;
        else if (val === '' && el) payload[f] = '';
    });
    try {
        var res = await fetch('/web-api/news-keys', { credentials: 'same-origin',
            method: 'PUT', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
            body: JSON.stringify(payload)
        });
        if (res.status === 401) { showToast('登录已过期，请重新登录', 'error'); window.location.href = '/login'; return; }
        var data = await res.json();
        if (res.ok) {
            showToast('保存成功');
            var masked = data.news_keys_masked || {};
            fields.forEach(function(f) {
                var el = document.getElementById(f);
                if (el && masked[f]) { el.value = masked[f]; el.dataset.masked = 'true'; el.type = 'password'; el.classList.add('bg-blue-50'); }
                else if (el) { el.value = ''; el.dataset.masked = 'false'; el.classList.remove('bg-blue-50'); }
            });
        } else { showToast('保存失败：' + (data.message || '未知错误'), 'error'); }
    } catch (err) { showToast('请求出错：' + err.message, 'error'); }
    finally { btn.disabled = false; btn.innerHTML = '<i class="fas fa-save text-xs"></i> 保存配置'; }
});

loadNewsKeys();
</script>
@endpush
