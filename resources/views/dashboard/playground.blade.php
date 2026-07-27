@extends('layouts.dashboard')
@section('title', __('Playground') . ' - ' . config('app.name'))
@section('content')
<h4 class="mb-4">{{ __('API Playground') }}</h4>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form id="playground-form">
            @csrf
            <div class="mb-3">
                <label class="form-label">{{ __('Model') }}</label>
                <select name="model" id="model-select" class="form-select">
                    <option value="gpt-3.5-turbo">gpt-3.5-turbo</option>
                    <option value="gpt-4">gpt-4</option>
                    <option value="claude-3-opus">claude-3-opus</option>
                    <option value="gemini-pro">gemini-pro</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">{{ __('Messages') }}</label>
                <div id="messages-container">
                    <div class="message-row mb-2">
                        <select name="role[]" class="form-select" style="width: 100px; display: inline-block;">
                            <option value="system">system</option>
                            <option value="user" selected>user</option>
                            <option value="assistant">assistant</option>
                        </select>
                        <input type="text" name="content[]" class="form-control" style="width: calc(100% - 110px); display: inline-block;" placeholder="{{ __('Message content') }}">
                    </div>
                </div>
                <button type="button" id="add-message" class="btn btn-sm btn-outline-secondary mt-2">+ {{ __('Add Message') }}</button>
            </div>
            <div class="mb-3">
                <label class="form-check">
                    <input type="checkbox" name="stream" class="form-check-input" id="stream">
                    <span class="form-check-label">{{ __('Stream Response') }}</span>
                </label>
            </div>
            <button type="submit" class="btn btn-primary">{{ __('Send') }}</button>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-light">
        <h6 class="mb-0">{{ __('Response') }}</h6>
    </div>
    <div class="card-body">
        <pre id="response-output" class="bg-dark text-light p-3" style="min-height: 200px; white-space: pre-wrap;">{{ __('Send a request to see the response here...') }}</pre>
    </div>
</div>

@push('scripts')
<script>
document.getElementById('add-message').addEventListener('click', function() {
    const container = document.getElementById('messages-container');
    const div = document.createElement('div');
    div.className = 'message-row mb-2';
    div.innerHTML = '<select name="role[]" class="form-select" style="width: 100px; display: inline-block;"><option value="system">system</option><option value="user" selected>user</option><option value="assistant">assistant</option></select><input type="text" name="content[]" class="form-control" style="width: calc(100% - 110px); display: inline-block;" placeholder="{{ __('Message content') }}">';
    container.appendChild(div);
});

document.getElementById('playground-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const output = document.getElementById('response-output');
    output.textContent = '{{ __('Loading...') }}';
    
    try {
        const formData = new FormData(this);
        const response = await fetch('{{ route('api.playground.chat') }}', {
            method: 'POST',
            body: formData,
            headers: { 'Accept': 'application/json' }
        });
        const data = await response.json();
        output.textContent = JSON.stringify(data, null, 2);
    } catch (err) {
        output.textContent = 'Error: ' + err.message;
    }
});
</script>
@endpush
@endsection