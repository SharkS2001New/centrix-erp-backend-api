<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\LpoAttachment;

class LpoAttachmentController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return LpoAttachment::class;
    }
}
