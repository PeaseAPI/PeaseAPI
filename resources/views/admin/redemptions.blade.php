@extends('layouts.dashboard')
@section('title', '兑换码管理')

@section('content')
<div class="mb-6 flex items-center justify-between">
    <h2 class="text-lg font-semibold">兑换码管理</h2>
    <button onclick="openCreate()" class="bg-primary-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-primary-700">
        <i class="fas fa-plus mr-1"></i>生成兑换码
    </button>
</div>
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <table class="w-full text-sm">
        <thead><tr class="text-left text-xs font-medium text-gray-500 uppercase bg-gray-50"><th class="px-6 py-3">ID</th><th class="px-6 py-3">码</th><th class="px-6 py-3">配额</th><th class="px-6 py-3">使用人</th><th class="px-6 py-3">状态</th><th class="px-6 py-3">创建时间</th><th class="px-6 py-3">操作</th></tr></thead>
        <tbody id="redemptionTable"><tr><td colspan="7" class="px-6 py-8 text-center text-gray-500">加载中...</td></tr></tbody>
    </table>
    <div class="px-6 py-4 border-t flex justify-between items-center">
        <span class="text-sm text-gray-500" id="pageInfo">共 0 条</span>
    </div>
</div>

<div id="codeModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4">
        <div class="px-6 py-4 border-b flex justify-between items-center">
            <h3 class="text-lg font-semibold">生成兑换码</h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
        </div>
        <form id="codeForm" class="p-6 space-y-4">
            <div><label class="block text-sm font-medium text-gray-700 mb-1">配额数量 *</label><input type="number" id="formQuota" required class="w-full px-4 py-2.5 border rounded-lg text-sm"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">生成数量</label><input type="number" id="formCount" value="1" min="1" max="100" class="w-full px-4 py-2.5 border rounded-lg text-sm"></div>
            <div class="flex gap-3 pt-4">
                <button type="button" onclick="closeModal()" class="flex-1 px-4 py-2.5 border rounded-lg text-sm">取消</button>
                <button type="submit" class="flex-1 px-4 py-2.5 bg-primary-600 text-white rounded-lg text-sm">生成</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
function loadRedemptions(){fetch('/web-api/redemptions?per_page=20').then(r=>r.json()).then(d=>{const t=document.getElementById('redemptionTable');if(d.data&&d.data.length){t.innerHTML=d.data.map(r=>`<tr class="border-b border-gray-100 hover:bg-gray-50"><td class="px-6 py-3">${r.id}</td><td class="px-6 py-3 font-mono text-xs">${r.code}</td><td class="px-6 py-3">${(r.quota||0).toLocaleString()}</td><td class="px-6 py-3">${r.username||'-'}</td><td class="px-6 py-3"><span class="px-2 py-1 text-xs rounded-full ${r.status===1?'bg-green-100 text-green-700':'bg-gray-100 text-gray-700'}">${r.status===1?'已使用':'未使用'}</span></td><td class="px-6 py-3 text-gray-500">${r.created_at?new Date(r.created_at*1000).toLocaleDateString():'-'}</td><td class="px-6 py-3"><button onclick="deleteCode(${r.id})" class="text-red-600 hover:text-red-800">删除</button></td></tr>`).join('')}else{t.innerHTML='<tr><td colspan="7" class="px-6 py-8 text-center text-gray-500">暂无兑换码</td></tr>'}document.getElementById('pageInfo').textContent=`共 ${d.total||0} 条`})}
function openCreate(){document.getElementById('codeModal').classList.remove('hidden')}
function closeModal(){document.getElementById('codeModal').classList.add('hidden')}
function deleteCode(id){if(!confirm('确定删除？'))return;fetch(`/web-api/redemptions/${id}`,{method:'DELETE',headers:{'X-CSRF-TOKEN':'{{ csrf_token() }}'}}).then(()=>loadRedemptions())}
document.getElementById('codeForm').onsubmit=function(e){e.preventDefault();fetch('/web-api/redemptions',{method:'POST',headers:{'X-CSRF-TOKEN':'{{ csrf_token() }}','Content-Type':'application/json'},body:JSON.stringify({quota:document.getElementById('formQuota').value,count:document.getElementById('formCount').value})}).then(r=>r.json()).then(d=>{if(d.codes){alert('生成成功:\n'+d.codes.join('\n'))}closeModal();loadRedemptions()})}
loadRedemptions();
</script>
@endpush