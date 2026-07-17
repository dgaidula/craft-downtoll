<?php

namespace dgaidula\downtoll\controllers;

use craft\web\Controller;
use dgaidula\downtoll\Plugin;
use yii\web\Response;

/**
 * CP lead index: lists the captured Submission elements via the native
 * element index (search / sort / pagination for free; export is Pro-gated
 * on the element itself).
 */
class SubmissionsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requirePermission(Plugin::PERMISSION_VIEW_SUBMISSIONS);
        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('downtoll/submissions/_index');
    }
}
