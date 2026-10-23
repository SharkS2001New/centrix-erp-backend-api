<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\ModelChangeAuditor;
use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use RuntimeException;
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

    public function test_audit_lock_wait_timeout_does_not_escape_to_business_request(): void
    {
        $logger = new class extends AuditLogger
        {
            protected function persistAuditLog(array $attributes): void
            {
                throw new QueryException(
                    'mysql',
                    'insert into `audit_logs` values (?)',
                    [],
                    new RuntimeException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded.'),
                );
            }
        };

        $logger->logAutomatic($this->actor(), 'update', 'customers', 12, [], ['customer_name' => 'Amina']);

        $this->assertTrue(true);
    }

    public function test_non_lock_audit_query_errors_still_propagate(): void
    {
        $logger = new class extends AuditLogger
        {
            protected function persistAuditLog(array $attributes): void
            {
                throw new QueryException(
                    'mysql',
                    'insert into `audit_logs` values (?)',
                    [],
                    new RuntimeException('SQLSTATE[42S02]: Base table or view not found.'),
                );
            }
        };

        $this->expectException(QueryException::class);
        $logger->logAutomatic($this->actor(), 'update', 'customers', 12, [], ['customer_name' => 'Amina']);
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

    public function test_agent_heartbeat_timestamps_are_not_written_to_audit_log(): void
    {
        foreach (['kra_agents', 'attendance_clock_devices'] as $table) {
            $logger = new RecordingAuditLogger;
            $this->app->instance(AuditLogger::class, $logger);
            $this->actingAs($this->actor());

            $model = new class extends Model
            {
                protected $table;

                protected $guarded = [];

                public $timestamps = false;

                public function useTable(string $table): void
                {
                    $this->table = $table;
                }
            };
            $model->useTable($table);
            $model->id = 7;
            $model->exists = true;
            $model->syncOriginal();
            $model->agent_last_seen_at = now();
            $model->syncChanges();

            $this->app->make(ModelChangeAuditor::class)->updated($model);

            $this->assertCount(0, $logger->rows, "Heartbeat update for {$table} should not be audited.");
        }
    }

    public function test_real_agent_status_changes_remain_audited(): void
    {
        $logger = new RecordingAuditLogger;
        $this->app->instance(AuditLogger::class, $logger);
        $this->actingAs($this->actor());

        $model = new class extends Model
        {
            protected $table = 'kra_agents';

            protected $guarded = [];

            public $timestamps = false;
        };
        $model->id = 7;
        $model->exists = true;
        $model->syncOriginal();
        $model->agent_last_seen_at = now();
        $model->comstore_reachable = false;
        $model->syncChanges();

        $this->app->make(ModelChangeAuditor::class)->updated($model);

        $this->assertCount(1, $logger->rows);
        $this->assertSame(['comstore_reachable' => false], $logger->rows[0]['new']);
    }

    public function test_model_events_reach_the_audit_log_for_the_signed_in_user(): void
    {
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

        Event::dispatch('eloquent.updated: '.$model::class, $model);

        $this->assertCount(1, $logger->rows);
        $this->assertSame('customers', $logger->rows[0]['table']);
        $this->assertSame('Amina', $logger->rows[0]['new']['customer_name']);
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
