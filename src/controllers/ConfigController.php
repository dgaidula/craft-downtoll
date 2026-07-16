<?php

namespace dgaidula\downtoll\controllers;

use Craft;
use craft\web\Controller;
use craft\web\UploadedFile;
use dgaidula\downtoll\Plugin;
use yii\web\Response;

/**
 * CP screen for the plugin-owned form configuration (affiliation options +
 * newsletter-list catalog). Backed by the plugin's own DB table, so it is
 * editable on production even when `allowAdminChanges` is false.
 */
class ConfigController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        // Editors (not just admins) may manage this — it's content, not project config.
        $this->requirePermission(Plugin::PERMISSION_MANAGE);
        return true;
    }

    public function actionIndex(): Response
    {
        $config = Plugin::getInstance()->formConfig->all();

        return $this->renderTemplate('downtoll/index', [
            'affiliations'    => $config['affiliations'] ?? [],
            'newsletterLists' => $config['newsletterLists'] ?? [],
        ]);
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();

        $saved = Plugin::getInstance()->formConfig->save([
            'affiliations'    => $request->getBodyParam('affiliations', []),
            'newsletterLists' => $request->getBodyParam('newsletterLists', []),
        ]);

        if (!$saved) {
            Craft::$app->getSession()->setError(Craft::t('downtoll', 'Couldn’t save configuration.'));
            return $this->renderTemplate('downtoll/index', [
                'affiliations'    => $request->getBodyParam('affiliations', []),
                'newsletterLists' => $request->getBodyParam('newsletterLists', []),
            ]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('downtoll', 'Configuration saved.'));
        return $this->redirectToPostedUrl();
    }

    /**
     * Download the full form config (both tables) as a single CSV so it can be
     * copied between environments. Columns: type, label, value_or_listId,
     * triggersHook — where `type` is "affiliation" or "newsletterList".
     */
    public function actionExport(): Response
    {
        $config = Plugin::getInstance()->formConfig->all();

        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, ['type', 'label', 'value_or_listId', 'triggersHook']);

        foreach ($config['affiliations'] ?? [] as $row) {
            fputcsv($fh, [
                'affiliation',
                $row['label'] ?? '',
                $row['value'] ?? '',
                !empty($row['triggersHook']) ? '1' : '0',
            ]);
        }
        foreach ($config['newsletterLists'] ?? [] as $row) {
            fputcsv($fh, [
                'newsletterList',
                $row['label'] ?? '',
                $row['listId'] ?? '',
                !empty($row['triggersHook']) ? '1' : '0',
            ]);
        }

        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        // Encode the source environment in the filename so a copied file stays
        // traceable across the environments.
        $filename = sprintf(
            'downtoll-config-%s-%s.csv',
            Craft::$app->env ?: 'env',
            (new \DateTime())->format('Ymd-His')
        );

        return Craft::$app->getResponse()->sendContentAsFile($csv, $filename, [
            'mimeType' => 'text/csv',
        ]);
    }

    /**
     * Replace the entire form config from an uploaded CSV (full overwrite).
     * Expects the columns produced by {@see actionExport()}. Rows whose `type`
     * is neither "affiliation" nor "newsletterList" are ignored; if none match,
     * the import is rejected rather than silently wiping the config.
     */
    public function actionImport(): Response
    {
        $this->requirePostRequest();

        $file = UploadedFile::getInstanceByName('configFile');
        if (!$file || $file->getHasError()) {
            Craft::$app->getSession()->setError(Craft::t('downtoll', 'Couldn’t read the uploaded file.'));
            return $this->redirectToPostedUrl();
        }

        $handle = fopen($file->tempName, 'r');
        if ($handle === false) {
            Craft::$app->getSession()->setError(Craft::t('downtoll', 'Couldn’t read the uploaded file.'));
            return $this->redirectToPostedUrl();
        }

        fgetcsv($handle); // discard the header row

        $affiliations = [];
        $newsletterLists = [];
        $recognized = 0;

        while (($cells = fgetcsv($handle)) !== false) {
            if ($cells === [null]) {
                continue; // blank line
            }
            [$type, $label, $valueOrListId, $trigger] = array_pad($cells, 4, '');
            switch (trim((string)$type)) {
                case 'affiliation':
                    $recognized++;
                    $affiliations[] = [
                        'label' => (string)$label,
                        'value' => (string)$valueOrListId,
                        'triggersHook' => in_array(
                            strtolower(trim((string)$trigger)),
                            ['1', 'true', 'yes', 'y', 'x'],
                            true
                        ),
                    ];
                    break;
                case 'newsletterList':
                    $recognized++;
                    $newsletterLists[] = [
                        'label' => (string)$label,
                        'listId' => (string)$valueOrListId,
                        'triggersHook' => in_array(
                            strtolower(trim((string)$trigger)),
                            ['1', 'true', 'yes', 'y', 'x'],
                            true
                        ),
                    ];
                    break;
            }
        }
        fclose($handle);

        if ($recognized === 0) {
            Craft::$app->getSession()->setError(Craft::t('downtoll', 'No valid rows found. Expected a “type” column of “affiliation” or “newsletterList”.'));
            return $this->redirectToPostedUrl();
        }

        // FormConfig::save() overwrites the single config row, so this is a
        // full replace; it also filters empties and normalizes the checkbox.
        $saved = Plugin::getInstance()->formConfig->save([
            'affiliations'    => $affiliations,
            'newsletterLists' => $newsletterLists,
        ]);

        if (!$saved) {
            Craft::$app->getSession()->setError(Craft::t('downtoll', 'Couldn’t save the imported configuration.'));
            return $this->redirectToPostedUrl();
        }

        Craft::$app->getSession()->setNotice(Craft::t('downtoll', 'Configuration imported.'));
        return $this->redirectToPostedUrl();
    }
}
