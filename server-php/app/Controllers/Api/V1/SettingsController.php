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

    /**
     * PUT v1/settings (super_admin). The Settings screen saves one setting at a time as {key, value}; a map
     * {name: value, …} sets several at once. The {key, value} form used to be stored as two settings literally named
     * "key" and "value", so an edit made on the Settings screen never reached the setting it was meant for.
     */
    public function update()
    {
        $body = $this->input();
        if (count($body) === 2 && array_key_exists('key', $body) && array_key_exists('value', $body) && is_string($body['key'])) {
            $key = trim($body['key']);
            if ($key === '' || strlen($key) > 96) {
                return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'code' => 'validation_failed', 'error' => 'Name the setting to change.',
                    'details' => ['key' => 'Required (at most 96 characters).']]);
            }
            $body = [$key => $body['value']];
        }
        $m = new SettingsModel();
        foreach ($body as $key => $val) {
            $m->setSetting((string) $key, $val, $this->user()['id'] ?? null);
        }
        $this->audit('settings_update', ['metadata' => ['keys' => array_keys($body)]]);
        return $this->ok((new SettingsModel())->all());
    }
}
