<?php

namespace dgaidula\downtoll\services;

use Craft;
use craft\base\Component;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use dgaidula\downtoll\elements\Submission;
use dgaidula\downtoll\events\SubmissionEvent;
use dgaidula\downtoll\models\ResourceConfig;
use dgaidula\downtoll\Plugin;

/**
 * Owns the submission lifecycle: ResourceConfig enrichment, payload
 * normalization, the custom EVENT_AFTER_SUBMISSION event, signed
 * config/download tokens, and the session-based access gate.
 */
class Submissions extends Component
{
    /**
     * @event SubmissionEvent Fired after a submission passes validation + reCAPTCHA,
     * before the resource is gated. Integration listeners (the shipped webhook, or a
     * site-side CRM listener) subscribe here.
     */
    public const EVENT_AFTER_SUBMISSION = 'afterSubmission';

    private const SESSION_KEY = 'downtoll.unlocked';

    /** Maps posted (kebab) field names → the Title-Case keys integrations expect. */
    private const FIELD_MAP = [
        'email'                 => 'Email',
        'first-name'            => 'First Name',
        'last-name'             => 'Last Name',
        'state'                 => 'State',
        'affiliation'           => 'Affiliation',
        'school-district-input' => 'School District Input',
        'district-id'           => 'District Id',
        'other-affiliation'     => 'Other Affiliation',
    ];

    /**
     * Fill a ResourceConfig's catalog fields from the plugin-owned FormConfig
     * (content) + global Settings. Called by the field on normalize.
     */
    public function enrich(ResourceConfig $config): void
    {
        $formConfig = Plugin::getInstance()->formConfig;
        $settings = Plugin::getInstance()->getSettings();

        $config->affiliationOptions = $formConfig->getAffiliations();
        $config->triggerValues = $formConfig->triggerValues();

        // Resolve the offered newsletter lists (id + label + hook flag) from the
        // catalog, in catalog order. The hook flag marks routing-modifier boxes.
        $offered = [];
        $hookIds = [];
        foreach ($formConfig->getNewsletterLists() as $list) {
            $listId = $list['listId'] ?? '';
            if (in_array($listId, $config->newsletterListIds, true)) {
                $triggersHook = !empty($list['triggersHook']);
                $offered[] = ['listId' => $listId, 'label' => $list['label'], 'triggersHook' => $triggersHook];
                if ($triggersHook) {
                    $hookIds[] = $listId;
                }
            }
        }
        // PRO gate: multi-list opt-in + `triggersHook` routing modifiers are the
        // "route leads across systems" capability. LITE offers a single plain opt-in
        // checkbox — enough to ask "subscribe me?", not enough to route. Capping here
        // (rather than in the form) means render(), data() and the server-side submit
        // path all agree, so a crafted POST can't smuggle extra list IDs past Lite:
        // anything not in newsletterLists is dropped by the signed token contract.
        if (!Plugin::getInstance()->isPro()) {
            $offered = array_slice($offered, 0, 1);
            foreach ($offered as &$row) {
                $row['triggersHook'] = false;
            }
            unset($row);
            $hookIds = [];
        }

        $config->newsletterLists = $offered;
        $config->newsletterHookIds = $hookIds;

        if ($config->siteName === '') {
            $config->siteName = Craft::$app->getSites()->getCurrentSite()->name;
        }
        // Heading is now per-resource content; just resolve the {site} token (blank
        // stays blank, so the front end hides it).
        $config->newsletterHeading = str_replace('{site}', $config->siteName, $config->newsletterHeading);
        $config->recaptchaFieldName = $settings->recaptchaFieldName ?: 'g-recaptcha-response';

        if ($config->errorMessage === '') {
            $config->errorMessage = $settings->defaultErrorMessage;
        }
    }

