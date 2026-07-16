<?php

namespace modules\examples;

use Craft;
use craft\helpers\App;
use craft\mail\Message;
use CS_REST_Subscribers;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use dgaidula\downtoll\events\SubmissionEvent;
use dgaidula\downtoll\services\Submissions;
use yii\base\Event;
use yii\base\Module;

/**
 * REFERENCE EXAMPLE — a site-side CRM/ESP listener for the Downtoll plugin.
 *
 * The plugin ships integration-agnostic: it validates the submission, verifies
 * reCAPTCHA, resolves the download name, and fires
 * `Submissions::EVENT_AFTER_SUBMISSION`. Everything vendor-specific — which CRM,
 * which fields, which lists — lives in a listener like this one, in YOUR site.
 * That is why the plugin has no "CRM settings" tab to outgrow.
 *
 * This example is adapted from a production listener and does three things:
 *
 *   1. Upsert a CRM Contact, keyed by email (find-then-PATCH, else POST).
 *   2. Create ONE timestamped engagement record per download, so engagement
 *      history accumulates instead of being overwritten by the latest file.
 *   3. Route the lead to an ESP: opt-ins go to the checked list(s); non-opt-ins
 *      go to a dedicated drip list.
 *
 * It is written against Salesforce (REST) + Campaign Monitor because a concrete
 * stack teaches better than pseudocode. Swap the two vendor sections for your
 * own and the surrounding structure still applies — see README.md in this
 * directory, "Adapting this to your stack".
 *
 * EVERY id, key, and secret comes from an environment variable. Nothing is
 * hardcoded. That is deliberate: it is what lets one identical listener serve
 * two different sites/brands off the same codebase, and it is the property to
 * preserve when you adapt this.
 *
 * Every CRM field API name below (`Affiliation__c`, `Platform_Registration__c`,
 * `pmdm__ProgramEngagement__c`, …) is one customer's schema, NOT an interface of
 * this plugin. Replace each with your own object/field API names — they are
 * flagged inline.
 *
 * Register it like any Craft module, in `config/app.php`, with `bootstrap` set
 * so `init()` runs on every request and the handler is attached before a
 * submission can arrive:
 *
 *     'modules' => [
 *         'downtoll-integration' => \modules\examples\GatedContentIntegration::class,
 *     ],
 *     'bootstrap' => ['downtoll-integration'],
 */
class GatedContentIntegration extends Module
{
    /** Cache key + TTL for the CRM OAuth token. Re-authing per submission is wasteful and rate-limited. */
    private const TOKEN_CACHE_KEY = 'downtoll.crmToken';
    private const TOKEN_TTL = 7000;

    /**
     * OPTIONAL per-submission debug capture. Only assembled + emailed/logged when
     * the GATED_CONTENT_DEBUG_EMAIL env var is set (off by default, and it should
     * stay off in production once you are live). It exists to surface the raw CRM
     * and ESP responses — including the CRM error BODY that the catch blocks would
     * otherwise truncate to a generic message — without SSH log-digging. Delete it
     * if you do not want it; nothing else depends on it.
     */
    private array $debug = [];

    public function init(): void
    {
        parent::init();

        // Class-level handler: catches the event triggered by the plugin's
        // Submissions component without needing a reference to the instance.
        Event::on(
            Submissions::class,
            Submissions::EVENT_AFTER_SUBMISSION,
            [$this, 'handleSubmission']
        );
    }

