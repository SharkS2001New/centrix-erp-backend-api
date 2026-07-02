<?php

namespace App\Console\Commands;

use Google\Client as GoogleClient;
use Google\Service\Drive;
use Illuminate\Console\Command;

/** One-time OAuth setup for personal Gmail Drive backup uploads. */
class AuthorizeGoogleDriveBackupCommand extends Command
{
    protected $signature = 'erp:authorize-google-drive-backup {--code= : Authorization code from Google}';

    protected $description = 'Obtain a Google Drive OAuth refresh token for database backup uploads (personal Gmail)';

    public function handle(): int
    {
        if (! class_exists(GoogleClient::class)) {
            $this->error('google/apiclient is not installed.');

            return self::FAILURE;
        }

        $clientId = trim((string) config('backup.google_drive.oauth_client_id', ''));
        $clientSecret = trim((string) config('backup.google_drive.oauth_client_secret', ''));
        $redirectUri = trim((string) config('backup.google_drive.oauth_redirect_uri', 'urn:ietf:wg:oauth:2.0:oob'));

        if ($clientId === '' || $clientSecret === '') {
            $this->error('Set BACKUP_GOOGLE_DRIVE_OAUTH_CLIENT_ID and BACKUP_GOOGLE_DRIVE_OAUTH_CLIENT_SECRET in .env first.');
            $this->line('Create an OAuth client (Desktop app) in Google Cloud Console for this project.');

            return self::FAILURE;
        }

        $client = new GoogleClient;
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setRedirectUri($redirectUri);
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setScopes([Drive::DRIVE_FILE]);

        $code = trim((string) $this->option('code'));
        if ($code === '') {
            $this->line('Open this URL in a browser and sign in with the Google account that owns the backup folder:');
            $this->newLine();
            $this->line($client->createAuthUrl());
            $this->newLine();
            $this->line('Then run:');
            $this->line('  php artisan erp:authorize-google-drive-backup --code=PASTE_CODE_HERE');

            return self::SUCCESS;
        }

        $token = $client->fetchAccessTokenWithAuthCode($code);
        if (isset($token['error'])) {
            $this->error('Authorization failed: '.($token['error_description'] ?? $token['error']));

            return self::FAILURE;
        }

        $refresh = trim((string) ($token['refresh_token'] ?? ''));
        if ($refresh === '') {
            $this->warn('No refresh token returned. Revoke prior app access at https://myaccount.google.com/permissions and run again.');
            $this->line(json_encode($token, JSON_PRETTY_PRINT));

            return self::FAILURE;
        }

        $this->info('Add these to your API server .env:');
        $this->newLine();
        $this->line('BACKUP_GOOGLE_DRIVE_AUTH=oauth');
        $this->line('BACKUP_GOOGLE_DRIVE_OAUTH_REFRESH_TOKEN='.$refresh);
        $this->newLine();
        $this->line('Then: php artisan config:clear');

        return self::SUCCESS;
    }
}
