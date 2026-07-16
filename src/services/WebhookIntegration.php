<?php

namespace dgaidula\downtoll\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use craft\helpers\Json;
use dgaidula\downtoll\events\SubmissionEvent;
use dgaidula\downtoll\Plugin;

/**
 * The shipped, integration-agnostic listener: on a successful submission it
 * POSTs the normalized lead to a configurable webhook URL. This is what makes
 * the plugin useful out of the box for any buyer (Zapier, Make, a CRM endpoint,
 * etc.) WITHOUT bundling vendor-specific code.
 *
 * CRM-specific mapping (e.g. Salesforce + an ESP) is intentionally NOT here — it
 * lives in a site-side listener on the same SubmissionEvent. See examples/ for a
 * full reference implementation.
 */
class WebhookIntegration extends Component
{
    public function handleSubmission(SubmissionEvent $event): void
    {
        $settings = Plugin::getInstance()->getSettings();
        if (!$settings->webhookEnabled) {
            return;
        }

        $url = App::parseEnv($settings->webhookUrl);
        if (!$url) {
            return;
        }

        $body = Json::encode([
            'fields' => $event->fields,
            'resource' => [
                'id'              => $event->config->resourceId,
                'newsletterLists' => $event->config->newsletterLists, // offered (id + label)
            ],
            // $event->fields['Newsletter Lists'] holds the list IDs the visitor actually checked.
            'submittedAt' => (new \DateTime())->format(\DateTime::ATOM),
        ]);

        $headers = ['Content-Type' => 'application/json'];

        // Optional HMAC signature so the receiver can verify authenticity.
        $secret = App::parseEnv($settings->webhookSecret);
        if ($secret) {
            $headers['X-Downtoll-Signature'] = 'sha256=' . hash_hmac('sha256', $body, $secret);
        }

        try {
            Craft::createGuzzleClient()->post($url, [
                'headers' => $headers,
                'body'    => $body,
                'timeout' => 8,
            ]);
        } catch (\Throwable $e) {
            // Soft-fail: a webhook hiccup shouldn't block content the visitor earned.
            Craft::error('Downtoll webhook failed: ' . $e->getMessage(), 'downtoll');
        }
    }
}