    public function handleSubmission(SubmissionEvent $event): void
    {
        $config = $event->config;
        $fields = $event->fields;

        // --- Server-side tamper guard ---------------------------------------
        // The form only reveals the district lookup for affiliations the editor
        // flagged as hook-triggering. A crafted POST could still carry a lookup
        // id alongside an affiliation that has no lookup. Trust the signed config
        // ($config->triggerValues), not the client: drop the id when the two
        // disagree. Apply this pattern to ANY client-posted field whose presence
        // is supposed to be conditional on another answer.
        $affiliation = $fields['Affiliation'] ?? null;
        if ($affiliation !== null
            && $config->triggerValues
            && !in_array($affiliation, $config->triggerValues, true)
        ) {
            unset($fields['District Id'], $fields['School District Input']);
        }

        // --- Routing facts, derived from the plugin's generic checkbox model --

        // A `triggersHook` box is a routing MODIFIER, not an opt-in. Checked =>
        // classify the lead. Here: one hook box meaning "I work in this field".
        $isProfessional = !empty($event->triggeredHooks);
        $classification = $isProfessional ? 'School Food Professional' : 'Parent/Advocate';

        // Newsletter opt-in gate: a PLAIN (non-hook) box must be checked for any
        // ESP subscribe. array_diff() is what separates the two kinds of box —
        // everything checked, minus the modifiers, is what the visitor actually
        // opted into. A hook box on its own classifies the lead in the CRM and
        // subscribes them to nothing.
        $checkedLists = (array) ($fields['Newsletter Lists'] ?? []);
        $plainOptIns = array_values(array_diff($checkedLists, $event->triggeredHooks));
        $newsletterOptIn = $plainOptIns !== [];

        // Carry the derived facts as Title-Case keys, matching the plugin's
        // payload convention, so the CRM mapper below reads uniformly.
        $fields['Classification'] = $classification;
        $fields['Newsletter Opt-In'] = $newsletterOptIn ? 'TRUE' : 'FALSE';
        if ($event->downloadName !== null) {
            // Resolved SERVER-SIDE by the plugin from the signed asset id, so
            // "which file did they take" cannot be spoofed by the client.
            $fields['Download Name'] = $event->downloadName;
        }

        // Reset the per-request debug capture for this submission.
        $this->debug = ['fields' => $fields, 'crm' => [], 'esp' => [], 'routing' => [
            'classification'  => $classification,
            'newsletterOptIn' => $newsletterOptIn,
            'checkedLists'    => $checkedLists,
            'triggeredHooks'  => $event->triggeredHooks,
        ]];

        // --- CRM -------------------------------------------------------------
        // Catch Throwable, log, and CONTINUE. A CRM outage is your problem, not
        // the visitor's — they filled the form, they get the file. Setting
        // $event->isValid = false here would instead hard-gate the download.
        // Choose that only when the integration IS the product (e.g. the CRM
        // mints the license key you are about to hand out).
        try {
            $event->contactId = $this->upsertContact($fields);
            $this->debug['crm']['contactId'] = $event->contactId;

            // One timestamped engagement record per download — NOT an overwritten
            // "last download" field — so the history (which file, when, from which
            // affiliation/district) is preserved and reportable.
            if ($event->contactId) {
                $this->debug['crm']['engagementId'] = $this->createEngagement($fields, $event->contactId);
            }
        } catch (\Throwable $e) {
            $this->debug['crm']['exception'] = $e->getMessage();
            Craft::error('Gated Content CRM push failed: ' . $e->getMessage(), 'downtoll');
        }

        // --- ESP -------------------------------------------------------------
        if ($newsletterOptIn) {
            // Subscribe EVERY checked box's list — a checked hook box rides along
            // once a plain opt-in has opened the gate. That is what makes
            // "subscribe me" + "I'm a professional" fan out to both audiences,
            // while "subscribe me" alone hits only the primary list.
            //
            // Map list id → plain-text label; catalog labels may contain HTML.
            $labels = [];
            foreach ($config->newsletterLists as $list) {
                $labels[$list['listId']] = trim(strip_tags((string) $list['label']));
            }
            foreach ($checkedLists as $listId) {
                try {
                    $this->subscribe($fields, $listId, $labels[$listId] ?? '');
                } catch (\Throwable $e) {
                    $this->debug['esp'][] = ['listId' => $listId, 'exception' => $e->getMessage()];
                    Craft::error('Gated Content ESP push failed: ' . $e->getMessage(), 'downtoll');
                }
            }
        } else {
            // No opt-in: the lead still goes to a DEDICATED drip list rather than
            // being CRM-only, so their subsequent opt-in state is managed in the
            // ESP. They already got the Contact + engagement record above.
            $dripListId = App::env('CM_GATED_DRIP_LIST_ID');
            if ($dripListId) {
                try {
                    $this->subscribe($fields, $dripListId, '');
                } catch (\Throwable $e) {
                    $this->debug['esp'][] = ['listId' => 'drip', 'exception' => $e->getMessage()];
                    Craft::error('Gated Content drip push failed: ' . $e->getMessage(), 'downtoll');
                }
            } else {
                $this->debug['esp'][] = ['skipped' => 'no opt-in; CM_GATED_DRIP_LIST_ID not configured'];
            }
        }

        // Surface the raw outcomes for debugging (no-op unless the env flag is set).
        $event->integrationResults = $this->debug;
        $this->sendDebug();
    }

