<?php

namespace dgaidula\downtoll\web\twig;

use Craft;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use dgaidula\downtoll\models\ResourceConfig;
use dgaidula\downtoll\Plugin;
use dgaidula\downtoll\web\assets\form\FormAsset;
use Twig\Markup;

/**
 * Exposes `craft.downtoll.*` to templates.
 *
 * Primary usage (field-driven):
 *   {{ craft.downtoll.render(entry.myGatedField) }}
 *
 * The field value is an already-enriched {@see ResourceConfig}.
 */
class DowntollVariable
{
    /** Render the embedded form (or the unlocked state) for a field value. */
    public function render(?ResourceConfig $config): Markup
    {
        if (!$config || !$config->resourceId) {
            return new Markup('', 'UTF-8');
        }

        $view = Craft::$app->getView();

        // Ship the turnkey behaviour with the turnkey form. Headless data() users
        // never hit this path, so they never carry JS they won't use.
        $view->registerAssetBundle(FormAsset::class);

        $html = $view->renderTemplate('downtoll/_form', [
            'config' => $config,
        ]);

        return new Markup($html, Craft::$app->charset ?? 'UTF-8');
    }

    /**
     * Headless / bring-your-own-markup mode. Returns everything a custom Twig
     * template needs to build a 100%-bespoke form that still posts to the
     * plugin's controller (validation + reCAPTCHA + gating reused). The dev
     * renders their own markup; only the field `name`s + signed token matter.
     *
     *   {% set gc = craft.downtoll.data(entry.myGatedField) %}
     *   <form method="post" data-endpoint="{{ gc.endpoint }}">
     *     <input type="hidden" name="{{ gc.fieldNames.config }}" value="{{ gc.token }}">
     *     <input type="email" name="{{ gc.fieldNames.email }}" required>
     *     …
     */
    public function data(?ResourceConfig $config): array
    {
        // PRO gate: headless mode is for devs building a bespoke front end — the same
        // audience Pro targets. Lite ships render() instead, which is a complete form.
        if (!Plugin::getInstance()->isPro()) {
            Craft::warning(
                'craft.downtoll.data() is a Pro feature; Lite ships craft.downtoll.render(). Returning [].',
                'downtoll'
            );
            return [];
        }

        if (!$config || !$config->resourceId) {
            return [];
        }

        $submissions = Plugin::getInstance()->submissions;

        return [
            'token'              => $submissions->encodeConfigToken($config),
            'endpoint'           => UrlHelper::actionUrl('downtoll/submit'),
            'recaptchaSiteKey'   => $this->recaptchaSiteKey(),
            'successMode'        => $config->successMode,
            'hasAccess'          => $submissions->hasAccess($config),
            'downloadUrl'        => $submissions->signedDownloadUrl($config),
            'assetId'            => $config->assetId,
            'successMessage'     => $config->successMessage,
            'errorMessage'       => $config->errorMessage,
            'includeAffiliation' => $config->includeAffiliation,
            'includeNewsletter'  => $config->includeNewsletter,
            'requiredFields'     => $config->requiredFields,
            'cssClass'           => $config->cssClass,
            'newsletter'         => [
                'heading' => $config->newsletterHeading,
                'lists'   => $config->newsletterLists, // [{listId, label(html)}] offered on this resource
            ],
            'affiliations'          => $config->affiliationOptions,
            'triggerValues'         => $config->triggerValues,
            'districtLookupEndpoint' => $this->districtLookupEndpoint(),
            'states'                => $this->usStates(),
            // The exact POST names the controller maps (don't rename these):
            'fieldNames' => [
                'config'         => '_gcConfig',
                'recaptcha'      => $config->recaptchaFieldName,
                'email'          => 'email',
                'firstName'      => 'first-name',
                'lastName'       => 'last-name',
                'state'          => 'state',
                'affiliation'    => 'affiliation',
                'schoolDistrict' => 'school-district-input',
                'districtId'     => 'district-id',
                'newsletter'     => 'newsletter-subscribe',
            ],
        ];
    }

    public function token(ResourceConfig $config): string
    {
        return Plugin::getInstance()->submissions->encodeConfigToken($config);
    }

    public function hasAccess(ResourceConfig $config): bool
    {
        return Plugin::getInstance()->submissions->hasAccess($config);
    }

    public function downloadUrl(ResourceConfig $config): ?string
    {
        return Plugin::getInstance()->submissions->signedDownloadUrl($config);
    }

    /** reCAPTCHA v3 site key (resolved from settings, env-aware). */
    public function recaptchaSiteKey(): string
    {
        return (string) App::parseEnv(Plugin::getInstance()->getSettings()->recaptchaSiteKey);
    }

    /** The (possibly obfuscated) field name the reCAPTCHA token submits under. */
    public function recaptchaFieldName(): string
    {
        return Plugin::getInstance()->getSettings()->recaptchaFieldName ?: 'g-recaptcha-response';
    }

    /**
     * Endpoint the district typeahead queries (resolved from settings, env-aware).
     * Empty = no lookup configured; the shipped form omits the attribute and the
     * district input degrades to plain text.
     */
    public function districtLookupEndpoint(): string
    {
        // PRO gate: Lite keeps the district text input (it still submits as
        // `School District Input`); only the typeahead endpoint is Pro. Returning ''
        // makes _form.twig omit the data-lookup-endpoint attribute, so the field
        // degrades to plain text exactly as it does when no endpoint is configured.
        if (!Plugin::getInstance()->isPro()) {
            return '';
        }

        return (string) App::parseEnv(Plugin::getInstance()->getSettings()->districtLookupEndpoint);
    }

    /**
     * US states + territories (abbreviation => name) for the optional dropdown.
     *
     * @return array<string,string>
     */
    public function usStates(): array
    {
        return [
            'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas',
            'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware',
            'DC' => 'District of Columbia', 'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii',
            'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa',
            'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine',
            'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota',
            'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska',
            'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico',
            'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio',
            'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island',
            'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas',
            'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington',
            'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming',
            'PR' => 'Puerto Rico', 'VI' => 'Virgin Islands',
        ];
    }
}
