@extends('layouts.dashboard')
@section('title', '仪表盘')

@section('content')
<!-- Stats Cards -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-500 mb-1">可用余额</p>
                <p class="text-2xl font-bold text-gray-900" id="balance">-</p>
            </div>
            <div class="w-12 h-12 bg-green-100 text-green-600 rounded-lg flex items-center justify-center">
                <i class="fas fa-wallet text-xl"></i>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-500 mb-1">总配额</p>
                <p class="text-2xl font-bold text-gray-900" id="totalQuota">-</p>
            </div>
            <div class="w-12 h-12 bg-blue-100 text-blue-600 rounded-lg flex items-center justify-center">
                <i class="fas fa-coins text-xl"></i>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-500 mb-1">已使用</p>
                <p class="text-2xl font-bold text-gray-900" id="usedQuota">-</p>
            </div>
            <div class="w-12 h-12 bg-orange-100 text-orange-600 rounded-lg flex items-center justify-center">
                <i class="fas fa-chart-line text-xl"></i>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-500 mb-1">令牌数量</p>
                <p class="text-2xl font-bold text-gray-900" id="tokenCount">-</p>
            </div>
            <div class="w-12 h-12 bg-purple-100 text-purple-600 rounded-lg flex items-center justify-center">
                <i class="fas fa-key text-xl"></i>
            </div>
        </div>
    </div>
</div>

