<?php

namespace App\Services\Notifications;

use App\Models\InAppNotification;
use App\Models\Organization;
use App\Models\User;

class InAppNotificationMailDelivery
{
    public function deliver(InAppNotification $notification, User $recipient): void
    {
        $email = trim((string) ($recipient->email ?? ''));
        if ($email === '') {
            return;
        }

        $organization = Organization::query()->find((int) $notification->organization_id);
        if ($organization === null) {
            return;
        }

        $settings = NotificationSettingsResolver::forOrganization($organization);
        $isApproval = $notification->type === 'approval';
        $enabled = $isApproval
            ? ! empty($settings['notify_on_approval_request'])
            : ! empty($settings['notify_on_approval_outcome']);

        if (! $enabled || empty($settings['email_enabled'])) {
            return;
        }

        $link = NotificationActionUrlBuilder::absolute($notification->action_url);
        $vars = [
            'title' => (string) $notification->title,
            'message' => (string) $notification->message,
            'link' => $link ?? '',
            'organization_name' => trim((string) ($organization->org_name ?? '')),
        ];

        if ($isApproval) {
            $subjectTemplate = (string) ($settings['approval_request_email_subject'] ?? '');
            $bodyTemplate = (string) ($settings['approval_request_email_template'] ?? '');
        } else {
            $subjectTemplate = (string) ($settings['approval_outcome_email_subject'] ?? '');
            $bodyTemplate = (string) ($settings['approval_outcome_email_template'] ?? '');
        }

        $subject = NotificationSettingsResolver::renderTemplate($subjectTemplate, $vars);
        $body = NotificationSettingsResolver::renderTemplate($bodyTemplate, $vars);

        app(OrganizationMailSender::class)->sendRaw(
            $organization,
            $email,
            $subject,
            $body,
            requireNotificationsEnabled: true,
        );
    }
}
