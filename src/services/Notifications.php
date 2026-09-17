<?php

namespace dgaidula\downtoll\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use craft\mail\Message;
use dgaidula\downtoll\events\SubmissionEvent;
use dgaidula\downtoll\Plugin;

/**
 * Emails each captured lead to a human. PRO.
 *
 * Lite captures the lead and stores it in the CP index (Submissions::store(), both
 * editions); Pro is how a lead gets OUT of the control panel — this email, the webhook,
 * the after-submission event. The edition line is "stored" vs. "delivered".
 *
 * Every failure here is swallowed (logged, never thrown). The visitor filled in the
 * form and is owed their download; a broken SMTP config is the site's problem, not
 * theirs. Same rule the shipped integration examples teach.
 */
class Notifications extends Component
{
    public function send(SubmissionEvent $event): bool
    {
        // PRO. Lite leaves any saved notify* settings untouched but never sends, so
        // upgrading turns an already-configured notification straight back on.
        if (!Plugin::getInstance()->isPro()) {
            return false;
        }

        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->notifyEnabled) {
            return false;
        }

        $recipients = $this->recipients($settings->notifyRecipients);
        if ($recipients === []) {
            Craft::warning('Downtoll notifications are enabled but no recipients resolved.', 'downtoll');
            return false;
        }

        try {
            $siteName = Craft::$app->getSites()->getCurrentSite()->name;
            $download = $event->downloadName ?? 'a gated download';

            $subject = str_replace(
                ['{download}', '{site}'],
                [$download, $siteName],
                $settings->notifySubject ?: 'New download lead: {download}'
            );

            $body = Craft::$app->getView()->renderTemplate(
                'downtoll/_mail/notification',
                [
                    'fields'   => $event->fields,
                    'download' => $event->downloadName,
                    'siteName' => $siteName,
                    'date'     => new \DateTime('now', new \DateTimeZone(Craft::$app->getTimeZone())),
                ],
                \craft\web\View::TEMPLATE_MODE_CP
            );

            $message = (new Message())
                ->setTo($recipients)
                ->setSubject($subject)
                ->setTextBody($body);

            // An explicit From avoids depending on the system fromEmail being set —
            // an empty From makes Symfony reject the whole message.
            $from = App::parseEnv($settings->notifyFrom ?: '') ?: null;
            if ($from) {
                $message->setFrom($from);
            }

            // Replying to the notification should reach the lead, not the robot.
            if (!empty($event->fields['Email']) && filter_var($event->fields['Email'], FILTER_VALIDATE_EMAIL)) {
                $message->setReplyTo($event->fields['Email']);
            }

            $sent = Craft::$app->getMailer()->send($message);

            if (!$sent) {
                Craft::error('Downtoll lead notification was not sent (mailer returned false).', 'downtoll');
            }

            return $sent;
        } catch (\Throwable $e) {
            Craft::error('Downtoll lead notification failed: ' . $e->getMessage(), 'downtoll');
            return false;
        }
    }

    /**
     * Resolve the recipient list. Accepts a comma-separated literal or an `$ENV_VAR`
     * reference (which may itself hold a comma-separated list).
     *
     * @return string[]
     */
    private function recipients(string $raw): array
    {
        $resolved = (string) App::parseEnv(trim($raw));

        return array_values(array_filter(
            array_map('trim', explode(',', $resolved)),
            static fn (string $a): bool => $a !== '' && filter_var($a, FILTER_VALIDATE_EMAIL) !== false
        ));
    }
}