    // --- CRM: Contact upsert (Salesforce REST) ------------------------------

    private function upsertContact(array $fields): ?string
    {
        if (empty($fields['Email'])) {
            return null;
        }

        [$accessToken, $apiUrl] = $this->getAuth();
        if (!$accessToken) {
            return null;
        }

        // REPLACE every key below with your own CRM's field API names. The values
        // are the plugin's Title-Case payload keys and stay as-is.
        //
        // WARNING — restricted picklists. If your CRM restricts a picklist's
        // values, ONE unrecognized value 400-rejects the ENTIRE record: no
        // contact, no engagement, and an error body you will not see unless you
        // capture it (see request()). Verify every value you send against the
        // live CRM before wiring it, especially derived ones like Classification.
        $data = array_filter([
            'Email'     => $fields['Email'] ?? null,
            'FirstName' => $fields['First Name'] ?? null,
            'LastName'  => $fields['Last Name'] ?? null,

            // GOTCHA — Salesforce State & Country Picklists. With that feature on,
            // the compound `MailingState` field REJECTS the 2-letter code "PA" (it
            // wants the label "Pennsylvania"), and the 400 takes the whole contact
            // with it. Write the code to `MailingStateCode`, the 2-letter
            // *integration value*, instead: a create with MailingStateCode="PA"
            // returns 201 and the CRM resolves MailingState="Pennsylvania" itself.
            // The plugin's state <select> already submits the abbreviation
            // (gc.states), so no conversion table is needed.
            'MailingStateCode' => $fields['State'] ?? null,

            // Your CRM field API names. `Affiliation__c` holds the raw "I'm a…"
            // dropdown value; `Newsletter_Affiliation__c` holds the derived
            // classification. Different granularities — keep both, or drop the raw
            // one if your reporting does not need it.
            'Affiliation__c'            => $fields['Affiliation'] ?? null,
            'Newsletter_Affiliation__c' => $fields['Classification'] ?? null,
            'Other_Affiliation__c'      => $fields['Other Affiliation'] ?? null,
            'School_District__c'        => $fields['School District Input'] ?? null,
            'School_District_Lookup__c' => $fields['District Id'] ?? null,

            // Which site/surface registered this contact, supplied per-site via env
            // so this file stays byte-identical across brands sharing one CRM org.
            'Platform_Registration__c' => App::env('SF_GATED_PLATFORM') ?: null,
        ], static fn ($v) => $v !== null && $v !== '');

        $this->debug['crm']['payload'] = $data;
        $this->debug['crm']['derived'] = [
            'Classification'    => $fields['Classification'] ?? null,
            'Newsletter Opt-In' => $fields['Newsletter Opt-In'] ?? null,
            'Download Name'     => $fields['Download Name'] ?? null,
        ];

        $existingId = $this->findContactId($accessToken, $apiUrl, $fields['Email']);
        if ($existingId) {
            $this->debug['crm']['action'] = "update ({$existingId})";
            $this->request('PATCH', "{$apiUrl}/sobjects/Contact/{$existingId}", $accessToken, $data);

            return $existingId;
        }

        $this->debug['crm']['action'] = 'create';
        $response = $this->request('POST', "{$apiUrl}/sobjects/Contact/", $accessToken, $data);

        return $response['id'] ?? null;
    }

