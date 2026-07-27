@component('mail::message')
# {{ $systemName }} 密码重置

您好！

我们收到了您 ({{ $email }}) 的密码重置请求。请点击下方按钮重置密码：

@component('mail::button', ['url' => $link])
重置密码
@endcomponent

该链接有效期为 30 分钟。如非本人操作，请忽略此邮件，您的密码不会发生变化。

谢谢！

{{ $systemName }} 团队
@endcomponent