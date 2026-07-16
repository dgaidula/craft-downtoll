# Integration examples

Reference listeners for `Submissions::EVENT_AFTER_SUBMISSION`. Nothing here is
loaded by the plugin — these are examples to read and adapt.

## Why there is no "CRM settings" tab

The plugin is deliberately **integration-agnostic**. It owns the parts every
buyer needs identically:

- the field type + per-entry configuration (content, editable on prod),
- rendering (`render()` / `data()`),
- validation + server-side reCAPTCHA verification,
- server-side resolution of the download name from the signed asset id,
- the signed-token + session access gate.

It ships **zero** CRM/ESP logic. The moment it fires
`Submissions::EVENT_AFTER_SUBMISSION`, its job is done and yours starts. Every
vendor-specific decision — which CRM, which object, which field API names, which
list, what counts as an opt-in — lives in a listener **in your site**, not in
plugin settings.

That is the architecture, not an omission. A "CRM settings" tab would be a
permanent guessing game about which fields your Salesforce/HubSpot/Klaviyo org
happens to have, and it would still be wrong for the next buyer. An event costs
one `Event::on()` and can express anything PHP can.

> The one integration that *is* shipped — `WebhookIntegration` — is exactly a
> listener on this same event, and it is vendor-neutral by construction: it POSTs
> the normalized lead to a URL you configure. It is useful out of the box (Zapier,
> Make, your own endpoint) precisely *because* it knows nothing about your CRM.

## The examples

| Example | What it shows |
| --- | --- |
| `WebhookIntegration` (in `src/services/`, not here) | The simplest real listener: POST the lead to a configurable URL, optional HMAC signature, soft-fail on error. Read this first. |
| [`salesforce-campaign-monitor/`](salesforce-campaign-monitor/) | The full real-world case: CRM Contact upsert, one timestamped engagement record per download, opt-in vs drip-list routing, env-driven config, and the gotchas that cost real debugging time. |

## Minimal listener

The smallest thing that works:

```php
use yii\base\Event;
use dgaidula\downtoll\services\Submissions;
use dgaidula\downtoll\events\SubmissionEvent;

Event::on(Submissions::class, Submissions::EVENT_AFTER_SUBMISSION, function (SubmissionEvent $e) {
    Craft::info(sprintf(
        '%s downloaded "%s" (lists: %s)',
        $e->fields['Email'] ?? '?',
        $e->downloadName ?? 'n/a',
        implode(', ', (array) ($e->fields['Newsletter Lists'] ?? []))
    ), 'downtoll');
});
```

Register it in a Craft module's `init()`, and bootstrap the module in
`config/app.php` so `init()` runs on every request — otherwise the handler isn't
attached when a submission arrives:

```php
// config/app.php
return [
    'modules' => [
        'downtoll-integration' => \modules\GatedContentIntegration::class,
    ],
    'bootstrap' => ['downtoll-integration'],
];
```

## What the event hands you

| Property | Notes |
| --- | --- |
| `$e->fields` | Normalized Title-Case payload: `Email`, `First Name`, `Last Name`, `State`, `Affiliation`, `School District Input`, `District Id`, `Other Affiliation`, plus `Newsletter Lists` (array of checked list IDs). Keys are **absent**, not empty, when not submitted. |
| `$e->config` | The per-resource `ResourceConfig`: `resourceId`, `assetId`, `successMode`, `newsletterLists` (offered: id + label + hook flag), `newsletterHookIds`, `triggerValues`, `requiredFields`. The subset round-tripped in the signed token is server-trusted. |
| `$e->rawPayload` | The raw, **untrusted** POST, for listeners needing keys the plugin doesn't map. |
| `$e->triggeredHooks` | Checked list IDs flagged `triggersHook` — the routing modifiers. |
| `$e->downloadName` | The gated file's name, resolved server-side from the signed asset id. Never client-posted, so it can't be spoofed. `null` when the resource gates no asset. |
| `$e->contactId` | Write it: your CRM's id for the lead. Flows into the gating record. |
| `$e->isValid` | Write `false` to hard-gate — block the download on a hard failure. Default `true`. |
| `$e->integrationResults` | Free-form bag for your own outcome data. |

Multiple listeners can subscribe; they all run. Keep each one's failures to
itself (catch `Throwable`) unless blocking the download is the intent.
