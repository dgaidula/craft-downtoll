<?php

namespace iceboxind\downtoll\migrations;

use Craft;
use craft\db\Migration;
use iceboxind\downtoll\elements\Submission;
use iceboxind\downtoll\fields\GatedContent;

/**
 * m260917_120000_rename_vendor_namespace migration.
 *
 * 1.3.0 moved the plugin from the `dgaidula\downtoll` namespace to
 * `iceboxind\downtoll` (the Composer vendor changed with it; the GitHub repo did
 * not move). Two class names live OUTSIDE the code and have to follow, or an
 * already-installed site is left with a "missing" field type and orphaned leads:
 *
 *  - the Submission element type, stored per row in {{%elements}}.type (and in
 *    any saved element-index settings) — content, so always rewritten;
 *  - the Gated Content field type, stored in project config at fields.<uid>.type
 *    — rewritten only when this environment's config is still behind, the
 *    standard guard so a deploy that already carries the updated YAML isn't
 *    fought by its own migration.
 *
 * Idempotent: on a fresh install, or a site that never ran under the old
 * namespace, every statement matches nothing.
 */
class m260917_120000_rename_vendor_namespace extends Migration
{
    private const OLD_ELEMENT_TYPE = 'dgaidula\\downtoll\\elements\\Submission';
    private const OLD_FIELD_TYPE = 'dgaidula\\downtoll\\fields\\GatedContent';

    public function safeUp(): bool
    {
        $this->update('{{%elements}}', ['type' => Submission::class], ['type' => self::OLD_ELEMENT_TYPE], [], false);
        $this->update('{{%elementindexsettings}}', ['type' => Submission::class], ['type' => self::OLD_ELEMENT_TYPE], [], false);

        $projectConfig = Craft::$app->getProjectConfig();
        $schemaVersion = (string) $projectConfig->get('plugins.downtoll.schemaVersion', true);

        if (version_compare($schemaVersion, '1.2.0', '<')) {
            foreach ($projectConfig->get('fields') ?? [] as $uid => $field) {
                if (($field['type'] ?? null) === self::OLD_FIELD_TYPE) {
                    $projectConfig->set("fields.$uid.type", GatedContent::class, 'Downtoll: vendor namespace moved to iceboxind');
                }
            }
        }

        return true;
    }

    public function safeDown(): bool
    {
        echo "m260917_120000_rename_vendor_namespace cannot be reverted.\n";
        return false;
    }
}
