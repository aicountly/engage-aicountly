<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\BotActionsModel;

class BotActionsController extends BaseApiController
{
    public function index()
    {
        return $this->ok((new BotActionsModel())->orderBy('category', 'ASC')->orderBy('name', 'ASC')->findAll());
    }
}
