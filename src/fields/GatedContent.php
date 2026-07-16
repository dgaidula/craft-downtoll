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
            $config = new ResourceConfig();

            // The asset element-select posts an array of IDs on save (e.g. ['123']);
            // a stored value decodes to a scalar int. Handle both.
            $assetId = $data['assetId'] ?? null;
            if (is_array($assetId)) {
                $assetId = $assetId[0] ?? null;
            }
            $config->assetId = ($assetId !== null && $assetId !== '') ? (int) $assetId : null;
            $config->successMode = $data['successMode'] ?? Plugin::getInstance()->getSettings()->defaultSuccessMode;
            $config->includeAffiliation = (bool) ($data['includeAffiliation'] ?? true);
            $config->includeNewsletter = (bool) ($data['includeNewsletter'] ?? false);
            $ids = $data['newsletterListIds'] ?? [];
            $config->newsletterListIds = is_array($ids) ? array_values(array_filter($ids)) : [];

            // Required fields: default the standard set when never configured; once
            // saved (key present), respect the editor's choice (even an empty set).
            if (array_key_exists('requiredFields', $data)) {
                $rf = $data['requiredFields'];
                $config->requiredFields = is_array($rf) ? array_values(array_filter($rf)) : [];
            }

            // Custom CSS class: sanitize to safe class-name chars (no injection).
            $config->cssClass = trim(preg_replace('/[^A-Za-z0-9 _\-]/', '', (string) ($data['cssClass'] ?? '')));

            // Per-resource newsletter heading (content). Default to the global setting
            // only when the field has never been configured (key absent).
            $config->newsletterHeading = array_key_exists('newsletterHeading', $data)
                ? trim((string) $data['newsletterHeading'])
                : Plugin::getInstance()->getSettings()->newsletterHeading;

            $config->successMessage = trim((string) ($data['successMessage'] ?? ''));
            $config->errorMessage = trim((string) ($data['errorMessage'] ?? ''));
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
