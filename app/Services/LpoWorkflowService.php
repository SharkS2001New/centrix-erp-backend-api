<?php

namespace App\Services;

/**
 * Backward-compatible alias — some callers resolve the workflow service from App\Services.
 *
 * @see \App\Services\Purchasing\LpoWorkflowService
 */
class LpoWorkflowService extends Purchasing\LpoWorkflowService {}
