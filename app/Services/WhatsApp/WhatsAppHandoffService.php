<?php

namespace App\Services\WhatsApp;

use App\Models\Customer;
use App\Models\User;
use App\Models\WhatsappConversation;
use App\Models\WhatsappHandoff;
use App\Services\Notifications\AdminNotificationService;
use App\Services\Notifications\InAppNotificationEvents;

class WhatsAppHandoffService
{
    public function __construct(
        protected AdminNotificationService $adminNotifications,
    ) {}

    public function requestHandoff(
        ResolvedWhatsAppConfig $config,
        WhatsappConversation $conversation,
        Customer $customer,
        User $botUser,
        ?string $customerMessage = null,
    ): WhatsappHandoff {
        $handoff = WhatsappHandoff::query()->create([
            'organization_id' => $config->organizationId,
            'conversation_id' => $conversation->id,
            'customer_num' => $customer->customer_num,
            'phone' => $conversation->phone,
            'status' => 'open',
            'customer_message' => $customerMessage,
        ]);

        $conversation->state = 'handoff';
        $conversation->payload = array_merge($conversation->payload ?? [], [
            'handoff_id' => (int) $handoff->id,
        ]);
        $conversation->save();

        $message = "Customer *{$customer->customer_name}* ({$conversation->phone}) asked to speak with someone on WhatsApp.";
        if ($customerMessage) {
            $message .= ' Last message: "'.mb_substr($customerMessage, 0, 200).'".';
        }

        $this->adminNotifications->notifyPermission($botUser, 'sales.orders.view', [
            'type' => 'info',
            'severity' => 'warning',
            'title' => 'WhatsApp customer needs help',
            'message' => $message,
            'action_url' => '/sales/whatsapp?handoff='.$handoff->id,
        ], InAppNotificationEvents::WHATSAPP_HANDOFF);

        return $handoff;
    }

    public function resolve(WhatsappHandoff $handoff, User $resolver): WhatsappHandoff
    {
        $handoff->update([
            'status' => 'resolved',
            'resolved_at' => now(),
            'resolved_by' => $resolver->id,
        ]);

        if ($handoff->conversation_id) {
            WhatsappConversation::query()
                ->where('id', $handoff->conversation_id)
                ->where('state', 'handoff')
                ->update([
                    'state' => 'main_menu',
                ]);
        }

        return $handoff->fresh(['customer', 'conversation']);
    }

    public function hasOpenHandoff(int $organizationId, int $conversationId): bool
    {
        return WhatsappHandoff::query()
            ->where('organization_id', $organizationId)
            ->where('conversation_id', $conversationId)
            ->where('status', 'open')
            ->exists();
    }
}
