<?php

namespace App\Jobs;

use App\Models\PlatformWhatsNewNote;
use App\Services\Platform\PlatformWhatsNewService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PublishPlatformWhatsNewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public int $noteId) {}

    public function handle(PlatformWhatsNewService $service): void
    {
        $note = PlatformWhatsNewNote::query()->find($this->noteId);
        if (! $note || ! $note->isPublished()) {
            return;
        }

        $service->fanOutNotifications($note);
    }
}
