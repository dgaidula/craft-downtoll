<?php

namespace iceboxind\downtoll\migrations;

use craft\db\Migration;

/**
 * Install migration.
 *
 * Creates the plugin-owned config table. This lives in the CONTENT layer
 * (a plain DB table written by the plugin's own controllers), NOT in project
 * config — so editors can manage form structure + newsletter routing on
 * production even when `allowAdminChanges` is false. The plugin ships and
 * owns this table, keeping it fully self-contained for the Plugin Store.
 */
class Install extends Migration
{
    public const CONFIG_TABLE = '{{%downtoll_config}}';
    public const SUBMISSIONS_TABLE = '{{%downtoll_submissions}}';

    public function safeUp(): bool
    {
        if (!$this->db->tableExists(self::CONFIG_TABLE)) {
            $this->createTable(self::CONFIG_TABLE, [
                'id'          => $this->primaryKey(),
                'configData'  => $this->longText(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid'         => $this->uid(),
            ]);
        }

        // Lead storage: an element sub-table keyed to {{%elements}}.id (standard
        // element pattern). Commonly-queried fields get real columns; `payload`
        // keeps the FULL normalized Title-Case submission as JSON so new form
        // fields never require a migration.
        if (!$this->db->tableExists(self::SUBMISSIONS_TABLE)) {
            $this->createTable(self::SUBMISSIONS_TABLE, [
                'id'               => $this->integer()->notNull(),
                'email'            => $this->string(),
                'firstName'        => $this->string(),
                'lastName'         => $this->string(),
                'state'            => $this->string(64),
                'affiliation'      => $this->string(),
                'otherAffiliation' => $this->string(),
                'schoolDistrict'   => $this->string(),
                'districtId'       => $this->string(),
                'downloadName'     => $this->string(),
                'resourceId'       => $this->integer(),
                'siteId'           => $this->integer(),
                'newsletterLists'  => $this->text(),
                'payload'          => $this->longText()->notNull(),
                'dateCreated'      => $this->dateTime()->notNull(),
                'dateUpdated'      => $this->dateTime()->notNull(),
                'uid'              => $this->uid(),
                'PRIMARY KEY([[id]])',
            ]);
            $this->createIndex(null, self::SUBMISSIONS_TABLE, ['resourceId']);
            $this->createIndex(null, self::SUBMISSIONS_TABLE, ['siteId']);
            $this->addForeignKey(null, self::SUBMISSIONS_TABLE, ['id'], '{{%elements}}', ['id'], 'CASCADE', null);
        }

        return true;
    }

    public function safeDown(): bool
    {
        // Remove the submission elements' base rows first (cascades to the
        // sub-table via the FK), then drop the plugin tables.
        $this->delete('{{%elements}}', ['type' => 'iceboxind\\downtoll\\elements\\Submission']);
        $this->dropTableIfExists(self::SUBMISSIONS_TABLE);
        $this->dropTableIfExists(self::CONFIG_TABLE);
        return true;
    }
}
