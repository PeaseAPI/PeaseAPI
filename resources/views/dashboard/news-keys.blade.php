@extends('layouts.dashboard')
@section('title', '新闻/搜索 API Key')

@section('content')
<div class="max-w-3xl mx-auto space-y-6">
    @php
    $inputCls = 'w-full h-10 px-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none transition text-sm bg-white';
    @endphp

    <!-- 协议地址说明 -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">API 协议地址</h3>
        <p class="text-sm text-gray-500 mb-4">以下为各服务的 API 协议地址，可在支持 OpenAI 兼容格式的客户端中使用。</p>
        <div class="space-y-3">
            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 bg-green-100 text-green-700 rounded-lg flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-robot text-sm"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-900">OpenAI 兼容</p>
                        <p class="text-xs text-gray-500">Chat / Embeddings / Images 等</p>
                    </div>
                </div>
                <code class="text-xs bg-white border border-gray-200 rounded px-2 py-1 select-all text-primary-600 font-mono">{{ url('/v1') }}</code>
            </div>
            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 bg-orange-100 text-orange-700 rounded-lg flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-brain text-sm"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-900">Anthropic Claude</p>
                        <p class="text-xs text-gray-500">Claude Messages API</p>
                    </div>
                </div>
                <code class="text-xs bg-white border border-gray-200 rounded px-2 py-1 select-all text-primary-600 font-mono">{{ url('/v1') }}</code>
            </div>
            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 bg-blue-100 text-blue-700 rounded-lg flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-newspaper text-sm"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-900">__News__</p>
                        <p class="text-xs text-gray-500">新闻搜索 API</p>
                    </div>
                </div>
                <code class="text-xs bg-white border border-gray-200 rounded px-2 py-1 select-all text-primary-600 font-mono">{{ url('/news') }}</code>
            </div>
            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 bg-purple-100 text-purple-700 rounded-lg flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-search text-sm"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-900">__Search__</p>
                        <p class="text-xs text-gray-500">网页搜索 API</p>
                    </div>
                </div>
                <code class="text-xs bg-white border border-gray-200 rounded px-2 py-1 select-all text-primary-600 font-mono">{{ url('/search') }}</code>
            </div>
        </div>

    <!-- 新闻搜索 Provider Key -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-1">新闻搜索 API Key</h3>
        <p class="text-sm text-gray-500 mb-4">配置你自己的新闻 / 搜索 Provider API Key，留空则使用系统默认渠道。</p>
        <form id="newsKeysForm" class="space-y-4">
            @csrf
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Google News Key</label>
                <div class="relative">
                    <input type="password" name="news_google_key" id="news_google_key" class="{{ $inputCls }} pr-10" placeholder="AIza..." autocomplete="off">
                    <button type="button" onclick="toggleKeyVisibility('news_google_key')" class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                        <i class="fas fa-eye text-sm"></i>
                    </button>
                </div>
                <p class="text-xs text-gray-400 mt-1">Google Custom Search JSON API 的密钥</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">NewsAPI Key</label>
                <div class="relative">
                    <input type="password" name="news_newsapi_key" id="news_newsapi_key" class="{{ $inputCls }} pr-10" placeholder="abc123..." autocomplete="off">
                    <button type="button" onclick="toggleKeyVisibility('news_newsapi_key')" class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                        <i class="fas fa-eye text-sm"></i>
                    </button>
                </div>
                <p class="text-xs text-gray-400 mt-1">NewsAPI.org 的 API Key</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Tavily Key</label>
                <div class="relative">
                    <input type="password" name="news_tavily_key" id="news_tavily_key" class="{{ $inputCls }} pr-10" placeholder="tvly-..." autocomplete="off">
                    <button type="button" onclick="toggleKeyVisibility('news_tavily_key')" class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                        <i class="fas fa-eye text-sm"></i>
                    </button>
                </div>
                <p class="text-xs text-gray-400 mt-1">Tavily Search API 的密钥</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Exa Key</label>
                <div class="relative">
                    <input type="password" name="news_exa_key" id="news_exa_key" class="{{ $inputCls }} pr-10" placeholder="exa-..." autocomplete="off">
                    <button type="button" onclick="toggleKeyVisibility('news_exa_key')" class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                        <i class="fas fa-eye text-sm"></i>
                    </button>
                </div>
                <p class="text-xs text-gray-400 mt-1">Exa Search API 的密钥</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Brave Search Key</label>
                <div class="relative">
                    <input type="password" name="news_brave_key" id="news_brave_key" class="{{ $inputCls }} pr-10" placeholder="BSA..." autocomplete="off">
                    <button type="button" onclick="toggleKeyVisibility('news_brave_key')" class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                        <i class="fas fa-eye text-sm"></i>
                    </button>
                </div>
                <p class="text-xs text-gray-400 mt-1">Brave Search API 的密钥</p>
            </div>
            <div class="flex justify-end">
                <button type="submit" id="saveBtn" class="px-5 py-2.5 bg-primary-600 text-white rounded-lg hover:bg-primary-700 transition font-medium text-sm">保存 Key</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
// 加载已有的 key（脱敏后）
async function loadNewsKeys() {
    try {
        const res = await fetch('/web-api/me', {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' }
        });
        const data = await res.json();
        const masked = data.news_keys_masked || {};
        const fields = ['news_google_key', 'news_newsapi_key', 'news_tavily_key', 'news_exa_key', 'news_brave_key'];
        fields.forEach(f => {
            const el = document.getElementById(f);
            if (el && masked[f]) {
                el.value = masked[f];
                el.dataset.masked = 'true';
            }
        });
    } catch (e) {
        console.error('加载 News Key 失败', e);
    }
}

// 切换密码可见性
function toggleKeyVisibility(fieldId) {
    const el = document.getElementById(fieldId);
    if (!el) return;
    const icon = el.parentElement.querySelector('i');
    if (el.type === 'password') {
        el.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        el.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}

// 点击脱敏字段时清空，方便输入新值
document.querySelectorAll('#newsKeysForm input').forEach(el => {
    el.addEventListener('focus', function() {
        if (this.dataset.masked === 'true') {
            this.value = '';
            this.dataset.masked = 'false';
        }
        });
});

// 保存
document.getElementById('newsKeysForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('saveBtn');
    btn.disabled = true;
    btn.textContent = '保存中...';

    const fields = ['news_google_key', 'news_newsapi_key', 'news_tavily_key', 'news_exa_key', 'news_brave_key'];
    const payload = {};
    fields.forEach(f => {
        const el = document.getElementById(f);
        const val = el ? el.value.trim() : '';
        if (val && el.dataset.masked !== 'true') {
            payload[f] = val;
        } else if (val === '' && el) {
            payload[f] = '';
        }
    });

    try {
        const res = await fetch('/web-api/news-keys', {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json'
            },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (res.ok) {
            alert('保存成功');
            const masked = data.news_keys_masked || {};
            fields.forEach(f => {
                const el = document.getElementById(f);
                if (el && masked[f]) {
                    el.value = masked[f];
                    el.dataset.masked = 'true';
                    el.type = 'password';
                } else if (el) {
                    el.value = '';
                    el.dataset.masked = 'false';
                }
            });
        } else {
            alert('保存失败：' + (data.message || JSON.stringify(data)));
        }
    } catch (err) {
        alert('请求出错：' + err.message);
    } finally {
        btn.disabled = false;
        btn.textContent = '保存 Key';
    }
});

loadNewsKeys();
</script>
@endpush
