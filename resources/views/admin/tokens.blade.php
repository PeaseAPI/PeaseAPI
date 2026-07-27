@extends('layouts.dashboard')
@section('title', '令牌总览')

@section('content')
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <table class="w-full text-sm">
        <thead><tr class="text-left text-xs font-medium text-gray-500 uppercase bg-gray-50"><th class="px-6 py-3">ID</th><th class="px-6 py-3">用户</th><th class="px-6 py-3">名称</th><th class="px-6 py-3">密钥</th><th class="px-6 py-3">配额</th><th class="px-6 py-3">已用</th><th class="px-6 py-3">状态</th><th class="px-6 py-3">创建时间</th></tr></thead>
        <tbody id="tokenTable"><tr><td colspan="8" class="px-6 py-8 text-center text-gray-500">加载中...</td></tr></tbody>
    </table>
    <div class="px-6 py-4 border-t flex justify-between items-center">
        <span class="text-sm text-gray-500" id="pageInfo">共 0 条</span>
        <div id="pagination" class="flex gap-2"></div>
    </div>
</div>
@endsection

@push('scripts')
<script>
let page=1;
function loadTokens(p=1){page=p;fetch(`/web-api/tokens?page=${p}&per_page=15`).then(r=>r.json()).then(d=>{const t=document.getElementById('tokenTable');if(d.data&&d.data.length){t.innerHTML=d.data.map(tk=>`<tr class="border-b border-gray-100 hover:bg-gray-50"><td class="px-6 py-3">${tk.id}</td><td class="px-6 py-3">${tk.username||'-'}</td><td class="px-6 py-3 font-medium">${tk.name}</td><td class="px-6 py-3 font-mono text-xs">${tk.key.substring(0,16)}...</td><td class="px-6 py-3">${tk.unlimited_quota?'∞':(tk.quota_quota||0).toLocaleString()}</td><td class="px-6 py-3">${(tk.quota_used||0).toLocaleString()}</td><td class="px-6 py-3"><span class="px-2 py-1 text-xs rounded-full ${tk.status===1?'bg-green-100 text-green-700':'bg-red-100 text-red-700'}">${tk.status===1?'正常':'禁用'}</span></td><td class="px-6 py-3 text-gray-500">${tk.created_at?new Date(tk.created_at*1000).toLocaleDateString():'-'}</td></tr>`).join('')}else{t.innerHTML='<tr><td colspan="8" class="px-6 py-8 text-center text-gray-500">暂无令牌</td></tr>'}document.getElementById('pageInfo').textContent=`共 ${d.total||0} 条`})}
loadTokens();
</script>
@endpush