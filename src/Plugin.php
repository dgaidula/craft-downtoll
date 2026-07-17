<?php

namespace dgaidula\downtoll;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Elements;
use craft\services\Fields;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use dgaidula\downtoll\elements\Submission;
use dgaidula\downtoll\fields\GatedContent as GatedContentField;
use dgaidula\downtoll\models\Settings;
use dgaidula\downtoll\services\FormConfig;
use dgaidula\downtoll\services\Notifications;
use dgaidula\downtoll\services\Submissions;
use dgaidula\downtoll\services\WebhookIntegration;
use dgaidula\downtoll\web\twig\DowntollVariable;
use yii\base\Event;

/**
 * Downtoll plugin (self-contained, Plugin-Store-bound).
 *
 * Ships a "Gated Content" field, a plugin-owned CP screen for editable form
 * config (works with allowAdminChanges off), server-side reCAPTCHA, gating, the
 * SubmissionEvent, and a generic webhook integration. Vendor-specific CRM logic
 * is intentionally left to site-side listeners on the event.
 *
 * @property-read Submissions $submissions
 * @property-read FormConfig $formConfig
 * @property-read WebhookIntegration $webhook
 * @property-read Notifications $notifications
 * @property-read Settings $settings
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.1.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public const PERMISSION_MANAGE = 'downtoll:manageConfig';
    public const PERMISSION_VIEW_SUBMISSIONS = 'downtoll:viewSubmissions';

    /**
     * LITE (free) — a complete product on its own: gate a file behind a form, capture the
     * lead, notify. PRO (paid) — wire it into your stack.
     *
     * The line is "works standalone" vs "integrates with your systems", which is how
     * Freeform and Formie price and what buyers already understand. Lite must be genuinely
     * useful, not a crippled trial.
     */
    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    public static function editions(): array
    {
        return [self::EDITION_LITE, self::EDITION_PRO];
    }

    /** Single place the Pro gates ask. */
    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO);
    }

    public static function config(): array
    {
        return [
            'components' => [
                'submissions'   => Submissions::class,
                'formConfig'    => FormConfig::class,
                'webhook'       => WebhookIntegration::class,
                'notifications' => Notifications::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        // Optional rename: let a site relabel the plugin in the CP (Plugins screen,
        // settings breadcrumb, and — via getCpNavItem — the sidebar). Blank keeps
        // "Downtoll". Guarded so a partially-constructed settings model can't blank it.
        $name = $this->getSettings()->pluginName ?? '';
        if ($name !== '') {
            $this->name = $name;
        }

        $this->registerFieldType();
        $this->registerElementType();
        $this->registerRoutes();
        $this->registerPermissions();
        $this->registerTwigVariable();
        $this->registerTemplateRoot();
        $this->registerGarbageCollection();

        // PRO: the shipped, integration-agnostic listener. Vendor CRM/ESP mapping belongs
        // in a SEPARATE site-side listener on the same event — see examples/.
        // Lite never fires EVENT_AFTER_SUBMISSION (see Submissions::fireAfterSubmission),
        // so this would be inert there anyway; not registering it keeps that explicit.
        if ($this->isPro()) {
            $this->submissions->on(
                Submissions::EVENT_AFTER_SUBMISSION,
                [$this->webhook, 'handleSubmission']
            );
        }
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        // parent already uses $this->name (set from the pluginName override in init());
        // fall back to "Downtoll" if it somehow came through empty.
        $item['label'] = $this->name ?: Craft::t('downtoll', 'Downtoll');
        // Bundled Font Awesome solid icon. Alternatives: torii-gate, lock-keyhole, shield-halved, vault.
        $item['icon'] = 'fence';
        $item['subnav'] = [
            'submissions' => [
                'label' => Craft::t('downtoll', 'Submissions'),
                'url' => 'downtoll/submissions',
            ],
            'config' => [
                'label' => Craft::t('downtoll', 'Form Config'),
                'url' => 'downtoll/config',
            ],
        ];
        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('downtoll/settings', [
            'plugin'   => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    private function registerFieldType(): void
    {
        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            static function (RegisterComponentTypesEvent $event): void {
                $event->types[] = GatedContentField::class;
            }
        );
    }

    private function registerElementType(): void
    {
        Event::on(
            Elements::class,
            Elements::EVENT_REGISTER_ELEMENT_TYPES,
            static function (RegisterComponentTypesEvent $event): void {
                $event->types[] = Submission::class;
            }
        );
    }

    private function registerRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            static function (RegisterUrlRulesEvent $event): void {
                $event->rules['downtoll/submit'] = 'downtoll/submit/index';
                $event->rules['downtoll/download'] = 'downtoll/download/index';
                // Dev-only QA/E2E preview of the rendered form (PreviewController
                // 404s unless devMode). Harmless to register unconditionally.
                $event->rules['downtoll/preview'] = 'downtoll/preview/form';
            }
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function (RegisterUrlRulesEvent $event): void {
                $event->rules['downtoll'] = 'downtoll/submissions/index';
                $event->rules['downtoll/submissions'] = 'downtoll/submissions/index';
                $event->rules['downtoll/config'] = 'downtoll/config/index';
            }
        );
    }

    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function (RegisterUserPermissionsEvent $event): void {
                $event->permissions[] = [
                    'heading' => Craft::t('downtoll', 'Downtoll'),
                    'permissions' => [
                        self::PERMISSION_MANAGE => [
                            'label' => Craft::t('downtoll', 'Manage gated form configuration'),
                        ],
                        self::PERMISSION_VIEW_SUBMISSIONS => [
                            'label' => Craft::t('downtoll', 'View form submissions'),
                        ],
                    ],
                ];
            }
        );
    }

    private function registerTwigVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function (Event $event): void {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('downtoll', DowntollVariable::class);
            }
        );
    }

    private function registerGarbageCollection(): void
    {
        // Retention purge (P4): the moment submissions are stored, Lite holds PII
        // indefinitely — this bounds it. Fires during `php craft gc` and Craft's
        // scheduled garbage collection; a no-op while submissionRetentionDays is 0.
        Event::on(
            Gc::class,
            Gc::EVENT_RUN,
            function (): void {
                $this->submissions->purgeExpired();
            }
        );
    }

    private function registerTemplateRoot(): void
    {
        Event::on(
            View::class,
            View::EVENT_REGISTER_CP_TEMPLATE_ROOTS,
            static function (RegisterTemplateRootsEvent $event): void {
                $event->roots['downtoll'] = __DIR__ . '/templates';
            }
        );
        Event::on(
            View::class,
            View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS,
            static function (RegisterTemplateRootsEvent $event): void {
                $event->roots['downtoll'] = __DIR__ . '/templates';
            }
        );
    }
}
