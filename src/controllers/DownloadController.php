<?php

namespace dgaidula\downtoll\controllers;

use Craft;
use craft\web\Controller;
use dgaidula\downtoll\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Serves a gated asset, but only via a short-lived signed token issued by
 * {@see \dgaidula\downtoll\services\Submissions::signedDownloadUrl()} after
 * a successful submission. The token both authorizes the download and pins it
 * to a single asset, so the public asset URL is never exposed directly.
 */
class DownloadController extends Controller
{
    protected array|bool|int $allowAnonymous = ['index'];

    public function actionIndex(): Response
    {
        $token = (string) Craft::$app->getRequest()->getQueryParam('t', '');
        $assetId = Plugin::getInstance()->submissions->validateDownloadToken($token);

        if ($assetId === null) {
            throw new NotFoundHttpException('This download link is invalid or has expired.');
        }

        $asset = Craft::$app->getAssets()->getAssetById($assetId);
        if (!$asset) {
            throw new NotFoundHttpException('The requested file could not be found.');
        }

        // Stream the file as an attachment straight from its volume filesystem.
        return Craft::$app->getResponse()->sendStreamAsFile(
            $asset->getStream(),
            $asset->getFilename(),
            [
                'fileSize' => $asset->size,
                'mimeType' => $asset->getMimeType(),
            ]
        );
    }
}
