<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\SettingsModel;
use Config\Services;

class BotSettingsController extends BaseApiController
{
    public function index()
    {
        $m = new SettingsModel();
        return $this->ok([
            'mode'                 => $m->getSetting('bot_mode', 'confirm'),
            'allowed_auto_actions' => (array) $m->getSetting('allowed_auto_actions', []),
        ]);
    }

    public function update()
    {
        $body = $this->input();
        $mode = $body['mode'] ?? null;
        if ($mode !== null && ! in_array($mode, ['confirm', 'auto'], true)) {
            return $this->fail('mode must be one of: confirm | auto.', 400);
        }

        $m = new SettingsModel();
        if ($mode !== null) {
            $m->setSetting('bot_mode', $mode, $this->user()['id'] ?? null);
            $this->audit('auto_mode.change', ['metadata' => ['mode' => $mode], 'fanout_console' => true]);
            try {
                Services::consoleClient()->sendModeStatus($mode);
            } catch (\Throwable $e) {
                log_message('error', 'Console mode fan-out failed: ' . $e->getMessage());
            }
        }

        if (array_key_exists('allowed_auto_actions', $body)) {
            $codes = array_values(array_filter((array) $body['allowed_auto_actions'], 'is_string'));
            $m->setSetting('allowed_auto_actions', $codes, $this->user()['id'] ?? null);
        }

        return $this->ok([
            'mode'                 => $m->getSetting('bot_mode', 'confirm'),
            'allowed_auto_actions' => (array) $m->getSetting('allowed_auto_actions', []),
        ]);
    }
}