<!-- API Protocol Endpoints -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
    <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-semibold text-gray-900">API 协议地址</h3>
        <span class="text-xs text-gray-400">可在支持 OpenAI 兼容格式的客户端中使用</span>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
        <div class="group relative flex items-center gap-3 p-3.5 bg-gradient-to-br from-green-50 to-emerald-50 border border-green-100 rounded-xl hover:shadow-md transition-all duration-200">
            <div class="w-10 h-10 bg-green-500 text-white rounded-lg flex items-center justify-center flex-shrink-0 shadow-sm">
                <i class="fas fa-robot text-sm"></i>
            </div>
            <div class="min-w-0 flex-1">
                <p class="text-xs font-semibold text-green-800 mb-0.5">OpenAI 兼容</p>
                <code class="text-[11px] bg-white/70 border border-green-200 rounded px-1.5 py-0.5 select-all text-green-700 font-mono block truncate cursor-pointer" onclick="copyToClipboard(this, '{{ url('/v1') }}')">{{ url('/v1') }}</code>
            </div>
            <i class="fas fa-copy text-green-400 opacity-0 group-hover:opacity-100 transition text-xs absolute top-2 right-2"></i>
        </div>
        <div class="group relative flex items-center gap-3 p-3.5 bg-gradient-to-br from-orange-50 to-amber-50 border border-orange-100 rounded-xl hover:shadow-md transition-all duration-200">
            <div class="w-10 h-10 bg-orange-500 text-white rounded-lg flex items-center justify-center flex-shrink-0 shadow-sm">
                <i class="fas fa-brain text-sm"></i>
            </div>
            <div class="min-w-0 flex-1">
                <p class="text-xs font-semibold text-orange-800 mb-0.5">Anthropic Claude</p>
                <code class="text-[11px] bg-white/70 border border-orange-200 rounded px-1.5 py-0.5 select-all text-orange-700 font-mono block truncate cursor-pointer" onclick="copyToClipboard(this, '{{ url('/v1') }}')">{{ url('/v1') }}</code>
            </div>
            <i class="fas fa-copy text-orange-400 opacity-0 group-hover:opacity-100 transition text-xs absolute top-2 right-2"></i>
        </div>
        <div class="group relative flex items-center gap-3 p-3.5 bg-gradient-to-br from-blue-50 to-indigo-50 border border-blue-100 rounded-xl hover:shadow-md transition-all duration-200">
            <div class="w-10 h-10 bg-blue-500 text-white rounded-lg flex items-center justify-center flex-shrink-0 shadow-sm">
                <i class="fas fa-newspaper text-sm"></i>
            </div>
            <div class="min-w-0 flex-1">
                <p class="text-xs font-semibold text-blue-800 mb-0.5">__News__</p>
                <code class="text-[11px] bg-white/70 border border-blue-200 rounded px-1.5 py-0.5 select-all text-blue-700 font-mono block truncate cursor-pointer" onclick="copyToClipboard(this, '{{ url('/news') }}')">{{ url('/news') }}</code>
            </div>
            <i class="fas fa-copy text-blue-400 opacity-0 group-hover:opacity-100 transition text-xs absolute top-2 right-2"></i>
        </div>
        <div class="group relative flex items-center gap-3 p-3.5 bg-gradient-to-br from-purple-50 to-violet-50 border border-purple-100 rounded-xl hover:shadow-md transition-all duration-200">
            <div class="w-10 h-10 bg-purple-500 text-white rounded-lg flex items-center justify-center flex-shrink-0 shadow-sm">
                <i class="fas fa-search text-sm"></i>
            </div>
            <div class="min-w-0 flex-1">
                <p class="text-xs font-semibold text-purple-800 mb-0.5">__Search__</p>
                <code class="text-[11px] bg-white/70 border border-purple-200 rounded px-1.5 py-0.5 select-all text-purple-700 font-mono block truncate cursor-pointer" onclick="copyToClipboard(this, '{{ url('/search') }}')">{{ url('/search') }}</code>
            </div>
            <i class="fas fa-copy text-purple-400 opacity-0 group-hover:opacity-100 transition text-xs absolute top-2 right-2"></i>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
    <h3 class="text-lg font-semibold text-gray-900 mb-4">快速操作</h3>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <a href="{{ route('tokens.create') }}" class="flex flex-col items-center p-4 border border-gray-200 rounded-lg hover:border-primary-300 hover:bg-primary-50 transition">
            <div class="w-10 h-10 bg-primary-100 text-primary-600 rounded-full flex items-center justify-center mb-2">
                <i class="fas fa-plus"></i>
            </div>
            <span class="text-sm font-medium text-gray-700">创建令牌</span>
        </a>
        <a href="{{ route('redeem') }}" class="flex flex-col items-center p-4 border border-gray-200 rounded-lg hover:border-primary-300 hover:bg-primary-50 transition">
            <div class="w-10 h-10 bg-green-100 text-green-600 rounded-full flex items-center justify-center mb-2">
                <i class="fas fa-gift"></i>
            </div>
            <span class="text-sm font-medium text-gray-700">兑换码</span>
        </a>
        <a href="{{ route('user.logs') }}" class="flex flex-col items-center p-4 border border-gray-200 rounded-lg hover:border-primary-300 hover:bg-primary-50 transition">
            <div class="w-10 h-10 bg-blue-100 text-blue-600 rounded-full flex items-center justify-center mb-2">
                <i class="fas fa-list"></i>
            </div>
            <span class="text-sm font-medium text-gray-700">请求日志</span>
        </a>
        <a href="{{ route('profile') }}" class="flex flex-col items-center p-4 border border-gray-200 rounded-lg hover:border-primary-300 hover:bg-primary-50 transition">
            <div class="w-10 h-10 bg-purple-100 text-purple-600 rounded-full flex items-center justify-center mb-2">
                <i class="fas fa-user"></i>
            </div>
            <span class="text-sm font-medium text-gray-700">个人信息</span>
        </a>
    </div>
</div>

<!-- Recent Tokens -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
    <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-semibold text-gray-900">最近令牌</h3>
        <a href="{{ route('tokens') }}" class="text-sm text-primary-600 hover:text-primary-700">查看全部</a>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead>
                <tr class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider border-b border-gray-200">
                    <th class="pb-3">名称</th>
                    <th class="pb-3">配额</th>
                    <th class="pb-3">已用</th>
                    <th class="pb-3">状态</th>
                    <th class="pb-3">创建时间</th>
                </tr>
            </thead>
            <tbody id="recentTokens" class="text-sm">
                <tr><td colspan="5" class="py-4 text-center text-gray-500">加载中...</td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Recent Logs -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
    <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-semibold text-gray-900">最近请求</h3>
        <a href="{{ route('user.logs') }}" class="text-sm text-primary-600 hover:text-primary-700">查看全部</a>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead>
                <tr class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider border-b border-gray-200">
                    <th class="pb-3">时间</th>
                    <th class="pb-3">模型</th>
                    <th class="pb-3">渠道</th>
                    <th class="pb-3">状态</th>
                    <th class="pb-3">用量</th>
                </tr>
            </thead>
            <tbody id="recentLogs" class="text-sm">
                <tr><td colspan="5" class="py-4 text-center text-gray-500">加载中...</td></tr>
            </tbody>
        </table>
    </div>
