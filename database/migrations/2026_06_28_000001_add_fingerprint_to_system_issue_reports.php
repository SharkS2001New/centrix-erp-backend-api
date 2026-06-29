<?php

use App\Models\SystemIssueReport;
use App\Services\SystemIssues\SystemIssueFingerprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_issue_reports', function (Blueprint $table) {
            $table->string('fingerprint', 64)->nullable()->after('kind');
            $table->index(['fingerprint', 'status', 'created_at']);
        });

        SystemIssueReport::query()->orderBy('created_at')->chunk(200, function ($reports) {
            foreach ($reports as $report) {
                $report->update([
                    'fingerprint' => SystemIssueFingerprint::forReport(
                        (string) $report->kind,
                        (string) $report->message,
                        $report->api_path,
                    ),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('system_issue_reports', function (Blueprint $table) {
            $table->dropIndex(['fingerprint', 'status', 'created_at']);
            $table->dropColumn('fingerprint');
        });
    }
};