    /**
     * Log the download as its own engagement record.
     *
     * One distinct, timestamped record per download — rather than an overwritten
     * "download name" field — so engagement history is kept. The per-site Program /
     * Record Type / Platform / Name all come from env so this file is identical
     * across sites. Verify every field API name and restricted-picklist value
     * against your live CRM before wiring.
     *
     * NB: this fires at SUBMIT time, so it records "requested", not "clicked". To
     * record the actual download click instead, persist the submission facts and
     * re-invoke this from your own download hook.
     *
     * REPLACE `pmdm__ProgramEngagement__c` with your CRM's engagement/activity
     * object, and every field API name below with your own.
     */
    private function createEngagement(array $fields, string $contactId): ?string
    {
        [$accessToken, $apiUrl] = $this->getAuth();
        if (!$accessToken) {
            return null;
        }

        // GOTCHA — lookup/relation fields. `School_District__c` here is an Account
        // LOOKUP: it accepts only a well-formed record id (15- or 18-char
        // alphanumeric). A malformed id 400-rejects the WHOLE record, so validate
        // before sending and drop the field if it does not pass. Never forward a
        // client-supplied relation id to your CRM unchecked.
        $districtId = $fields['District Id'] ?? null;
        if ($districtId !== null && !preg_match('/^[A-Za-z0-9]{15}([A-Za-z0-9]{3})?$/', (string) $districtId)) {
            $districtId = null;
        }

        $engagement = array_filter([
            'Name'                => App::env('SF_GATED_ENGAGEMENT_NAME') ?: 'Gated Content Download',
            'RecordTypeId'        => App::env('SF_GATED_RECORD_TYPE_ID') ?: null,
            'pmdm__Program__c'    => App::env('SF_GATED_PROGRAM_ID') ?: null,
            'pmdm__Stage__c'      => 'Downloaded',                          // restricted picklist — verify the value exists
            'Contact__c'          => $contactId,
            'Affiliation_Type__c' => $fields['Classification'] ?? null,      // restricted picklist — verify the values exist
            'School_District__c'  => $districtId,                            // lookup — guarded above
            'Download_Name__c'    => $fields['Download Name'] ?? null,
            'Downloaded_Date__c'  => (new \DateTime('now', new \DateTimeZone(Craft::$app->getTimeZone())))->format(DATE_ATOM),
        ], static fn ($v) => $v !== null && $v !== '');

        $this->debug['crm']['engagement'] = $engagement;

        $response = $this->request('POST', "{$apiUrl}/sobjects/pmdm__ProgramEngagement__c/", $accessToken, $engagement);

        return $response['id'] ?? null;
    }

    private function findContactId(string $accessToken, string $apiUrl, string $email): ?string
    {
        $soql = "SELECT Id FROM Contact WHERE Email = '" . addslashes(trim($email)) . "'";
        $result = $this->request('GET', "{$apiUrl}/query", $accessToken, null, ['q' => $soql]);

        return !empty($result['totalSize']) ? ($result['records'][0]['Id'] ?? null) : null;
    }