</div>
@endsection

@push('scripts')
<script>
function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function copyToClipboard(el, text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(() => {
            const orig = el.textContent;
            el.textContent = '已复制!';
            setTimeout(() => { el.textContent = orig; }, 1200);
        });
    } else {
        // Fallback for non-HTTPS environments
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        try {
            document.execCommand('copy');
            const orig = el.textContent;
            el.textContent = '已复制!';
            setTimeout(() => { el.textContent = orig; }, 1200);
        } catch (e) {
            console.error('Copy failed', e);
        }
        document.body.removeChild(textarea);
    }
}

async function loadDashboard() {
    try {
        const [userRes, tokensRes, logsRes] = await Promise.all([
            fetch('/web-api/me'),
            fetch('/web-api/tokens?per_page=5'),
            fetch('/web-api/logs?per_page=5')
        ]);
        
        const userData = await userRes.json();
        const tokensData = await tokensRes.json();
        const logsData = await logsRes.json();
        
        // Update stats
        document.getElementById('balance').textContent = (userData.balance || 0).toLocaleString();
        document.getElementById('totalQuota').textContent = (userData.quota || 0).toLocaleString();
        document.getElementById('usedQuota').textContent = (userData.used_quota || 0).toLocaleString();
        document.getElementById('tokenCount').textContent = tokensData.total || 0;
        
        // Update tokens table
        if (tokensData.data && tokensData.data.length > 0) {
            document.getElementById('recentTokens').innerHTML = tokensData.data.map(token => `
                <tr class="border-b border-gray-100 hover:bg-gray-50">
                    <td class="py-3 font-medium">${escapeHtml(token.name || '-')}</td>
                    <td class="py-3">${token.unlimited_quota ? '无限' : (token.remain_quota || 0).toLocaleString()}</td>
                    <td class="py-3">${(token.quota_used || 0).toLocaleString()}</td>
                    <td class="py-3">
                        <span class="px-2 py-1 text-xs rounded-full ${token.status === 1 ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-700'}">
                            ${token.status === 1 ? '正常' : '禁用'}
                        </span>
                    </td>
                    <td class="py-3 text-gray-500">${token.created_at ? new Date(token.created_at * 1000).toLocaleDateString() : '-'}</td>
                </tr>
            `).join('');
        } else {
            document.getElementById('recentTokens').innerHTML = '<tr><td colspan="5" class="py-4 text-center text-gray-500">暂无令牌</td></tr>';
        }
        
        // Update logs table
        if (logsData.data && logsData.data.length > 0) {
            document.getElementById('recentLogs').innerHTML = logsData.data.map(log => `
                <tr class="border-b border-gray-100 hover:bg-gray-50">
                    <td class="py-3 text-gray-500">${log.created_at ? new Date(log.created_at * 1000).toLocaleString() : '-'}</td>
                    <td class="py-3 font-mono text-xs">${escapeHtml(log.model || '-')}</td>
                    <td class="py-3">${escapeHtml(log.channel_name || '-')}</td>
                    <td class="py-3">
                        <span class="px-2 py-1 text-xs rounded-full ${log.status === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'}">
                            ${log.status === 'success' ? '成功' : '失败'}
                        </span>
                    </td>
                    <td class="py-3">${(log.prompt_tokens || 0) + (log.completion_tokens || 0)}</td>
                </tr>
            `).join('');
        } else {
            document.getElementById('recentLogs').innerHTML = '<tr><td colspan="5" class="py-4 text-center text-gray-500">暂无日志</td></tr>';
        }
    } catch (e) {
        console.error('Load dashboard error:', e);
    }
}
loadDashboard();
</script>
@endpush