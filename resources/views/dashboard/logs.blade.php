@extends('layouts.dashboard')
@section('title', '请求日志')

@section('content')
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <div class="p-4 border-b border-gray-200 flex items-center gap-4">
        <input type="text" id="searchInput" placeholder="搜索模型或渠道..." 
            class="flex-1 px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary-500">
        <select id="statusFilter" class="px-4 py-2 border border-gray-300 rounded-lg text-sm">
            <option value="">全部状态</option>
            <option value="success">成功</option>
            <option value="error">失败</option>
        </select>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead>
                <tr class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider bg-gray-50">
                    <th class="px-6 py-3">时间</th>
                    <th class="px-6 py-3">令牌</th>
                    <th class="px-6 py-3">模型</th>
                    <th class="px-6 py-3">渠道</th>
                    <th class="px-6 py-3">状态</th>
                    <th class="px-6 py-3">Prompt</th>
                    <th class="px-6 py-3">Completion</th>
                    <th class="px-6 py-3">延迟</th>
                </tr>
            </thead>
            <tbody id="logTable" class="text-sm">
                <tr><td colspan="8" class="px-6 py-8 text-center text-gray-500">加载中...</td></tr>
            </tbody>
        </table>
    </div>
    <div class="px-6 py-4 border-t border-gray-200">
        <div class="flex items-center justify-between">
            <span class="text-sm text-gray-500" id="pageInfo">共 0 条</span>
            <div class="flex items-center gap-2" id="pagination"></div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
let currentPage = 1;

function loadLogs(page = 1) {
    currentPage = page;
    const params = new URLSearchParams({ page, per_page: 15 });
    fetch(`/web-api/logs?${params}`).then(res => res.json()).then(data => {
        const tbody = document.getElementById('logTable');
        if (data.data && data.data.length > 0) {
            tbody.innerHTML = data.data.map(log => `
                <tr class="border-b border-gray-100 hover:bg-gray-50">
                    <td class="px-6 py-3 text-gray-500">${log.created_at ? new Date(log.created_at * 1000).toLocaleString() : '-'}</td>
                    <td class="px-6 py-3 font-mono text-xs">${log.token_name || '-'}</td>
                    <td class="px-6 py-3 font-mono text-xs">${log.model || '-'}</td>
                    <td class="px-6 py-3">${log.channel_name || '-'}</td>
                    <td class="px-6 py-3">
                        <span class="px-2 py-1 text-xs rounded-full ${log.status === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'}">
                            ${log.status === 'success' ? '成功' : '失败'}
                        </span>
                    </td>
                    <td class="px-6 py-3">${log.prompt_tokens || 0}</td>
                    <td class="px-6 py-3">${log.completion_tokens || 0}</td>
                    <td class="px-6 py-3">${log.latency ? log.latency + 'ms' : '-'}</td>
                </tr>
            `).join('');
        } else {
            tbody.innerHTML = '<tr><td colspan="8" class="px-6 py-8 text-center text-gray-500">暂无日志</td></tr>';
        }
        document.getElementById('pageInfo').textContent = `共 ${data.total || 0} 条`;
    });
}
loadLogs();
</script>
@endpush