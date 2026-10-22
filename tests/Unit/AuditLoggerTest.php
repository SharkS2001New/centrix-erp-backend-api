<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\ModelChangeAuditor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AuditLoggerTest extends TestCase
{
    public function test_secrets_are_redacted_before_they_are_stored(): void
    {
        $logger = new AuditLogger;

        $values = $logger->redactValues([
            'username' => 'amina',
            'password' => 'hashed-secret',
            'login_pin' => '1234',
            'notes' => 'ok',
        ]);

        $this->assertSame('amina', $values['username']);
        $this->assertSame('ok', $values['notes']);
        $this->assertSame('[redacted]', $values['password']);
        $this->assertSame('[redacted]', $values['login_pin']);
    }

    public function test_a_model_event_and_an_explicit_log_for_the_same_change_are_one_row(): void
    {
        $logger = new RecordingAuditLogger;
        $user = $this->actor();

        $logger->logAutomatic($user, 'create', 'products', 9, null, ['product_name' => 'Soap']);
        $logger->log($user, 'create', 'products', 9, null, ['product_name' => 'Soap']);

        $logger->log($user, 'delete', 'products', 9, ['product_name' => 'Soap'], null);
        $logger->logAutomatic($user, 'delete', 'products', 9, ['product_name' => 'Soap'], null);

        $this->assertCount(2, $logger->rows);
        $this->assertSame('create', $logger->rows[0]['action']);
        $this->assertSame('delete', $logger->rows[1]['action']);
    }

    public function test_signed_in_updates_are_recorded_and_sign_in_timestamps_are_not(): void
    {
        $logger = new RecordingAuditLogger;
        $this->app->instance(AuditLogger::class, $logger);
        $this->actingAs($this->actor());

        $model = new class extends Model
        {
            protected $table = 'products';

            protected $guarded = [];

            public $timestamps = false;
        };
        $model->id = 5;
        $model->exists = true;
        $model->syncOriginal();
        $model->product_name = 'Soap';
        $model->syncChanges();

        $auditor = $this->app->make(ModelChangeAuditor::class);
        $auditor->updated($model);

        $model->syncOriginal();
        $model->last_login = now();
        $model->syncChanges();
        $auditor->updated($model);

        $this->assertCount(1, $logger->rows);
        $this->assertSame('update', $logger->rows[0]['action']);
        $this->assertSame('products', $logger->rows[0]['table']);
        $this->assertSame('Soap', $logger->rows[0]['new']['product_name']);
    }

    public function test_global_model_events_are_off_by_default(): void
    {
        $this->assertFalse((bool) config('audit.model_events'));

        $logger = new RecordingAuditLogger;
        $this->app->instance(AuditLogger::class, $logger);
        $this->actingAs($this->actor());

        $model = new class extends Model
        {
            protected $table = 'customers';

            protected $guarded = [];

            public $timestamps = false;
        };
        $model->id = 12;
        $model->exists = true;
        $model->syncOriginal();
        $model->customer_name = 'Amina';
        $model->syncChanges();

        Event::dispatch('eloquent.updated: '.$model::class, [$model]);

        $this->assertCount(0, $logger->rows);
    }

    private function actor(): User
    {
        $user = new User;
        $user->id = 4;
        $user->organization_id = 8;
        $user->branch_id = 2;

        return $user;
    }
}

class RecordingAuditLogger extends AuditLogger
{
    /** @var list<array{action: string, table: string, record: string, old: ?array, new: ?array}> */
    public array $rows = [];

    protected function write(
        User $user,
        string $action,
        string $tableName,
        string|int $recordId,
        ?array $oldValues,
        ?array $newValues,
        ?\Illuminate\Http\Request $request,
    ): void {
        $this->rows[] = [
            'action' => $action,
            'table' => $tableName,
            'record' => (string) $recordId,
            'old' => $oldValues,
            'new' => $newValues,
        ];
    }
}
