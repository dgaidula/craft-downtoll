# Example: CRM + ESP integration (Salesforce + Campaign Monitor)

A full, real-world listener on `Submissions::EVENT_AFTER_SUBMISSION`, adapted
from a production integration. Code: [`GatedContentIntegration.example.php`](GatedContentIntegration.example.php).

It is written against Salesforce (REST) and Campaign Monitor because a concrete
stack teaches more than pseudocode. The vendors are incidental — the structure,
the env-driven config, and the gotchas transfer to any CRM/ESP.

> Nothing in this directory is loaded by the plugin. Copy the file into your own
> `modules/`, rename the namespace, and edit.

## What it does, end to end

On each successful submission:

1. **Upsert a CRM Contact, keyed by email.** Query for an existing Contact by
   email → `PATCH` if found, `POST` if not. The resulting id is written back to
   `$e->contactId`, which the plugin stores with the gating record.
2. **Log the download as its own engagement record.** One *distinct, timestamped*
   record per submission — which file, which affiliation, which district — rather
   than an overwritten "last download" field. Engagement history accumulates and
   stays reportable.
3. **Route to the ESP.** Opted in → subscribe every checked list. Didn't opt in →
   subscribe to a dedicated **drip list** instead, so the lead's opt-in state is
   managed in the ESP rather than the lead being CRM-only.

The Contact and the engagement record happen either way. The ESP branch is what
the opt-in decides.

## Checkbox model → routing

The plugin's newsletter catalog gives every checkbox one flag: `triggersHook`.
That single flag yields two kinds of box:

- **Plain opt-in box** — "subscribe me". Means what it says.
- **`triggersHook` modifier box** — "I'm a school food professional". A routing
  **modifier**. It classifies the lead; on its own it is *not* a subscription
  request.

The plugin hands you both: `$e->fields['Newsletter Lists']` is everything the
visitor checked; `$e->triggeredHooks` is the subset flagged `triggersHook`. One
`array_diff` separates them:

```php
$checked    = (array) ($e->fields['Newsletter Lists'] ?? []);
$plainOptIns = array_values(array_diff($checked, $e->triggeredHooks)); // real opt-ins
$optedIn     = $plainOptIns !== [];
$class       = $e->triggeredHooks !== [] ? 'School Food Professional' : 'Parent/Advocate';
```

This is the whole trick, and why a hook box can classify a lead **without**
counting as a newsletter opt-in:

| Checked | Classification | ESP result |
| --- | --- | --- |
| nothing | Parent/Advocate | drip list |
| hook box only | School Food Professional | drip list — classified, *not* subscribed |
| plain box only | Parent/Advocate | the plain box's list |
| both | School Food Professional | **both** lists (the hook box rides along) |

Note the last row: once a plain opt-in opens the gate, the listener subscribes
*every* checked list, hook boxes included. That's a decision made here in twelve
lines of listener — not a plugin setting, and not something the plugin could have
guessed for you.

## Configuration: env vars only

No id, key, list id, or secret is hardcoded anywhere in the example. Everything
is read through `App::env()` / `App::parseEnv()`.

| Env var | Purpose |
| --- | --- |
| `SF_LOGIN_URI` | CRM OAuth host (e.g. the login/test domain). `/services/oauth2/token` is appended. |
| `SF_CONSUMER_KEY` | OAuth client id. |
| `SF_CONSUMER_SECRET` | OAuth client secret. |
| `SF_USERNAME` | Integration user. |
| `SF_PASSWORD` | Integration user's password (concatenated with `SF_TOKEN`). |
| `SF_TOKEN` | Security token appended to the password. |
| `SF_API_VERSION` | API path suffix appended to the returned `instance_url` (e.g. `/services/data/v58.0`). A **path**, not a bare number. |
| `SF_GATED_PLATFORM` | Which site/surface registered the contact → `Platform_Registration__c`. The per-site value. |
| `SF_GATED_PROGRAM_ID` | Program record the engagement is filed under. |
| `SF_GATED_RECORD_TYPE_ID` | Record Type for the engagement record. |
| `SF_GATED_ENGAGEMENT_NAME` | Engagement record `Name`. Falls back to `Gated Content Download`. |
| `CM_API_KEY` | ESP API key. Empty → subscribes are skipped with a warning, downloads still work. |
| `CM_GATED_DRIP_LIST_ID` | List for non-opt-in leads. Unset → the drip branch is skipped (logged). |
| `GATED_CONTENT_DEBUG_EMAIL` | **Optional.** Recipient for the per-submission debug capture. Unset = debug off. |
| `SMTP_FROM` | From address for the debug email. Only read when debug is on. |

Newsletter **list ids** are not in this table on purpose: they come from the
plugin's catalog, where each row's List ID may be a literal *or* an `$ENV_VAR`
reference. The listener resolves them with `App::parseEnv()` at the API call.

Env-only config is what lets **one identical listener serve two different
sites/brands** off the same codebase: same file, different `.env`. The moment you
hardcode one list id "just for now", you've forked the file.

