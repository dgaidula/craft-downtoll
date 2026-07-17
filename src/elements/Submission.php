<?php

namespace dgaidula\downtoll\elements;

use Craft;
use craft\base\Element;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\Json;
use dgaidula\downtoll\elements\db\SubmissionQuery;
use dgaidula\downtoll\Plugin;

/**
 * A captured gated-form lead, stored as a native element so the CP gets
 * search / sort / pagination / export for free. No field layout and not
 * localized: the commonly-queried fields are real columns on the
 * {{%downtoll_submissions}} sub-table, and `payload` keeps the FULL
 * normalized Title-Case submission as JSON so new form fields never
 * require a migration.
 */
class Submission extends Element
{
    public ?string $email = null;
    public ?string $firstName = null;
    public ?string $lastName = null;
    public ?string $state = null;
    public ?string $affiliation = null;
    public ?string $otherAffiliation = null;
    public ?string $schoolDistrict = null;
    public ?string $districtId = null;
    public ?string $downloadName = null;

    /** The gated entry's element id. (`siteId` lives on the base Element.) */
    public ?int $resourceId = null;

    /** The full normalized Title-Case submission, as a JSON string. */
    public ?string $payload = null;

    /** @var string[]|null Checked newsletter list IDs (stored as JSON). */
    private ?array $_newsletterLists = null;

    public static function displayName(): string
    {
        return Craft::t('downtoll', 'Submission');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('downtoll', 'submission');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('downtoll', 'Submissions');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('downtoll', 'submissions');
    }

    public static function refHandle(): ?string
    {
        return 'downtollsubmission';
    }

    public static function isLocalized(): bool
    {
        return false;
    }

    /**
     * @return SubmissionQuery The newly created [[SubmissionQuery]] instance.
     */
    public static function find(): SubmissionQuery
    {
        return new SubmissionQuery(static::class);
    }

    /**
     * Accepts the raw JSON string from the query select or a decoded array.
     *
     * @param array|string|null $value
     */
    public function setNewsletterLists(array|string|null $value): void
    {
        if (is_string($value)) {
            $value = $value !== '' ? Json::decodeIfJson($value) : null;
        }
        $this->_newsletterLists = is_array($value) ? array_values($value) : null;
    }

    /**
     * @return string[]|null
     */
    public function getNewsletterLists(): ?array
    {
        return $this->_newsletterLists;
    }

    public function getUiLabel(): string
    {
        if ($this->email !== null && $this->email !== '') {
            return $this->email;
        }
        $name = trim(($this->firstName ?? '') . ' ' . ($this->lastName ?? ''));

        return $name !== '' ? $name : Craft::t('downtoll', 'Submission {id}', ['id' => $this->id]);
    }

    public function afterSave(bool $isNew): void
    {
        $data = [
            'email'            => $this->email,
            'firstName'        => $this->firstName,
            'lastName'         => $this->lastName,
            'state'            => $this->state,
            'affiliation'      => $this->affiliation,
            'otherAffiliation' => $this->otherAffiliation,
            'schoolDistrict'   => $this->schoolDistrict,
            'districtId'       => $this->districtId,
            'downloadName'     => $this->downloadName,
            'resourceId'       => $this->resourceId,
            'siteId'           => $this->siteId,
            'newsletterLists'  => $this->_newsletterLists !== null ? Json::encode($this->_newsletterLists) : null,
            'payload'          => $this->payload ?? '{}',
        ];

        if ($isNew) {
            Db::insert('{{%downtoll_submissions}}', ['id' => $this->id] + $data);
        } else {
            Db::update('{{%downtoll_submissions}}', $data, ['id' => $this->id]);
        }

        parent::afterSave($isNew);
    }

    protected static function defineSources(string $context): array
    {
        return [
            [
                'key' => '*',
                'label' => Craft::t('downtoll', 'All submissions'),
                'criteria' => [],
                'defaultSort' => ['dateCreated', 'desc'],
            ],
        ];
    }

    protected static function defineTableAttributes(): array
    {
        return array_merge(parent::defineTableAttributes(), [
            'email'        => ['label' => Craft::t('downtoll', 'Email')],
            'fullName'     => ['label' => Craft::t('downtoll', 'Name')],
            'state'        => ['label' => Craft::t('downtoll', 'State')],
            'affiliation'  => ['label' => Craft::t('downtoll', 'Affiliation')],
            'downloadName' => ['label' => Craft::t('downtoll', 'Download')],
            'resourceId'   => ['label' => Craft::t('downtoll', 'Resource')],
        ]);
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['email', 'fullName', 'state', 'affiliation', 'downloadName', 'dateCreated'];
    }

    protected function attributeHtml(string $attribute): string
    {
        switch ($attribute) {
            case 'fullName':
                return Html::encode(trim(($this->firstName ?? '') . ' ' . ($this->lastName ?? '')));
            case 'resourceId':
                if (!$this->resourceId) {
                    return '';
                }
                $entry = Craft::$app->getElements()->getElementById($this->resourceId);
                if ($entry && ($url = $entry->getCpEditUrl())) {
                    return Html::a(Html::encode($entry->getUiLabel()), $url);
                }
                return Html::encode((string)$this->resourceId);
            default:
                return parent::attributeHtml($attribute);
        }
    }

    protected static function defineSortOptions(): array
    {
        return [
            [
                'label' => Craft::t('downtoll', 'Date'),
                'orderBy' => 'elements.dateCreated',
                'attribute' => 'dateCreated',
                'defaultDir' => 'desc',
            ],
            [
                'label' => Craft::t('downtoll', 'Email'),
                'orderBy' => 'downtoll_submissions.email',
                'attribute' => 'email',
            ],
            [
                'label' => Craft::t('downtoll', 'Last name'),
                'orderBy' => 'downtoll_submissions.lastName',
                'attribute' => 'fullName',
            ],
        ];
    }

    protected static function defineSearchableAttributes(): array
    {
        return ['email', 'firstName', 'lastName', 'schoolDistrict', 'downloadName'];
    }

    /**
     * PRO gate: lead CSV export. The element index gives export for free, so
     * Lite hides the Export button by declaring no exporters.
     */
    protected static function defineExporters(string $source): array
    {
        if (!Plugin::getInstance()->isPro()) {
            return [];
        }

        return parent::defineExporters($source);
    }
}
