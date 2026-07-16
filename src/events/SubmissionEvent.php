<?php

namespace dgaidula\downtoll\events;

use dgaidula\downtoll\models\ResourceConfig;
use yii\base\Event;

/**
 * Fired by Submissions after a submission passes validation + reCAPTCHA.
 *
 * Listeners — the shipped {@see \dgaidula\downtoll\services\WebhookIntegration},
 * or a site-side CRM listener — read $fields, may set $contactId, and may flip
 * $isValid to false to abort gating on a hard integration failure.
 */
class SubmissionEvent extends Event
{
    /** Normalized, Title-Case payload matching the existing integration contract. */
    public array $fields = [];

    /** The per-resource configuration that produced this form. */
    public ResourceConfig $config;

    /** Raw, untrusted request payload (for listeners that need extra keys). */
    public array $rawPayload = [];

    /**
     * @var string[] The checked checkboxes flagged `triggersHook` (list IDs) — the
     * routing-significant boxes. A listener branches on these (e.g. classify the
     * lead, gate downstream subscriptions) without parsing visitor-facing labels.
     */
    public array $triggeredHooks = [];

    /**
     * The human-readable name of the gated download, resolved SERVER-SIDE from the
     * signed asset id (never a client-posted field). Null when the resource gates
     * no asset. Listeners can store this with the lead (e.g. "which file").
     */
    public ?string $downloadName = null;

    /** Set by a listener (e.g. the Salesforce Contact Id); flows into the gating record. */
    public ?string $contactId = null;

    /** A listener may set this false to block gating on a hard integration failure. */
    public bool $isValid = true;

    /** Free-form bag for listeners to stash extra outcome data. */
    public array $integrationResults = [];
}
