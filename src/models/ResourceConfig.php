<?php

namespace dgaidula\downtoll\models;

use craft\base\Model;

/**
 * Per-resource form configuration.
 *
 * The per-entry fields (resourceId, assetId, successMode, include* flags,
 * newsletterListIds) come from the {@see \dgaidula\downtoll\fields\GatedContent}
 * field value (content). The catalog fields (affiliationOptions,
 * triggerValues, newsletterLists, newsletterHeading) are enriched from
 * {@see FormConfig} at render time. A trimmed, signed subset is round-tripped as
 * the hidden `_gcConfig` token so the server can trust the editor's choices on submit.
 */
class ResourceConfig extends Model
{
    // --- Per-entry (from the field value) ---
    public int|string $resourceId = '';
    public ?int $assetId = null;
    public string $successMode = 'swap';
    public bool $includeAffiliation = true;
    public bool $includeNewsletter = true;

    /**
     * @var string[] Which standard fields are required (by POST key). Renders the
     * `*` marker + `required` attribute and is enforced server-side. Round-tripped
     * in the signed token so the server trusts the editor's choice on submit.
     */
    public array $requiredFields = ['first-name', 'last-name', 'email'];

    /** Optional extra CSS class(es) added to the form wrapper (editor styling hook). */
    public string $cssClass = '';

    /** Optional message shown above the download button on success. */
    public string $successMessage = '';
    /** Message shown on failure (falls back to the global default if blank). */
    public string $errorMessage = '';

    /** Selected newsletter list identifiers offered on this resource (from the catalog). */
    public array $newsletterListIds = [];

    // --- Enriched from FormConfig / Settings at render ---
    /** @var array<int,array{listId:string,label:string,triggersHook:bool}> The offered lists, with labels. */
    public array $newsletterLists = [];

    /**
     * @var string[] The subset of offered list IDs flagged `triggersHook` — i.e.
     * routing MODIFIERS that fire the submission hook rather than a plain opt-in.
     * Round-tripped in the signed token so the server can trust it on submit.
     */
    public array $newsletterHookIds = [];

    /**
     * Heading shown above the newsletter checkboxes. PER-RESOURCE content (editable
     * on prod); supports the `{site}` token. Blank = no heading rendered. Defaults
     * to the global Settings heading when a field has never been configured.
     */
    public string $newsletterHeading = '';
    public string $siteName = '';

    /** Submitted field name for the reCAPTCHA token (obfuscatable; from Settings). */
    public string $recaptchaFieldName = 'g-recaptcha-response';

    /** @var array<int,array{label:string,value:string,triggersHook:bool}> */
    public array $affiliationOptions = [];

    /** @var string[] Affiliation values whose option fires the front-end hook. */
    public array $triggerValues = [];

    public function rules(): array
    {
        return [
            [['resourceId'], 'required'],
            [['successMode'], 'in', 'range' => ['swap', 'reload']],
            [['assetId'], 'integer'],
        ];
    }

    /** The minimal, server-trusted subset embedded in the signed `_gcConfig` token. */
    public function toTokenArray(): array
    {
        return [
            'r'   => $this->resourceId,
            'a'   => $this->assetId,
            'm'   => $this->successMode,
            'ia'  => $this->includeAffiliation,
            'in'  => $this->includeNewsletter,
            'nls' => $this->newsletterListIds,
            'nlh' => $this->newsletterHookIds,
            'rf'  => $this->requiredFields,
            'dlv' => $this->triggerValues,
        ];
    }

    public static function fromTokenArray(array $data): self
    {
        $config = new self();
        $config->resourceId = $data['r'] ?? '';
        $config->assetId = isset($data['a']) ? (int) $data['a'] : null;
        $config->successMode = $data['m'] ?? 'swap';
        $config->includeAffiliation = (bool) ($data['ia'] ?? true);
        $config->includeNewsletter = (bool) ($data['in'] ?? true);
        $config->newsletterListIds = $data['nls'] ?? [];
        $config->newsletterHookIds = $data['nlh'] ?? [];
        $config->requiredFields = $data['rf'] ?? ['first-name', 'last-name', 'email'];
        $config->triggerValues = $data['dlv'] ?? [];

        return $config;
    }
}
