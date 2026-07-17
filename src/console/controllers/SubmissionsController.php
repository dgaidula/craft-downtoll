<?php

namespace dgaidula\downtoll\console\controllers;

use craft\console\Controller;
use dgaidula\downtoll\Plugin;
use yii\console\ExitCode;

/**
 * Manages stored Downtoll submissions from the command line.
 *
 * Craft auto-registers plugin console controllers from `console/controllers/`,
 * so this runs as `php craft downtoll/submissions/purge`.
 */
class SubmissionsController extends Controller
{
    /**
     * Hard-deletes submissions older than the configured retention window
     * (the `submissionRetentionDays` setting; 0 = disabled, purges nothing).
     */
    public function actionPurge(): int
    {
        $count = Plugin::getInstance()->submissions->purgeExpired();
        $this->stdout("Purged {$count} expired submission(s).\n");

        return ExitCode::OK;
    }
}
