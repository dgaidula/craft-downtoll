<?php

namespace dgaidula\downtoll\models;

use craft\base\Model;

/**
 * Global plugin settings (PROJECT CONFIG — dev-owned, locked on prod when
 * `allowAdminChanges` is false; that's intentional, these are deploy-time
 * values). Editor-facing form structure lives in {@see FormConfig} instead.
 *
 * No secrets are stored here. Credential fields accept either a literal value
 * or an `$ENV_VAR` reference (Craft autosuggest), keeping the plugin
 * self-contained for the Plugin Store without bundling keys.
 */
class Settings extends Model
{
    // --- General ---

    /**
     * Overrides the plugin's name in the CP — the sidebar nav item, the Plugins
     * screen, and the settings breadcrumb. Blank = "Downtoll".
     *
     * Its purpose is a seamless rename: a site that has always called this feature
     * something else (e.g. "Gated Content") can keep that label so editors never see
     * the internal package name change. Set it in the site's `config/downtoll.php`
     * so it is version-controlled and survives with allowAdminChanges off.
     */
    public string $pluginName = '';

    /** Default per-resource success UX: 'swap' (AJAX download button) or 'reload'. */
    public string $defaultSuccessMode = 'swap';

    /**
     * DEFAULT newsletter heading, pre-filled into a Gated Content field the first
     * time it is configured. Thereafter the heading is per-resource content (blank
     * = hidden). "{site}" is replaced with the site name.
     */
    public string $newsletterHeading = 'Which newsletters would you like to subscribe to?';

    /** Fallback message shown when a submission fails (per-resource override available). */
    public string $defaultErrorMessage = 'Something went wrong. Please try again.';

    /**
     * Retention window for stored submissions, in days. 0 = keep submissions
     * forever (no purge); a positive N = hard-delete submissions older than
     * N days (runs during Craft's garbage collection). Submissions hold PII
     * (names, emails), so a bounded window is the privacy-friendly default
     * once you no longer need old leads.
     */
    public int $submissionRetentionDays = 0;

    // --- Defaults for new gated pages (PRO) ---

    /**
     * PRO. The full default {@see ResourceConfig} state a BRAND-NEW (never-saved)
     * Gated Content field initializes from, so an editor only has to pick the asset
     * on a fresh page. Stored as the same serialized shape
     * {@see \dgaidula\downtoll\fields\GatedContent::serializeValue()} produces
     * (successMode, includeAffiliation, includeNewsletter, newsletterListIds,
     * requiredFields, cssClass, newsletterHeading, successMessage, errorMessage) —
     * EXCEPT an asset is never defaulted (`assetId` is always forced to null).
     *
     * Empty (the default) = no full-state default; new fields fall back to the
     * hardcoded fresh-field defaults (affiliation ON, newsletter OFF). Lite ignores
     * this entirely. Project config, dev-owned like every other setting here; the
     * posted value is canonicalized in {@see self::normalizeDefaultResourceConfig()}.
     *
     * @var array<string,mixed>
     */
    public array $defaultResourceConfig = [];

    // --- Notifications ---

    /**
     * Email each captured lead to a human. This is the LITE path to "the lead reached
     * someone": without it (and without a Pro integration listener) a submission would
     * vanish the moment the download was served.
     */
    public bool $notifyEnabled = false;

    /**
     * Where the lead notification goes. Comma-separated; literal or `$ENV_VAR`
     * (resolved server-side, so an address never ships in page HTML).
     */
    public string $notifyRecipients = '';

    /**
     * Subject line. `{download}` is replaced with the gated file's name (resolved
     * server-side from the signed asset id) and `{site}` with the site name.
     */
    public string $notifySubject = 'New download lead: {download}';

    /**
     * Optional From address — literal or `$ENV_VAR`. Blank falls back to Craft's system
     * From. Set this if the system From isn't configured: an empty From makes the mailer
     * reject the whole message.
     */
    public string $notifyFrom = '';

    // --- Integrations: reCAPTCHA ---

    /** reCAPTCHA v3 secret — literal or `$ENV_VAR`. Empty disables server-side verification. */
    public string $recaptchaSecret = '$RECAPTCHA_V3_PRIVATE_KEY';

    /** reCAPTCHA v3 site key (public) — literal or `$ENV_VAR`. */
    public string $recaptchaSiteKey = '$RECAPTCHA_V3_PUBLIC_KEY';

    /** Minimum passing score. */
    public float $recaptchaMinScore = 0.5;

