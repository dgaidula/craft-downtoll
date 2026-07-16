<?php

namespace dgaidula\downtoll\controllers;

use Craft;
use craft\elements\Asset;
use craft\web\Controller;
use dgaidula\downtoll\models\ResourceConfig;
use dgaidula\downtoll\web\assets\form\FormAsset;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * DEV-ONLY: renders a standalone page with a real, fully-wired gated form so the
 * front-end JS can be exercised in a browser (and by the Playwright E2E) WITHOUT
 * creating any Craft section/entry — so it never touches project config.
 *
 * Hard-gated behind devMode; 404 everywhere else. Not part of the product surface.
 */
class PreviewController extends Controller
{
    protected array|bool|int $allowAnonymous = ['form'];

    public $enableCsrfValidation = false;

    public function actionForm(): Response
    {
        if (!Craft::$app->getConfig()->getGeneral()->devMode) {
            throw new NotFoundHttpException();
        }

        $request = Craft::$app->getRequest();
        $mode = $request->getQueryParam('mode') === 'reload' ? 'reload' : 'swap';

        // A real asset so the signed download URL resolves. Prefer an explicit id,
        // else the first asset in the system.
        $assetId = (int) $request->getQueryParam('asset');
        if (!$assetId) {
            $asset = Asset::find()->kind('image')->one() ?: Asset::find()->one();
            $assetId = $asset?->id ?? 0;
        }

        $config = new ResourceConfig();
        $config->resourceId = 'preview';
        $config->assetId = $assetId ?: null;
        $config->successMode = $mode;
        $config->includeAffiliation = true;
        $config->includeNewsletter = true;
        $config->requiredFields = ['first-name', 'last-name', 'email'];
        $config->siteName = Craft::$app->getSites()->getCurrentSite()->name;

        // Built directly (not via enrich(), which reads the site's catalog) so the
        // preview is self-contained: a trigger affiliation exercises the district
        // toggle, and one newsletter list renders the opt-in. _form.twig signs the
        // token from this config; the submit decodes + trusts it.
        $config->affiliationOptions = [
            ['label' => 'Parent / Advocate', 'value' => 'parent', 'triggersHook' => false],
            ['label' => 'School Food Professional', 'value' => 'school', 'triggersHook' => true],
        ];
        $config->triggerValues = ['school'];
        $config->newsletterLists = [
            ['listId' => 'preview-list', 'label' => 'Monthly newsletter', 'triggersHook' => false],
        ];
        $config->newsletterListIds = ['preview-list'];

        $view = Craft::$app->getView();
        $view->registerAssetBundle(FormAsset::class);

        $formHtml = $view->renderTemplate('downtoll/_form', ['config' => $config]);

        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Downtoll preview</title>'
            . $view->getHeadHtml()
            . '</head><body><main style="max-width:40rem;margin:2rem auto;padding:0 1rem">'
            . $formHtml
            . '</main>' . $view->getBodyHtml() . '</body></html>';

        return $this->asRaw($html);
    }
}
