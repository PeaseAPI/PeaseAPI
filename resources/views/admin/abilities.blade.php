@extends('layouts.dashboard')
@section('title', '能力管理')

@section('content')
<div class="mb-6 flex items-center justify-between">
    <h2 class="text-lg font-semibold">能力管理</h2>
    <button onclick="openCreate()" class="bg-primary-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-primary-700">
        <i class="fas fa-plus mr-1"></i>添加能力
    </button>
</div>
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <table class="w-full text-sm">
        <thead><tr class="text-left text-xs font-medium text-gray-500 uppercase bg-gray-50"><th class="px-6 py-3">ID</th><th class="px-6 py-3">分组</th><th class="px-6 py-3">模型</th><th class="px-6 py-3">渠道</th><th class="px-6 py-3">优先级</th><th class="px-6 py-3">状态</th><th class="px-6 py-3">操作</th></tr></thead>
        <tbody id="abilityTable"><tr><td colspan="7" class="px-6 py-8 text-center text-gray-500">加载中...</td></tr></tbody>
    </table>
</div>

<div id="abilityModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4">
        <div class="px-6 py-4 border-b flex justify-between items-center">
            <h3 class="text-lg font-semibold" id="modalTitle">添加能力</h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
        </div>
        <form id="abilityForm" class="p-6 space-y-4">
            <input type="hidden" id="abilityId">
            <div><label class="block text-sm font-medium text-gray-700 mb-1">分组 *</label><input type="text" id="formGroup" required class="w-full px-4 py-2.5 border rounded-lg text-sm" placeholder="default"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">模型 *</label><input type="text" id="formModel" required class="w-full px-4 py-2.5 border rounded-lg text-sm" placeholder="gpt-4"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">渠道ID *</label><input type="number" id="formChannelId" required class="w-full px-4 py-2.5 border rounded-lg text-sm"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">优先级</label><input type="number" id="formPriority" value="0" class="w-full px-4 py-2.5 border rounded-lg text-sm"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">状态</label><select id="formEnabled" class="w-full px-4 py-2.5 border rounded-lg text-sm"><option value="1">启用</option><option value="0">禁用</option></select></div>
            <div class="flex gap-3 pt-4">
                <button type="button" onclick="closeModal()" class="flex-1 px-4 py-2.5 border rounded-lg text-sm">取消</button>
                <button type="submit" class="flex-1 px-4 py-2.5 bg-primary-600 text-white rounded-lg text-sm">保存</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
let page=1;
function readApiPayload(response){
    return response.text().then((text)=>{
        if(!text){return null;}
        try{
            return JSON.parse(text);
        }catch{
            return { raw: text };
        }
    });
}
function handleApiResponse(response, defaultMessage='请求失败'){
    return readApiPayload(response).then((payload)=>{
        if(!response.ok){
            const message = payload?.message || payload?.error || payload?.raw || defaultMessage;
            throw new Error(message);
        }
        return payload;
    });
}
function loadAbilities(p=1){
    page=p;
    fetch(`/web-api/abilities?page=${p}&per_page=15`)
        .then((response)=>handleApiResponse(response, '加载能力失败'))
        .then(d=>{
            const t=document.getElementById('abilityTable');
            const items=Array.isArray(d?.data?.items)?d.data.items:[];
            if(items.length){
                t.innerHTML=items.map(a=>`<tr class="border-b border-gray-100 hover:bg-gray-50"><td class="px-6 py-3">${a.id}</td><td class="px-6 py-3 font-medium">${a.group||'-'}</td><td class="px-6 py-3 font-mono text-xs">${a.model}</td><td class="px-6 py-3">${a.channel_id||'-'}${a.channel?' ('+a.channel.name+')':''}</td><td class="px-6 py-3">${a.priority||0}</td><td class="px-6 py-3"><span class="px-2 py-1 text-xs rounded-full ${a.enabled===1?'bg-green-100 text-green-700':'bg-red-100 text-red-700'}">${a.enabled===1?'启用':'禁用'}</span></td><td class="px-6 py-3"><button onclick="editAbility(${a.id})" class="text-blue-600 hover:text-blue-800 mr-2">编辑</button><button onclick="deleteAbility(${a.id})" class="text-red-600 hover:text-red-800">删除</button></td></tr>`).join('');
            }else{
                t.innerHTML='<tr><td colspan="7" class="px-6 py-8 text-center text-gray-500">暂无能力</td></tr>';
            }
        })
        .catch(e=>{
            console.error('加载能力失败:',e);
            document.getElementById('abilityTable').innerHTML='<tr><td colspan="7" class="px-6 py-8 text-center text-red-500">加载失败: '+e.message+'</td></tr>';
        });
}
function openCreate(){
    document.getElementById('modalTitle').textContent='添加能力';
    document.getElementById('abilityId').value='';
    document.getElementById('abilityForm').reset();
    document.getElementById('formPriority').value='0';
    document.getElementById('formEnabled').value='1';
    document.getElementById('abilityModal').classList.remove('hidden');
}
function editAbility(id){
    fetch(`/web-api/abilities/${id}`)
        .then((response)=>handleApiResponse(response, '加载能力失败'))
        .then(d=>{
            const a=d?.data||d;
            document.getElementById('modalTitle').textContent='编辑能力';
            document.getElementById('abilityId').value=a.id;
            document.getElementById('formGroup').value=a.group||'';
            document.getElementById('formModel').value=a.model||'';
            document.getElementById('formChannelId').value=a.channel_id||'';
            document.getElementById('formPriority').value=a.priority||0;
            document.getElementById('formEnabled').value=a.enabled;
            document.getElementById('abilityModal').classList.remove('hidden');
        })
        .catch(e=>alert('加载失败: '+e.message));
}
function closeModal(){document.getElementById('abilityModal').classList.add('hidden')}
function deleteAbility(id){
    if(!confirm('确定删除？'))return;
    fetch(`/web-api/abilities/${id}`,{method:'DELETE',headers:{'X-CSRF-TOKEN':'{{ csrf_token() }}'}})
        .then((response)=>handleApiResponse(response, '删除失败'))
        .then(()=>loadAbilities(page))
        .catch(e=>alert('删除失败: '+e.message));
}
document.getElementById('abilityForm').onsubmit=function(e){
    e.preventDefault();
    const id=document.getElementById('abilityId').value;
    const data={
        group:document.getElementById('formGroup').value,
        model:document.getElementById('formModel').value,
        channel_id:parseInt(document.getElementById('formChannelId').value),
        priority:parseInt(document.getElementById('formPriority').value)||0,
        enabled:parseInt(document.getElementById('formEnabled').value)
    };
    const url=id?`/web-api/abilities/${id}`:'/web-api/abilities';
    const method=id?'PUT':'POST';
    fetch(url,{method,headers:{'X-CSRF-TOKEN':'{{ csrf_token() }}','Content-Type':'application/json'},body:JSON.stringify(data)})
        .then((response)=>handleApiResponse(response, '保存失败'))
        .then(()=>{
            closeModal();
            loadAbilities(page);
        })
        .catch(e=>alert('保存失败: '+e.message));
};
loadAbilities();
</script>
@endpush