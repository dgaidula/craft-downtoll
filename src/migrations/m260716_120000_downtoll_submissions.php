<?php

namespace dgaidula\downtoll\migrations;

use craft\db\Migration;

/**
 * m260716_120000_downtoll_submissions migration.
 *
 * Adds the {{%downtoll_submissions}} element sub-table (lead storage) to
 * already-installed sites. Mirrors the table created in {@see Install} for
 * fresh installs; idempotent so running it after a fresh install is a no-op.
 */
class m260716_120000_downtoll_submissions extends Migration
{
    public const SUBMISSIONS_TABLE = '{{%downtoll_submissions}}';

    public function safeUp(): bool
    {
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
        // sub-table via the FK), then drop the table itself.
        $this->delete('{{%elements}}', ['type' => 'dgaidula\\downtoll\\elements\\Submission']);
        $this->dropTableIfExists(self::SUBMISSIONS_TABLE);
        return true;
    }
}
