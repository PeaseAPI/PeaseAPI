@extends('layouts.dashboard')
@section('title', '全局日志')

@section('content')
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <div class="p-4 border-b flex items-center gap-4">
        <input type="text" id="searchInput" placeholder="搜索用户/模型..." class="flex-1 px-4 py-2 border border-gray-300 rounded-lg text-sm">
        <select id="statusFilter" class="px-4 py-2 border border-gray-300 rounded-lg text-sm">
            <option value="">全部状态</option>
            <option value="success">成功</option>
            <option value="error">失败</option>
        </select>
    </div>
    <table class="w-full text-sm">
        <thead><tr class="text-left text-xs font-medium text-gray-500 uppercase bg-gray-50"><th class="px-6 py-3">时间</th><th class="px-6 py-3">用户</th><th class="px-6 py-3">令牌</th><th class="px-6 py-3">模型</th><th class="px-6 py-3">渠道</th><th class="px-6 py-3">状态</th><th class="px-6 py-3">用量</th><th class="px-6 py-3">延迟</th></tr></thead>
        <tbody id="logTable"><tr><td colspan="8" class="px-6 py-8 text-center text-gray-500">加载中...</td></tr></tbody>
    </table>
    <div class="px-6 py-4 border-t flex justify-between items-center">
        <span class="text-sm text-gray-500" id="pageInfo">共 0 条</span>
    </div>
</div>
@endsection

@push('scripts')
<script>
function loadLogs(page=1){fetch(`/web-api/logs?page=${page}&per_page=20`).then(r=>r.json()).then(d=>{const t=document.getElementById('logTable');if(d.data&&d.data.length){t.innerHTML=d.data.map(l=>`<tr class="border-b border-gray-100 hover:bg-gray-50"><td class="px-6 py-3 text-gray-500">${l.created_at?new Date(l.created_at*1000).toLocaleString():'-'}</td><td class="px-6 py-3">${l.username||'-'}</td><td class="px-6 py-3 font-mono text-xs">${l.token_name||'-'}</td><td class="px-6 py-3 font-mono text-xs">${l.model||'-'}</td><td class="px-6 py-3">${l.channel_name||'-'}</td><td class="px-6 py-3"><span class="px-2 py-1 text-xs rounded-full ${l.status==='success'?'bg-green-100 text-green-700':'bg-red-100 text-red-700'}">${l.status==='success'?'成功':'失败'}</span></td><td class="px-6 py-3">${(l.prompt_tokens||0)+(l.completion_tokens||0)}</td><td class="px-6 py-3">${l.latency?l.latency+'ms':'-'}</td></tr>`).join('')}else{t.innerHTML='<tr><td colspan="8" class="px-6 py-8 text-center text-gray-500">暂无日志</td></tr>'}document.getElementById('pageInfo').textContent=`共 ${d.total||0} 条`})}
loadLogs();
</script>
@endpush