## Gotchas

These are the ones that cost real debugging time. They generalize.

**Restricted picklists 400-reject the entire record.** One value your CRM doesn't
recognize doesn't drop that field — it rejects the whole write. No contact, no
engagement, and a generic error. Verify every picklist value against the live CRM
before wiring it, especially *derived* values like a classification string your
own code invents.

**Salesforce State & Country Picklists: write `MailingStateCode`, not
`MailingState`.** With that feature enabled, the compound `MailingState` field
rejects the 2-letter code `PA` — it wants `Pennsylvania` — and takes the whole
contact down with it. `MailingStateCode` accepts the 2-letter *integration value*
and the CRM resolves the label itself. The plugin's state `<select>` already
submits the abbreviation, so no conversion table is needed. Generic lesson: when
a field has a "code" twin, find out which one takes the integration value.

**Lookup/relation fields need a well-formed id.** A malformed relation id rejects
the record, same as a bad picklist. The example regex-guards the district id
(15- or 18-char alphanumeric) and drops it rather than sending garbage. Never
forward a client-supplied relation id to your CRM unchecked.

**Guard client-posted conditional fields server-side.** The form only reveals the
district lookup for affiliations the editor flagged as hook-triggering — but a
crafted POST can carry a lookup id alongside any affiliation. The listener drops
`District Id` when the submitted affiliation isn't in `$e->config->triggerValues`
(server-trusted, from the signed token). Apply this to any field whose presence
is supposed to be conditional on another answer.

**Resolve `$ENV_VAR` list ids server-side.** Storing an `$ENV_VAR` reference in
the catalog and resolving it with `App::parseEnv()` in the listener means the real
list id never ships in the page HTML where anyone can read it out of the form.

**Cache the OAuth token.** The example caches for ~7000s (comfortably under the
provider's token lifetime). Re-authing per submission burns a round trip per lead
and will find your rate limit eventually.

**Capture the error-response BODY.** The exception message your catch block logs
is generic — the *reason* (`INVALID_FIELD`, picklist rejection, malformed lookup)
is in the HTTP response body, which is discarded by the time the outer handler
sees it. The example's `request()` grabs the body in a `RequestException` catch
before rethrowing. Without this, a restricted-picklist 400 is nearly
undiagnosable.

**Never let an integration failure break the download.** The visitor filled in
the form; a CRM outage is your problem, not theirs. Catch `Throwable`, log,
continue — gating proceeds and they get their file. Contrast with
`$e->isValid = false`, which **blocks** the download:

- **Soft-fail (this example).** The download is the visitor's side of the deal
  and the lead capture is yours. Use this by default.
- **Hard-gate (`isValid = false`).** Use only when the integration *is* the
  product — the CRM mints the license key you're about to hand out, the ESP
  double-opt-in is legally required before delivery. Be sure the failure mode is
  worth the abandoned downloads.

## Adapting this to your stack

**Change:**

- **Field/object API names.** `Affiliation__c`, `Newsletter_Affiliation__c`,
  `Platform_Registration__c`, `School_District_Lookup__c`,
  `pmdm__ProgramEngagement__c` and friends are one customer's Salesforce schema,
  not an interface of this plugin. Every one is flagged inline in the example.
  (Where the source schema used brand-prefixed custom fields, they were renamed
  to neutral equivalents here — `Affiliation__c`, `Download_Name__c`,
  `Downloaded_Date__c` — so the example reads generically. Yours will be
  prefixed however your org does it.)
- **The CRM client.** `getAuth()` / `request()` / `findContactId()` /
  `upsertContact()` are Salesforce REST. Replace with your vendor's SDK or REST
  calls. The upsert *shape* — find by email, PATCH or POST — is near-universal.
- **The ESP client.** `subscribe()` wraps `CS_REST_Subscribers`. Swap for
  Mailchimp/Klaviyo/whatever; keep the `App::parseEnv()` on the list id.
- **The classification strings and routing rules.** "School Food Professional" /
  "Parent/Advocate" and the drip-list fallback are one org's marketing logic.
  Yours will differ. This is the part you're *supposed* to rewrite.

**Keep:**

- **The event wiring.** `Event::on(Submissions::class, EVENT_AFTER_SUBMISSION, …)`
  in a bootstrapped module's `init()`.
- **The `array_diff($checked, $e->triggeredHooks)` split.** It's the plugin's
  checkbox model; how you *act* on the two groups is yours.
- **Env-driven config.** Every id and secret via `App::env()` / `App::parseEnv()`.
- **Error isolation.** Per-call `try`/`catch (\Throwable)`, log, continue. One
  failing list must not take down the other list, the CRM push, or the download.
- **Server-side trust.** `$e->config` and `$e->downloadName` are trusted;
  `$e->rawPayload` and anything derived from client POST are not.
- **The debug capture** (optional). Env-gated, off by default. It pays for itself
  the first time a picklist 400s on a live server.
