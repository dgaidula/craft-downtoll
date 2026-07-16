<?php

namespace dgaidula\downtoll\migrations;

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

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::CONFIG_TABLE);
        return true;
    }
}
