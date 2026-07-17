<?php

namespace dgaidula\downtoll\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;

/**
 * Element query for {@see \dgaidula\downtoll\elements\Submission}.
 *
 * Joins the {{%downtoll_submissions}} sub-table and exposes the lead columns,
 * with fluent filters for the commonly-queried ones.
 */
class SubmissionQuery extends ElementQuery
{
    /** @var mixed Narrows the results by submitter email. */
    public mixed $email = null;

    /** @var mixed Narrows the results by the gated entry's element id. */
    public mixed $resourceId = null;

    /** @used-by email */
    public function email(mixed $value): static
    {
        $this->email = $value;
        return $this;
    }

    /** @used-by resourceId */
    public function resourceId(mixed $value): static
    {
        $this->resourceId = $value;
        return $this;
    }

    protected function beforePrepare(): bool
    {
        if (!parent::beforePrepare()) {
            return false;
        }

        $this->joinElementTable('downtoll_submissions');

        // `siteId` is deliberately NOT selected here: the base element query
        // already resolves it from elements_sites, and re-selecting the
        // sub-table copy would shadow it.
        $this->query->addSelect([
            'downtoll_submissions.email',
            'downtoll_submissions.firstName',
            'downtoll_submissions.lastName',
            'downtoll_submissions.state',
            'downtoll_submissions.affiliation',
            'downtoll_submissions.otherAffiliation',
            'downtoll_submissions.schoolDistrict',
            'downtoll_submissions.districtId',
            'downtoll_submissions.downloadName',
            'downtoll_submissions.resourceId',
            'downtoll_submissions.newsletterLists',
            'downtoll_submissions.payload',
        ]);

        if ($this->email) {
            $this->subQuery->andWhere(Db::parseParam('downtoll_submissions.email', $this->email));
        }

        if ($this->resourceId) {
            $this->subQuery->andWhere(Db::parseParam('downtoll_submissions.resourceId', $this->resourceId));
        }

        return true;
    }
}