    /**
     * Authenticate and CACHE the token. Re-authing on every submission burns a
     * round trip per lead and will eventually hit a rate limit. The TTL is kept
     * comfortably under the provider's own token lifetime.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function getAuth(): array
    {
        $cache = Craft::$app->getCache();
        $cached = $cache->get(self::TOKEN_CACHE_KEY);
        if (is_array($cached) && !empty($cached['access_token'])) {
            $this->debug['crm']['auth'] = 'ok (cached)';

            return [$cached['access_token'], $cached['api_url']];
        }

        try {
            $response = Craft::createGuzzleClient(['timeout' => 10])->post(
                App::env('SF_LOGIN_URI') . '/services/oauth2/token',
                ['form_params' => [
                    'grant_type'    => 'password',
                    'client_id'     => App::env('SF_CONSUMER_KEY'),
                    'client_secret' => App::env('SF_CONSUMER_SECRET'),
                    'username'      => App::env('SF_USERNAME'),
                    'password'      => App::env('SF_PASSWORD') . App::env('SF_TOKEN'),
                ]]
            );
            $body = json_decode((string) $response->getBody(), true);
        } catch (GuzzleException $e) {
            $this->debug['crm']['auth'] = 'failed: ' . $e->getMessage();
            Craft::error('Gated Content CRM auth failed: ' . $e->getMessage(), 'downtoll');

            return [null, null];
        }

        if (empty($body['access_token'])) {
            $this->debug['crm']['auth'] = 'failed: no access_token in response';

            return [null, null];
        }

        $this->debug['crm']['auth'] = 'ok';

        $apiUrl = $body['instance_url'] . App::env('SF_API_VERSION');
        $cache->set(self::TOKEN_CACHE_KEY, ['access_token' => $body['access_token'], 'api_url' => $apiUrl], self::TOKEN_TTL);

        return [$body['access_token'], $apiUrl];
    }

    private function request(string $method, string $url, string $accessToken, ?array $json = null, ?array $query = null): array
    {
        $options = [
            'headers' => ['Authorization' => "OAuth {$accessToken}", 'Content-Type' => 'application/json'],
            'timeout' => 10,
        ];
        if ($json !== null) {
            $options['json'] = $json;
        }
        if ($query !== null) {
            $options['query'] = $query;
        }

        try {
            $response = Craft::createGuzzleClient()->request($method, $url, $options);
            $body = (string) $response->getBody();
            $this->debug['crm']['requests'][] = [
                'method' => $method,
                'status' => $response->getStatusCode(),
                'body'   => $body,
            ];

            return $body !== '' ? (json_decode($body, true) ?: []) : [];
        } catch (RequestException $e) {
            // Capture the CRM's error response BODY (the real reason — INVALID_FIELD,
            // picklist rejection, malformed lookup id) before rethrowing. The generic
            // exception message the outer handler logs truncates it, and without the
            // body a restricted-picklist 400 is nearly undiagnosable.
            $this->debug['crm']['requests'][] = [
                'method' => $method,
                'status' => $e->hasResponse() ? $e->getResponse()->getStatusCode() : null,
                'error'  => $e->getMessage(),
                'body'   => $e->hasResponse() ? (string) $e->getResponse()->getBody() : '',
            ];

            throw $e;
        }
    }

    // --- ESP: list subscribe (Campaign Monitor) -----------------------------

    private function subscribe(array $fields, string $listId, string $listLabel): void
    {
        $apiKey = App::env('CM_API_KEY');
        if (!$apiKey) {
            Craft::warning('CM_API_KEY is empty; skipping ESP subscribe.', 'downtoll');
            $this->debug['esp'][] = ['listId' => $listId, 'skipped' => 'CM_API_KEY empty'];

            return;
        }

        // The plugin's newsletter catalog stores each list id as a literal OR as an
        // `$ENV_VAR` reference. Resolve it HERE, server-side, at the API call: an
        // `$ENV_VAR` reference means the real list id never ships in the page HTML
        // where anyone can read it out of the form markup.
        $resolvedListId = (string) App::parseEnv($listId);
        if ($resolvedListId === '') {
            Craft::warning("Gated Content: unresolved list id '{$listId}'.", 'downtoll');
            $this->debug['esp'][] = ['listId' => $listId, 'skipped' => 'unresolved env reference'];

            return;
        }

        $wrap = new CS_REST_Subscribers($resolvedListId, ['api_key' => $apiKey]);
        $name = trim(($fields['First Name'] ?? '') . ' ' . ($fields['Last Name'] ?? ''));

        $result = $wrap->add([
            'EmailAddress'   => $fields['Email'],
            'Name'           => $name,
            'CustomFields'   => $listLabel !== '' ? [['Key' => 'AreaofInterest', 'Value' => $listLabel]] : [],
            'ConsentToTrack' => 'yes',
            'Resubscribe'    => true,
        ]);

        $this->debug['esp'][] = [
            'listId'   => $listId,
            'label'    => $listLabel,
            'status'   => $result->http_status_code ?? null,
            'success'  => method_exists($result, 'was_successful') ? $result->was_successful() : null,
            'response' => $result->response ?? null,
        ];
    }

    // --- Debug capture (OPTIONAL; off unless GATED_CONTENT_DEBUG_EMAIL is set) ---

    /**
     * Emails + logs the raw CRM/ESP outcomes for one submission, so the integration
     * can be debugged on a live server without SSH log-digging. No-op unless the
     * GATED_CONTENT_DEBUG_EMAIL env var holds a recipient address.
     *
     * The payload contains submitted lead data. Keep it env-gated, point it at an
     * internal address, and turn it off once the integration is stable.
     */
    private function sendDebug(): void
    {
        $debugEmail = App::env('GATED_CONTENT_DEBUG_EMAIL');
        if (!$debugEmail) {
            return; // debug mode off
        }

        $payload = json_encode(
            $this->debug,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        // Persistent trail, greppable under the 'downtoll' category.
        Craft::info('Gated Content debug capture: ' . $payload, 'downtoll');

        // Transient email so the outcome surfaces immediately. Set an explicit From:
        // an empty From makes the mailer reject the whole message, so this must not
        // silently depend on the system fromEmail being configured.
        $from = App::env('SMTP_FROM');
        if (!$from) {
            Craft::warning('Gated Content debug email skipped: SMTP_FROM is empty.', 'downtoll');

            return;
        }

        try {
            $message = new Message();
            $message->setFrom($from);
            $message->setTo($debugEmail);
            $message->setSubject('[Gated Content DEBUG] ' . ($this->debug['fields']['Email'] ?? 'submission'));
            $message->setTextBody($payload);
            Craft::$app->getMailer()->send($message);
        } catch (\Throwable $e) {
            Craft::error('Gated Content debug email failed: ' . $e->getMessage(), 'downtoll');
        }
    }
}
