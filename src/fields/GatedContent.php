<?php

namespace dgaidula\downtoll\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\helpers\Json;
use dgaidula\downtoll\models\ResourceConfig;
use dgaidula\downtoll\Plugin;

/**
 * The "Gated Content" field. A dev adds it to any gateable entry type once
 * (project config, deployed); editors then configure gating PER ENTRY — asset,
 * newsletter list, success mode — as content, editable on production.
 *
 * The normalized value is a {@see ResourceConfig} enriched from {@see FormConfig}
 * (affiliation catalog) so templates can render directly:
 *   {{ entry.myGatedField | raw }}   (or)   {{ craft.downtoll.render(entry.myGatedField) }}
 */
class GatedContent extends Field
{
    public static function displayName(): string
    {
        return Craft::t('downtoll', 'Gated Content');
    }

    public static function icon(): string
    {
        return 'fence';
    }

    public function normalizeValue(mixed $value, ?ElementInterface $element = null): ResourceConfig
    {
        if ($value instanceof ResourceConfig) {
            $config = $value;
        } else {
            $data = is_string($value) ? (Json::decodeIfJson($value) ?: []) : (is_array($value) ? $value : []);
            $settings = Plugin::getInstance()->getSettings();

            // PRO: seed a brand-new (never-saved) field from the admin-defined default
            // ResourceConfig. An empty $data is the fresh-field signal — a saved entry
            // (non-empty $data) is NEVER re-seeded, and an asset is never defaulted.
            // Lite, and Pro-without-a-saved-default, both fall through to the same
            // hardcoded fresh-field defaults as before.
            if ($data === []) {
                $default = $settings->defaultResourceConfig;
                if (Plugin::getInstance()->isPro() && is_array($default) && $default !== []) {
                    $data = $default;
                    $data['assetId'] = null;
                }
            }

            $config = ResourceConfig::fromFieldData($data, $settings);
        }

        // Identify + enrich from the live element and the plugin-owned catalog.
        if ($element) {
            $config->resourceId = $element->id ?? '';
            $config->siteName = $element->getSite()->name ?? Craft::$app->getSites()->getCurrentSite()->name;
        }
        Plugin::getInstance()->submissions->enrich($config);

        return $config;
    }

    public function serializeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if (!$value instanceof ResourceConfig) {
            return $value;
        }

        return Json::encode([
            'assetId'            => $value->assetId,
            'successMode'        => $value->successMode,
            'includeAffiliation' => $value->includeAffiliation,
            'includeNewsletter'  => $value->includeNewsletter,
            'newsletterListIds'  => $value->newsletterListIds,
            'requiredFields'     => $value->requiredFields,
            'cssClass'           => $value->cssClass,
            'newsletterHeading'  => $value->newsletterHeading,
            'successMessage'     => $value->successMessage,
            'errorMessage'       => $value->errorMessage,
        ]);
    }

    protected function inputHtml(mixed $value, ?ElementInterface $element = null, bool $inline = false): string
    {
        /** @var ResourceConfig $value */
        $formConfig = Plugin::getInstance()->formConfig;

        // Checkbox-group options: every catalog list this resource may offer.
        $listOptions = [];
        foreach ($formConfig->getNewsletterLists() as $list) {
            $listOptions[] = ['label' => strip_tags((string) $list['label']), 'value' => $list['listId']];
        }

        return Craft::$app->getView()->renderTemplate('downtoll/_field/input', [
            'name'        => $this->handle,
            'value'       => $value,
            'listOptions' => $listOptions,
            'asset'       => $value->assetId ? Craft::$app->getAssets()->getAssetById($value->assetId) : null,
        ]);
    }
}
