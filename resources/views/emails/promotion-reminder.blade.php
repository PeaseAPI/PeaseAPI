@component('mail::message')
# {{ $systemName }} 活动提醒

您好！

您订阅的 **{{ $vendor }}** 厂商套餐相关活动有更新：

@component('mail::panel')
## {{ $title }}

{{ $description }}

- 活动类型：{{ $kind }}
- 截止时间：{{ $endsAt }}
- 剩余：约 {{ $remainingDays }} 天
@endcomponent

@if($sourceUrl)
官方说明：{{ $sourceUrl }}
@endif

到期后对应价格/权益将按官方公告恢复或调整，请提前安排。如无相关订阅，请忽略此邮件。

{{ $systemName }} 团队
@endcomponent