    /**
     * Normalize the raw POST payload to the Title-Case contract and validate.
     *
     * @return array{0: array<string,string>, 1: array<string,string>} [fields, errors]
     */
    public function normalizeAndValidate(array $payload, ResourceConfig $config): array
    {
        $fields = [];
        foreach (self::FIELD_MAP as $in => $canonical) {
            if (isset($payload[$in]) && trim((string) $payload[$in]) !== '') {
                $fields[$canonical] = trim((string) $payload[$in]);
            }
        }

        // Newsletter: capture which OFFERED lists were checked (array of list IDs).
        $checked = array_values(array_intersect(
            (array) ($payload['newsletter-subscribe'] ?? []),
            $config->newsletterListIds
        ));
        if ($checked) {
            $fields['Newsletter Lists'] = $checked;
        }

        // Required-field validation is driven by the resource's configured set
        // (POST key → [canonical key, human label]); trusted from the signed token.
        $requiredMap = [
            'email'      => ['Email', 'Email'],
            'first-name' => ['First Name', 'First name'],
            'last-name'  => ['Last Name', 'Last name'],
            'state'      => ['State', 'US State'],
        ];
        $errors = [];
        foreach ($config->requiredFields as $key) {
            if (!isset($requiredMap[$key])) {
                continue;
            }
            [$canonical, $label] = $requiredMap[$key];
            if (empty($fields[$canonical])) {
                $errors[$canonical] = "{$label} is required.";
            }
        }
        if (!empty($fields['Email']) && !filter_var($fields['Email'], FILTER_VALIDATE_EMAIL)) {
            $errors['Email'] = 'Please enter a valid email address.';
        }

        return [$fields, $errors];
    }

    /**
     * Builds the submission event and — on PRO — fires it for integration listeners.
     *
     * PRO gate: the event is the "integrate with anything" capability, which is what Pro
     * sells. On LITE the event is NOT triggered, so no listener (including the shipped
     * webhook) runs. The event object is still built and returned so the caller's contract
     * is unchanged: $event->isValid stays true and gating proceeds normally. Lite captures
     * the lead via storage + notification instead.
     */
    public function fireAfterSubmission(array $fields, ResourceConfig $config, array $rawPayload): SubmissionEvent
    {
        // Which checked boxes are routing modifiers (flagged triggersHook).
        $checked = (array) ($fields['Newsletter Lists'] ?? []);
        $triggeredHooks = array_values(array_intersect($checked, $config->newsletterHookIds));

        $event = new SubmissionEvent([
            'fields'         => $fields,
            'config'         => $config,
            'rawPayload'     => $rawPayload,
            'triggeredHooks' => $triggeredHooks,
            'downloadName'   => $this->resolveDownloadName($config),
        ]);

        if (Plugin::getInstance()->isPro()) {
            $this->trigger(self::EVENT_AFTER_SUBMISSION, $event);
        }

        return $event;
    }

    /**
     * Persist a validated submission as a Submission element (the CP lead index).
     *
     * Maps the normalized Title-Case contract onto the element's columns and keeps
     * the FULL payload as JSON, so new form fields are captured without a
     * migration. Available on BOTH editions — lead capture is core, not Pro.
     *
     * @param array<string,mixed> $fields The normalized Title-Case payload
     * @return Submission|null The saved element, or null on failure (logged)
     */
    public function store(array $fields, ResourceConfig $config, ?string $downloadName): ?Submission
    {
        $submission = new Submission();
        $submission->email = $fields['Email'] ?? null;
        $submission->firstName = $fields['First Name'] ?? null;
        $submission->lastName = $fields['Last Name'] ?? null;
        $submission->state = $fields['State'] ?? null;
        $submission->affiliation = $fields['Affiliation'] ?? null;
        $submission->otherAffiliation = $fields['Other Affiliation'] ?? null;
        $submission->schoolDistrict = $fields['School District Input'] ?? null;
        $submission->districtId = $fields['District Id'] ?? null;
        $submission->downloadName = $downloadName;
        $submission->resourceId = (int) $config->resourceId ?: null;
        $submission->siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $submission->setNewsletterLists($fields['Newsletter Lists'] ?? null);
        $submission->payload = Json::encode($fields);

        if (!Craft::$app->getElements()->saveElement($submission)) {
            Craft::error(
                'Failed to save submission element: ' . Json::encode($submission->getErrors()),
                'downtoll'
            );
            return null;
        }

        return $submission;
    }

