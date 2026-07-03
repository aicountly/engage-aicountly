<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\SettingsModel;

class SettingsController extends BaseApiController
{
    public function index()
    {
        return $this->ok((new SettingsModel())->all());
    }

    public function update()
    {
        $body = $this->input();
        $m = new SettingsModel();
        foreach ($body as $key => $val) {
            $m->setSetting((string) $key, $val, $this->user()['id'] ?? null);
        }
        $this->audit('settings_update', ['metadata' => ['keys' => array_keys($body)]]);
        return $this->ok((new SettingsModel())->all());
    }
}
