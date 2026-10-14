<?php

namespace Tests\Feature;

use App\Models\AuditLogsModel;
use App\Models\ConsoleSyncStatusModel;
use App\Models\LeadActivitiesModel;
use App\Models\LeadsModel;
use App\Models\WorkerHealthModel;
use App\Services\JsonColumnRepairService;
use Config\Database;
use Tests\Support\DatabaseTestCase;

/**
 * JSON columns round-trip through the models: a writer's array is stored once (a JSON object, not a
 * JSON string), SQL NULL reads as null (non-nullable casts used to throw), rows an older release
 * stored double-encoded still read as arrays, and engage:repair-json rewrites them.
 *
 * @internal
 */
final class JsonColumnsTest extends DatabaseTestCase
{
    private function jsonType(string $table, string $column, int $id): ?string
    {
        return Database::connect()->query("SELECT jsonb_typeof({$column}) AS t FROM {$table} WHERE id = ?", [$id])->getRow()->t ?? null;
    }

    public function testModelWritersStoreDocumentsOnce(): void
    {
        $leads = new LeadsModel();
        $leadId = (int) $leads->insert(['name' => 'Asha Traders', 'metadata' => ['source' => 'reach', 'tags' => ['gst']]], true);
        $this->assertSame('object', $this->jsonType('engage_leads', 'metadata', $leadId));
        $this->assertEquals(['source' => 'reach', 'tags' => ['gst']], $leads->find($leadId)['metadata']); // JSONB orders keys its own way

        // Models that used to json_encode() themselves before insert().
        $act = (new LeadActivitiesModel())->record($leadId, 'note', 'Called', null, ['metadata' => ['minutes' => 4]]);
        $this->assertSame('object', $this->jsonType('engage_lead_activities', 'metadata', $act));
        $this->assertSame(['minutes' => 4], (new LeadActivitiesModel())->find($act)['metadata']);

        $wh = (new WorkerHealthModel())->record(['job_kind' => 'screenshot', 'metadata' => ['ok' => true]]);
        $this->assertSame('object', $this->jsonType('engage_worker_health', 'metadata', $wh));

        $sync = (new ConsoleSyncStatusModel())->record(['event_kind' => 'approval_request', 'request_payload' => ['a' => 1], 'response_payload' => ['b' => 2]]);
        $this->assertSame(['object', 'object'], [$this->jsonType('engage_console_sync_status', 'request_payload', $sync), $this->jsonType('engage_console_sync_status', 'response_payload', $sync)]);
        $this->assertSame(['a' => 1], (new ConsoleSyncStatusModel())->find($sync)['request_payload']);
    }

    public function testNullAndLegacyRowsReadAndRepair(): void
    {
        $leads = new LeadsModel();
        $plain = (int) $leads->insert(['name' => 'No metadata'], true);
        $this->assertNull($leads->find($plain)['metadata'], 'SQL NULL reads as null');

        $db = Database::connect();
        $db->query("INSERT INTO engage_leads (name, metadata) VALUES ('Legacy', to_jsonb(?::text))", ['{"source":"old"}']);
        $legacy = (int) $db->insertID();
        $this->assertSame('string', $this->jsonType('engage_leads', 'metadata', $legacy));
        $this->assertSame(['source' => 'old'], $leads->find($legacy)['metadata'], 'a double-encoded row still reads as the document');

        $dry = (new JsonColumnRepairService($db))->run(false, 'engage_leads');
        $this->assertSame(1, array_sum(array_column($dry, 'candidates')));
        $this->assertSame('string', $this->jsonType('engage_leads', 'metadata', $legacy), 'dry run changes nothing');
        $run = (new JsonColumnRepairService($db))->run(true, 'engage_leads');
        $this->assertSame(1, array_sum(array_column($run, 'repaired')));
        $this->assertSame('object', $this->jsonType('engage_leads', 'metadata', $legacy));
        $this->assertSame(0, array_sum(array_column((new JsonColumnRepairService($db))->run(false), 'candidates')), 'idempotent');

        $audit = (int) (new AuditLogsModel())->insert(['event' => 'zz.test', 'created_at' => date('Y-m-d H:i:s')], true);
        $this->assertNull((new AuditLogsModel())->find($audit)['metadata']);
    }
}
