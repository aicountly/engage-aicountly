<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\BotQueueModel;
use Config\Services;

class BotQueueController extends BaseApiController
{
    private BotQueueModel $m;

    public function __construct()
    {
        $this->m = new BotQueueModel();
    }

    public function index()
    {
        $q = $this->request->getGet();
        [$page, $limit, $offset] = $this->paging();
        $qb = $this->m->orderBy('id', 'DESC');
        if (! empty($q['action']))      { $qb->where('action', $q['action']); }
        if (! empty($q['status']))      { $qb->where('status', $q['status']); }
        if (! empty($q['entity_kind'])) { $qb->where('entity_kind', $q['entity_kind']); }
        if (! empty($q['entity_id']))   { $qb->where('entity_id',  (string) $q['entity_id']); }
        return $this->ok(['items' => $qb->findAll($limit, $offset), 'page' => $page, 'limit' => $limit]);
    }

    public function create()
    {
        $data = $this->input();
        if (empty($data['action'])) return $this->fail('action is required.', 400);
        $result = Services::salesBot()->dispatch((string) $data['action'], (array) ($data['payload'] ?? []));
        $this->audit('bot.dispatch', [
            'subject_kind' => 'bot_queue', 'subject_id' => $result['queue_id'] ?? null,
            'metadata'     => ['action' => $data['action']],
            'fanout_console' => true,
        ]);
        return $this->ok($result, 201);
    }

    public function retry($id = null)
    {
        $row = $this->m->find((int) $id);
        if (! $row) return $this->fail('Queue entry not found.', 404);

        $result = Services::salesBot()->dispatch(
            (string) $row['action'],
            (array) ($row['payload'] ?? [])
        );
        $this->m->update((int) $id, [
            'status'   => 'retried',
            'attempts' => (int) ($row['attempts'] ?? 0) + 1,
        ]);
        return $this->ok($result);
    }
}
