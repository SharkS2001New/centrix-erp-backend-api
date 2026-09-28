<?php

/**
 * Organization licence / subscription Q&A for Assistant + Talk.
 * Teaches get_organization_license for expiry, trial, plan, and renewal questions.
 * Merged into ai_training_foundation via array_merge.
 * Companion: frontend docs/centrix-ai-sample-qa-license.md
 */
return [
    [
        'topic' => 'Q: When does our Centrix licence expire?',
        'content' => 'A: Call get_organization_license. Quote plan, status, expires_at, and days_remaining. Warn if about_to_expire. Never invent dates. Direct renewals to the Centrix administrator.',
        'path' => null,
        'workspace_id' => null,
    ],
    [
        'topic' => 'Q: When does my license expire?',
        'content' => 'A: Same as licence expiry — call get_organization_license (US spelling “license” is the same intent).',
        'path' => null,
        'workspace_id' => null,
    ],
    [
        'topic' => 'Q: How many days are left on our subscription?',
        'content' => 'A: Call get_organization_license; report days_remaining and the expiry date. If 0, say expires today.',
        'path' => null,
        'workspace_id' => null,
    ],
    [
        'topic' => 'Q: Are we still on a Centrix trial?',
        'content' => 'A: Call get_organization_license. If is_trial or status trialing, say yes and quote the trial end. Otherwise name the paid plan and expiry.',
        'path' => null,
        'workspace_id' => null,
    ],
    [
        'topic' => 'Q: What is our licence status?',
        'content' => 'A: Call get_organization_license. Summarize org name, plan, status, expiry, days remaining, seats if present.',
        'path' => null,
        'workspace_id' => null,
    ],
    [
        'topic' => 'Q: Is our Centrix licence about to expire?',
        'content' => 'A: Call get_organization_license. Yes when about_to_expire is true (within warning_days). Include exact date and days left.',
        'path' => null,
        'workspace_id' => null,
    ],
    [
        'topic' => 'Q: Do we have an active Centrix subscription?',
        'content' => 'A: Call get_organization_license. If status missing/expired, say no and that an administrator must activate/renew. If active/trialing/past_due, say yes with expiry.',
        'path' => null,
        'workspace_id' => null,
    ],
    [
        'topic' => 'Q: What plan are we on?',
        'content' => 'A: Call get_organization_license and quote plan_name (and trial flag if any).',
        'path' => null,
        'workspace_id' => null,
    ],
    [
        'topic' => 'Q: How do I renew Centrix?',
        'content' => 'A: Call get_organization_license first so you can state current expiry, then tell the user to contact their Centrix administrator to renew or extend. Do not invent payment links.',
        'path' => null,
        'workspace_id' => null,
    ],
    [
        'topic' => 'Q: How long do we have left on Centrix?',
        'content' => 'A: Call get_organization_license; answer with days_remaining and expires_at.',
        'path' => null,
        'workspace_id' => null,
    ],
    [
        'topic' => 'Q: Subscription renewal — when is it due?',
        'content' => 'A: Call get_organization_license; the due/expiry date is expires_at (and renewal_price if present).',
        'path' => null,
        'workspace_id' => null,
    ],
    [
        'topic' => 'Q: Has our Centrix licence already expired?',
        'content' => 'A: Call get_organization_license. If expired is true or days_remaining < 0 or status expired/missing, say yes and that access needs renewal by a Centrix administrator.',
        'path' => null,
        'workspace_id' => null,
    ],
];
