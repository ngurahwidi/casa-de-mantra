<?php

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Policies\SystemSettingPolicy;
use Database\Factories\NotificationTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'code',
    'channel',
    'subject',
    'body',
    'is_active',
])]
#[UsePolicy(SystemSettingPolicy::class)]
class NotificationTemplate extends Model
{
    /** @use HasFactory<NotificationTemplateFactory> */
    use HasFactory, HasUuids;

    /**
     * Variables a subject or body may use as {{ variable }}; the notification renderer (T7.1) fills them.
     *
     * @var list<string>
     */
    public const PLACEHOLDERS = [
        'property_name', 'property_email', 'property_whatsapp', 'property_address', 'checkin_time', 'checkout_time',
        'guest_name', 'guest_email', 'guest_phone', 'reservation_code', 'check_in', 'check_out', 'nights', 'adults',
        'children', 'special_requests', 'total', 'status_url', 'payment_deadline', 'payment_instructions',
        'reject_reason', 'cancel_reason', 'admin_url',
    ];

    /**
     * @param  Builder<NotificationTemplate>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'is_active' => 'boolean',
        ];
    }
}