    /**
     * Hard-delete stored submissions older than the retention window (a real PII
     * purge — bypasses the trash). Runs during Craft's garbage collection and via
     * `php craft downtoll/submissions/purge`.
     *
     * The canonical age is the ELEMENT's dateCreated (the {{%elements}} row),
     * which the element query exposes as `dateCreated`.
     *
     * @param int|null $days Retention window in days. Null reads the
     * `submissionRetentionDays` setting; 0 or less = retention disabled (no-op).
     * @return int The number of submissions actually deleted
     */
    public function purgeExpired(?int $days = null): int
    {
        $days ??= Plugin::getInstance()->getSettings()->submissionRetentionDays;
        if ($days <= 0) {
            return 0;
        }

        $cutoff = (new \DateTime())->sub(new \DateInterval("P{$days}D"));

        // Collect ids first (cheap), then load + delete one element at a time —
        // avoids both loading every element up front and paginating a result set
        // that shrinks as it is deleted.
        $ids = Submission::find()
            ->status(null)
            ->dateCreated('< ' . Db::prepareDateForDb($cutoff))
            ->ids();

        $elements = Craft::$app->getElements();
        $deleted = 0;

        foreach ($ids as $id) {
            try {
                $submission = Submission::find()->status(null)->id($id)->one();
                // `true` = hard delete: skip the trash so the PII is actually gone.
                if ($submission && $elements->deleteElement($submission, true)) {
                    $deleted++;
                }
            } catch (\Throwable $e) {
                // One bad row must not abort the whole purge.
                Craft::error(
                    "Failed to purge expired submission {$id}: {$e->getMessage()}",
                    'downtoll'
                );
            }
        }

        return $deleted;
    }

    /**
     * Resolve the gated download's human-readable name from the (signed, trusted)
     * asset id — title first, falling back to the filename. Never trusts a
     * client-posted value, so the captured "which file" can't be spoofed.
     */
    private function resolveDownloadName(ResourceConfig $config): ?string
    {
        if (!$config->assetId) {
            return null;
        }
        $asset = Craft::$app->getAssets()->getAssetById((int) $config->assetId);
        if (!$asset) {
            return null;
        }

        return $asset->title ?: $asset->filename;
    }

    // --- Access gate (server-trusted) ---

    public function grantAccess(ResourceConfig $config): void
    {
        $session = Craft::$app->getSession();
        $unlocked = $session->get(self::SESSION_KEY, []);
        $unlocked[(string) $config->resourceId] = true;
        $session->set(self::SESSION_KEY, $unlocked);
    }

    public function hasAccess(ResourceConfig $config): bool
    {
        $unlocked = Craft::$app->getSession()->get(self::SESSION_KEY, []);
        return !empty($unlocked[(string) $config->resourceId]);
    }

    // --- Signed tokens (tamper-proof via Craft's Security component) ---

    public function encodeConfigToken(ResourceConfig $config): string
    {
        return Craft::$app->getSecurity()->hashData(Json::encode($config->toTokenArray()));
    }

    public function decodeConfigToken(string $token): ?ResourceConfig
    {
        if ($token === '') {
            return null;
        }
        $json = Craft::$app->getSecurity()->validateData($token);
        if ($json === false) {
            return null;
        }

        return ResourceConfig::fromTokenArray(Json::decode($json));
    }

    public function signedDownloadUrl(ResourceConfig $config): ?string
    {
        if (!$config->assetId) {
            return null;
        }
        $ttl = Plugin::getInstance()->getSettings()->downloadTtl;
        $token = Craft::$app->getSecurity()->hashData(Json::encode([
            'a'   => $config->assetId,
            'exp' => time() + $ttl,
        ]));

        return UrlHelper::actionUrl('downtoll/download', ['t' => $token]);
    }

    public function validateDownloadToken(string $token): ?int
    {
        $json = Craft::$app->getSecurity()->validateData($token);
        if ($json === false) {
            return null;
        }
        $data = Json::decode($json);
        if (empty($data['a']) || empty($data['exp']) || $data['exp'] < time()) {
            return null;
        }

        return (int) $data['a'];
    }
}
