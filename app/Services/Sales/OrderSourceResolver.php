<?php

namespace App\Services\Sales;

use App\Models\PersonalAccessToken;
use App\Services\Auth\UserLoginChannelService;

class OrderSourceResolver
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function defaultForCart(array $input, ?PersonalAccessToken $token = null): string
    {
        if (! empty($input['order_source'])) {
            return (string) $input['order_source'];
        }

        $loginChannel = (string) ($token?->login_channel ?? UserLoginChannelService::BACKOFFICE);
        if ($loginChannel === UserLoginChannelService::POS) {
            return 'pos';
        }
        if ($loginChannel === UserLoginChannelService::MOBILE) {
            return 'mobile';
        }

        $channel = (string) ($input['channel'] ?? 'pos');

        return match ($channel) {
            'pos' => 'pos',
            'mobile' => 'mobile',
            'backend' => 'backoffice',
            default => 'backoffice',
        };
    }
}
