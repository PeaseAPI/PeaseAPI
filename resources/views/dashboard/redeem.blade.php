@extends('layouts.dashboard')
@section('title', '兑换码')

@section('content')
<div class="max-w-lg mx-auto">
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">兑换码充值</h3>
        <form id="redeemForm" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">兑换码</label>
                <input type="text" name="code" required
                    class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 text-sm"
                    placeholder="请输入兑换码">
            </div>
            <button type="submit" class="w-full px-4 py-2.5 bg-primary-600 text-white rounded-lg text-sm font-medium hover:bg-primary-700 transition">
                兑换
            </button>
        </form>
        <div id="result" class="mt-4 hidden"></div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.getElementById('redeemForm').onsubmit = function(e) {
    e.preventDefault();
    const code = this.code.value;
    fetch('/web-api/redeem', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Content-Type': 'application/json' },
        body: JSON.stringify({ code })
    }).then(res => res.json()).then(data => {
        const el = document.getElementById('result');
        el.classList.remove('hidden');
        if (data.success) {
            el.className = 'mt-4 p-4 bg-green-50 text-green-700 rounded-lg text-sm';
            el.textContent = data.message || '兑换成功！';
            this.reset();
        } else {
            el.className = 'mt-4 p-4 bg-red-50 text-red-700 rounded-lg text-sm';
            el.textContent = data.message || '兑换失败';
        }
    });
};
</script>
@endpush