    // --- Integrations: generic webhook (the shipped, integration-agnostic hook) ---

    /** When true, each successful submission is POSTed to the webhook URL. */
    public bool $webhookEnabled = false;

    /** Destination URL for submission webhooks — literal or `$ENV_VAR`. */
    public string $webhookUrl = '';

    /** Optional shared secret — literal or `$ENV_VAR`. If set, payloads are HMAC-signed. */
    public string $webhookSecret = '';

    // --- Integrations: district lookup ---

    /**
     * Endpoint backing the district typeahead on the SHIPPED form — emitted as a
     * `data-lookup-endpoint` attribute for your front-end JS to query. Literal or
     * `$ENV_VAR`; it is resolved server-side, so an env reference never ships in
     * page HTML.
     *
     * EMPTY (the default) = no attribute is rendered and the district input degrades
     * to a plain text field, which still submits as `School District Input`. The
     * plugin ships no typeahead JS — the consuming site owns that behavior.
     */
    public string $districtLookupEndpoint = '';

    // --- Advanced ---

    /**
     * The form field name the reCAPTCHA token is submitted under. Defaults to
     * Google's standard `g-recaptcha-response` (works out of the box). Set a
     * NON-OBVIOUS value (e.g. "gc-token") to deter bots that auto-fill the
     * standard field — they then fail verification (empty/invalid token).
     */
    public string $recaptchaFieldName = 'g-recaptcha-response';

    /** reCAPTCHA action allow-list (empty = accept any action). */
    public array $recaptchaAllowedActions = [];

    /** Signed-download link lifetime, seconds. */
    public int $downloadTtl = 900;

    public function rules(): array
    {
        return [
            [['defaultSuccessMode'], 'in', 'range' => ['swap', 'reload']],
            [['recaptchaMinScore'], 'number', 'min' => 0, 'max' => 1],
            [['downloadTtl'], 'integer', 'min' => 30],
            [['submissionRetentionDays'], 'integer', 'min' => 0],
            [['webhookUrl'], 'validateWebhook'],
            [['notifyRecipients'], 'validateNotifyRecipients'],
            // Marks the attribute safe for setAttributes() AND canonicalizes the
            // posted default into a clean, typed shape before it hits project config.
            [['defaultResourceConfig'], 'normalizeDefaultResourceConfig'],
        ];
    }

    /**
     * Canonicalize the posted `defaultResourceConfig` into the same serialized shape
     * a saved field value uses, so project config holds a clean, typed record (bools
     * as bools, filtered arrays) and never an asset. Runs during validate(), which
     * `Plugins::savePluginSettings()` calls before persisting; idempotent, so
     * re-validating an already-clean value is a no-op.
     */
    public function normalizeDefaultResourceConfig(string $attribute): void
    {
        $data = $this->defaultResourceConfig;
        if (!is_array($data) || $data === []) {
            $this->defaultResourceConfig = [];
            return;
        }

        $config = ResourceConfig::fromFieldData($data, $this);
        $this->defaultResourceConfig = [
            'assetId'            => null,
            'successMode'        => $config->successMode,
            'includeAffiliation' => $config->includeAffiliation,
            'includeNewsletter'  => $config->includeNewsletter,
            'newsletterListIds'  => $config->newsletterListIds,
            'requiredFields'     => $config->requiredFields,
            'cssClass'           => $config->cssClass,
            'newsletterHeading'  => $config->newsletterHeading,
            'successMessage'     => $config->successMessage,
            'errorMessage'       => $config->errorMessage,
        ];
    }

    public function validateWebhook(string $attribute): void
    {
        if ($this->webhookEnabled && trim($this->webhookUrl) === '') {
            $this->addError($attribute, 'A webhook URL is required when the webhook integration is enabled.');
        }
    }

    public function validateNotifyRecipients(string $attribute): void
    {
        if (!$this->notifyEnabled) {
            return;
        }

        $raw = trim($this->notifyRecipients);
        if ($raw === '') {
            $this->addError($attribute, 'At least one recipient is required when lead notifications are enabled.');
            return;
        }

        // An `$ENV_VAR` reference can't be validated here — it resolves at send time.
        if (str_starts_with($raw, '$')) {
            return;
        }

        foreach (array_filter(array_map('trim', explode(',', $raw))) as $address) {
            if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
                $this->addError($attribute, "“{$address}” is not a valid email address.");
            }
        }
    }
}
