<?php

namespace App\Services\Notifications;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\Sale;

class CustomerNotificationDispatcher
{
    public function __construct(
        protected AfricasTalkingSmsService $sms,
        protected OrganizationMailSender $mail,
    ) {}

    /**
     * @param  array<string, string|int|float>  $vars
     */
    public function notifySaleCustomer(
        Organization $organization,
        Sale $sale,
        string $smsTemplate,
        string $emailTemplate,
        string $emailSubject,
        array $vars,
    ): void {
        $settings = NotificationSettingsResolver::forOrganization($organization);
        $smsBody = NotificationSettingsResolver::renderTemplate($smsTemplate, $vars);
        $emailBodyTemplate = trim($emailTemplate) !== '' ? $emailTemplate : $smsTemplate;
        $emailBody = NotificationSettingsResolver::renderTemplate($emailBodyTemplate, $vars);
        $subject = NotificationSettingsResolver::renderTemplate($emailSubject, $vars);

        if (! empty($settings['sms_enabled'])) {
            $phone = $this->resolveCustomerPhone($sale);
            if ($phone) {
                $this->sms->send($organization, $phone, $smsBody);
            }
        }

        if (! empty($settings['email_enabled'])) {
            $email = $this->resolveCustomerEmail($sale);
            if ($email) {
                $this->mail->sendRaw(
                    $organization,
                    $email,
                    $subject,
                    $emailBody,
                    requireNotificationsEnabled: true,
                );
            }
        }
    }

    /**
     * @param  array<string, string|int|float>  $vars
     */
    public function notifyCustomerContact(
        Organization $organization,
        ?string $phone,
        ?string $email,
        string $smsTemplate,
        string $emailTemplate,
        string $emailSubject,
        array $vars,
    ): void {
        $settings = NotificationSettingsResolver::forOrganization($organization);
        $smsBody = NotificationSettingsResolver::renderTemplate($smsTemplate, $vars);
        $emailBodyTemplate = trim($emailTemplate) !== '' ? $emailTemplate : $smsTemplate;
        $emailBody = NotificationSettingsResolver::renderTemplate($emailBodyTemplate, $vars);
        $subject = NotificationSettingsResolver::renderTemplate($emailSubject, $vars);

        if (! empty($settings['sms_enabled']) && $phone) {
            $this->sms->send($organization, $phone, $smsBody);
        }

        if (! empty($settings['email_enabled']) && $email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->mail->sendRaw(
                $organization,
                $email,
                $subject,
                $emailBody,
                requireNotificationsEnabled: true,
            );
        }
    }

    protected function resolveCustomerPhone(Sale $sale): ?string
    {
        if (! $sale->customer_num) {
            return null;
        }

        $phone = Customer::query()
            ->where('customer_num', $sale->customer_num)
            ->value('phone_number');

        return $phone ? trim((string) $phone) : null;
    }

    protected function resolveCustomerEmail(Sale $sale): ?string
    {
        if (! $sale->customer_num) {
            return null;
        }

        $email = Customer::query()
            ->where('customer_num', $sale->customer_num)
            ->value('email');

        $email = trim((string) ($email ?? ''));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }
}
