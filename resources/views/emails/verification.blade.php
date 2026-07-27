@component('mail::message')
# {{ $systemName }} 邮箱验证

您好！

您正在 {{ $systemName }} 进行邮箱验证，验证码为：

@component('mail::panel')
# {{ $code }}
@endcomponent

验证码有效期为 10 分钟，请尽快使用。如非本人操作，请忽略此邮件。

谢谢！

{{ $systemName }} 团队
@endcomponent