@extends('layouts.dashboard')
@section('title', '渠道管理')

@section('content')
<div class="mb-6 flex items-center justify-between">
    <h2 class="text-lg font-semibold">渠道管理</h2>
    <button onclick="openCreate()" class="bg-primary-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-primary-700">
        <i class="fas fa-plus mr-1"></i>添加渠道
    </button>
</div>
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <table class="w-full text-sm">
        <thead><tr class="text-left text-xs font-medium text-gray-500 uppercase bg-gray-50"><th class="px-6 py-3">ID</th><th class="px-6 py-3">名称</th><th class="px-6 py-3">类型</th><th class="px-6 py-3">优先级</th><th class="px-6 py-3">状态</th><th class="px-6 py-3">操作</th></tr></thead>
        <tbody id="channelTable"><tr><td colspan="6" class="px-6 py-8 text-center text-gray-500">加载中...</td></tr></tbody>
    </table>
    <div class="px-6 py-4 border-t flex justify-between items-center">
        <span class="text-sm text-gray-500" id="pageInfo">共 0 条</span>
        <div id="pagination" class="flex gap-2"></div>
    </div>
</div>

<!-- Create/Edit Modal -->
<div id="channelModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-lg mx-4 max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-4 border-b flex justify-between items-center sticky top-0 bg-white">
            <h3 class="text-lg font-semibold" id="modalTitle">添加渠道</h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
        </div>
        <form id="channelForm" class="p-6 space-y-4">
            <input type="hidden" id="channelId">
            <div><label class="block text-sm font-medium text-gray-700 mb-1">名称 *</label><input type="text" id="formName" required class="w-full px-4 py-2.5 border rounded-lg text-sm"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">类型</label><select id="formType" class="w-full px-4 py-2.5 border rounded-lg text-sm"><option value="1">OpenAI</option><option value="14">Anthropic</option><option value="3">Azure</option><option value="24">Gemini</option><option value="43">DeepSeek</option><option value="20">OpenRouter</option><option value="25">Groq</option><option value="40">SiliconFlow</option><option value="48">xAI</option><option value="15">百度</option><option value="17">阿里</option><option value="18">讯飞</option><option value="16">智谱</option><option value="23">腾讯</option><option value="35">MiniMax</option><option value="42">Mistral</option><option value="34">Cohere</option><option value="4">Ollama</option><option value="8">自定义</option><option value="" disabled>── 新闻/搜索 API ──</option><option value="80">Google Custom Search</option><option value="81">NewsAPI</option><option value="82">Tavily Search</option><option value="83">Exa Search</option></select></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">API 地址</label><input type="text" id="formBaseUrl" class="w-full px-4 py-2.5 border rounded-lg text-sm" placeholder="https://api.openai.com"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">密钥 *</label><textarea id="formKey" required class="w-full px-4 py-2.5 border rounded-lg text-sm" rows="2"></textarea></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">模型</label><input type="text" id="formModels" class="w-full px-4 py-2.5 border rounded-lg text-sm" placeholder="gpt-4,gpt-3.5-turbo"></div>
            <div class="grid grid-cols-2 gap-4">
                <div><label class="block text-sm font-medium text-gray-700 mb-1">优先级</label><input type="number" id="formPriority" value="0" class="w-full px-4 py-2.5 border rounded-lg text-sm"></div>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">权重</label><input type="number" id="formWeight" value="1" class="w-full px-4 py-2.5 border rounded-lg text-sm"></div>
            </div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">状态</label><select id="formStatus" class="w-full px-4 py-2.5 border rounded-lg text-sm"><option value="1">启用</option><option value="2">禁用</option></select></div>
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
const typeNames={1:'OpenAI',14:'Anthropic',3:'Azure',24:'Gemini',43:'DeepSeek',20:'OpenRouter',25:'Groq',40:'SiliconFlow',48:'xAI',15:'百度',17:'阿里',18:'讯飞',16:'智谱',23:'腾讯',35:'MiniMax',42:'Mistral',34:'Cohere',4:'Ollama',8:'自定义',80:'Google CSE',81:'NewsAPI',82:'Tavily',83:'Exa'};
let page=1;
function loadChannels(p=1){page=p;fetch(`/web-api/channels?page=${p}&per_page=10`, { credentials: 'same-origin' }).then(r=>r.json()).then(d=>{const t=document.getElementById('channelTable');if(d.data&&d.data.length){t.innerHTML=d.data.map(c=>`<tr class="border-b border-gray-100 hover:bg-gray-50"><td class="px-6 py-3">${c.id}</td><td class="px-6 py-3 font-medium">${c.name}</td><td class="px-6 py-3">${typeNames[c.type]||c.type}</td><td class="px-6 py-3">${c.priority||0}</td><td class="px-6 py-3"><span class="px-2 py-1 text-xs rounded-full ${c.status===1?'bg-green-100 text-green-700':'bg-red-100 text-red-700'}">${c.status===1?'启用':'禁用'}</span></td><td class="px-6 py-3"><button onclick="editChannel(${c.id})" class="text-blue-600 hover:text-blue-800 mr-2">编辑</button><button onclick="deleteChannel(${c.id})" class="text-red-600 hover:text-red-800">删除</button></td></tr>`).join('')}else{t.innerHTML='<tr><td colspan="6" class="px-6 py-8 text-center text-gray-500">暂无渠道</td></tr>'}document.getElementById('pageInfo').textContent=`共 ${d.total||0} 条`})}
function openCreate(){document.getElementById('modalTitle').textContent='添加渠道';document.getElementById('channelId').value='';document.getElementById('channelForm').reset();document.getElementById('channelModal').classList.remove('hidden')}
function editChannel(id){fetch(`/web-api/channels/${id}`, { credentials: 'same-origin',headers:{'Accept':'application/json'}}).then(r=>r.json()).then(c=>{const d=c.data||c;document.getElementById('modalTitle').textContent='编辑渠道';document.getElementById('channelId').value=d.id;document.getElementById('formName').value=d.name;document.getElementById('formType').value=d.type;document.getElementById('formBaseUrl').value=d.base_url||'';document.getElementById('formKey').value=d.key||'';document.getElementById('formModels').value=Array.isArray(d.models)?d.models.join(','):d.models||'';document.getElementById('formPriority').value=d.priority||0;document.getElementById('formWeight').value=d.weight||1;document.getElementById('formStatus').value=d.status;document.getElementById('channelModal').classList.remove('hidden')})}
function closeModal(){document.getElementById('channelModal').classList.add('hidden')}
function apiError(res){return res.json().catch(()=>({message:'服务器返回了非 JSON 响应（可能是 419 CSRF 或 500 错误）'})).then(d=>{throw new Error(d.message||JSON.stringify(d.errors||d))})}
function deleteChannel(id){if(!confirm('确定删除？'))return;fetch(`/web-api/channels/${id}`, { credentials: 'same-origin',method:'DELETE',headers:{'Accept':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'}}).then(r=>{if(!r.ok)return apiError(r);return r.json()}).then(()=>loadChannels(page)).catch(err=>alert('删除失败：'+err.message))}
document.getElementById('channelForm').onsubmit=function(e){e.preventDefault();const id=document.getElementById('channelId').value;const modelsStr=document.getElementById('formModels').value;const data={name:document.getElementById('formName').value,type:parseInt(document.getElementById('formType').value,10),base_url:document.getElementById('formBaseUrl').value,key:document.getElementById('formKey').value,models:modelsStr?modelsStr.split(',').map(s=>s.trim()).filter(Boolean):[],priority:parseInt(document.getElementById('formPriority').value,10)||0,weight:parseInt(document.getElementById('formWeight').value,10)||1,status:parseInt(document.getElementById('formStatus').value,10)};const url=id?`/web-api/channels/${id}`:'/web-api/channels';const method=id?'PUT':'POST';fetch(url,{method,headers:{'Accept':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}','Content-Type':'application/json'},body:JSON.stringify(data)}).then(r=>{if(!r.ok)return apiError(r);return r.json()}).then(()=>{closeModal();loadChannels(page)}).catch(err=>alert('保存失败：'+err.message))};
loadChannels();
</script>
@endpush