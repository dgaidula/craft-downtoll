<?php

namespace dgaidula\downtoll\controllers;

use Craft;
use craft\helpers\App;
use craft\web\Controller;
use dgaidula\downtoll\Plugin;
use yii\web\Response;

/**
 * Anonymous front-end endpoint that validates a gated-form submission,
 * verifies reCAPTCHA v3 server-side (Craft's Guzzle client), fires the
 * custom submission event, gates the resource, and responds per the
 * editor's configured success UX.
 */
class SubmitController extends Controller
{
    protected array|bool|int $allowAnonymous = ['index'];

    // The signed `_gcConfig` token is the request authenticity guarantee,
    // and the endpoint is consumed by fetch() with a JSON body.
    public $enableCsrfValidation = false;

    public function actionIndex(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $payload = $request->getBodyParams();
        $submissions = Plugin::getInstance()->submissions;

        // 1. Trust the per-resource config only via its signed token, so a
        //    request can't be re-pointed at an arbitrary asset or resource.
        $config = $submissions->decodeConfigToken((string) ($payload['_gcConfig'] ?? ''));
        if ($config === null) {
            return $this->failureResponse('Invalid form configuration.', 400);
        }

        // 2. Server-side reCAPTCHA v3. The token field name is configurable
        //    (Settings → Advanced) so sites can obfuscate it as an anti-bot
        //    measure; we still accept the generic name as a fallback.
        $fieldName = Plugin::getInstance()->getSettings()->recaptchaFieldName ?: 'g-recaptcha-response';
        $token = (string) ($payload[$fieldName] ?? $payload['g-recaptcha-response'] ?? '');
        if (!$this->verifyRecaptcha($token)) {
            return $this->failureResponse('Verification failed. Please try again.', 418);
        }

        // 3. Normalize to the Title-Case contract + required-field validation.
        [$fields, $errors] = $submissions->normalizeAndValidate($payload, $config);
        if ($errors) {
            return $this->asJson(['success' => false, 'errors' => $errors])->setStatusCode(422);
        }

        // 4. Fire the custom event — the webhook + any site-side listeners push the lead.
        $event = $submissions->fireAfterSubmission($fields, $config, $payload);
        if (!$event->isValid) {
            return $this->failureResponse('We could not process your request. Please try again.', 502);
        }

        // 5. Gate the resource for this session.
        $submissions->grantAccess($config);

        // 6. Notify a human. Deliberately AFTER access is granted and never able to
        //    fail the request: the visitor has already earned the download, so a bad
        //    SMTP config must not cost them the file. Notifications::send() swallows
        //    and logs its own errors; this is available in Lite AND Pro.
        Plugin::getInstance()->notifications->send($event);

        // 7. Respond per the editor's per-resource UX choice.
        if ($config->successMode === 'swap') {
            return $this->asJson([
                'success'     => true,
                'mode'        => 'swap',
                'downloadUrl' => $submissions->signedDownloadUrl($config),
            ]);
        }

        return $this->asJson(['success' => true, 'mode' => 'reload']);
    }

    private function verifyRecaptcha(string $token): bool
    {
        $settings = Plugin::getInstance()->getSettings();
        $secret = App::parseEnv($settings->recaptchaSecret);

        // Empty secret = verification intentionally disabled (e.g. local dev).
        if (!$secret) {
            return true;
        }
        if ($token === '') {
            return false;
        }

        try {
            $client = Craft::createGuzzleClient(['base_uri' => 'https://www.google.com']);
            $response = $client->post('/recaptcha/api/siteverify', [
                'form_params' => [
                    'secret'   => $secret,
                    'response' => $token,
                    'remoteip' => Craft::$app->getRequest()->getUserIP(),
                ],
                'timeout' => 5,
            ]);
            $body = json_decode((string) $response->getBody(), true);
        } catch (\Throwable $e) {
            Craft::error('reCAPTCHA verification failed: ' . $e->getMessage(), 'downtoll');
            return false;
        }

        $actionOk = empty($settings->recaptchaAllowedActions)
            || in_array($body['action'] ?? '', $settings->recaptchaAllowedActions, true);

        return ($body['success'] ?? false) === true
            && (float) ($body['score'] ?? 0) >= $settings->recaptchaMinScore
            && $actionOk;
    }

    private function failureResponse(string $message, int $statusCode): Response
    {
        return $this->asJson(['success' => false, 'error' => $message])->setStatusCode($statusCode);
    }
}
