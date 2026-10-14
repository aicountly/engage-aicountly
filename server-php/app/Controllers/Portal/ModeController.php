<?php

namespace App\Controllers\Portal;

use App\Controllers\BaseApiController;
use App\Models\SettingsModel;

/**
 * PUT /api/v1/portal/bot/mode — Console pushes a new bot mode into Engage.
 */
class ModeController extends BaseApiController
{
    public function update()
    {
        $body = $this->input();
        $mode = (string) ($body['mode'] ?? '');
        if (! in_array($mode, ['confirm', 'auto'], true)) {
            return $this->fail('mode must be one of: confirm | auto.', 400);
        }

        $m = new SettingsModel();
        $m->setSetting('bot_mode', $mode, null);

        $this->audit('auto_mode.change', [
            'actor_kind' => 'console',
            'metadata'   => ['mode' => $mode, 'source' => 'console_push'],
        ]);

        // The response is the acknowledgement Console gets (Console has no mode-status route).

        return $this->ok(['mode' => $mode]);
    }
}
