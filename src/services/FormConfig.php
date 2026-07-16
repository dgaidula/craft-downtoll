<?php

namespace dgaidula\downtoll\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use dgaidula\downtoll\migrations\Install;

/**
 * Reads/writes the plugin-owned form configuration (affiliation options +
 * newsletter-list catalog).
 *
 * Stored in the {{%downtoll_config}} DB table (content layer), edited via
 * the plugin's own CP screen — so it is editable on production with
 * `allowAdminChanges` off, and ships entirely inside the plugin (Plugin-Store
 * self-contained). NOT project config.
 */
class FormConfig extends Component
{
    private ?array $data = null;

    /**
     * @return array{label:string,value:string,triggersHook:bool}[]
     */
    public function getAffiliations(): array
    {
        return $this->all()['affiliations'] ?? [];
    }

    /**
     * @return array{label:string,listId:string,triggersHook:bool}[]
     */
    public function getNewsletterLists(): array
    {
        return $this->all()['newsletterLists'] ?? [];
    }

    /** Affiliation `value`s whose option is flagged to fire the front-end hook. */
    public function triggerValues(): array
    {
        return array_values(array_map(
            static fn (array $o): string => $o['value'],
            array_filter($this->getAffiliations(), static fn (array $o): bool => !empty($o['triggersHook']))
        ));
    }

    public function getNewsletterListLabel(string $listId): ?string
    {
        foreach ($this->getNewsletterLists() as $list) {
            if (($list['listId'] ?? '') === $listId) {
                return $list['label'] ?? null;
            }
        }
        return null;
    }

    /**
     * The full config array: ['affiliations' => [...], 'newsletterLists' => [...]].
     */
    public function all(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        $row = (new Query())
            ->select(['configData'])
            ->from(Install::CONFIG_TABLE)
            ->orderBy(['id' => SORT_ASC])
            ->scalar();

        $this->data = ($row && ($decoded = Json::decodeIfJson($row)) && is_array($decoded))
            ? $decoded
            : ['affiliations' => [], 'newsletterLists' => []];

        // Back-compat: the per-affiliation flag was renamed
        // `triggersDistrictLookup` → `triggersHook`. Normalize legacy rows on
        // read so existing data (and older installs) keep working; the next
        // save() rewrites them under the new key. (Iterate the real array, not a
        // `?? []` temporary, so the by-reference mutations actually persist.)
        if (!empty($this->data['affiliations']) && is_array($this->data['affiliations'])) {
            foreach ($this->data['affiliations'] as &$aff) {
                if (!is_array($aff)) {
                    continue;
                }
                if (!array_key_exists('triggersHook', $aff) && array_key_exists('triggersDistrictLookup', $aff)) {
                    $aff['triggersHook'] = $aff['triggersDistrictLookup'];
                }
                unset($aff['triggersDistrictLookup']);
            }
            unset($aff);
        }

        return $this->data;
    }

    /**
     * Persist the config (single row, upsert). Returns true on success.
     *
     * @param array{affiliations:array,newsletterLists:array} $config
     */
    public function save(array $config): bool
    {
        // Editable tables post an empty string (not an array) when left blank,
        // so coerce before filtering. Both tables are optional.
        $affiliations = is_array($config['affiliations'] ?? null) ? $config['affiliations'] : [];
        $newsletterLists = is_array($config['newsletterLists'] ?? null) ? $config['newsletterLists'] : [];

        $clean = [
            'affiliations' => array_values(array_filter(
                $affiliations,
                static fn ($r) => is_array($r) && !empty($r['label']) && !empty($r['value'])
            )),
            'newsletterLists' => array_values(array_filter(
                $newsletterLists,
                static fn ($r) => is_array($r) && !empty($r['label']) && !empty($r['listId'])
            )),
        ];

        // Normalize the checkbox column to a real bool (accept the legacy
        // `triggersDistrictLookup` key on the way in; only `triggersHook` is stored).
        foreach ($clean['affiliations'] as &$row) {
            $row['triggersHook'] = !empty($row['triggersHook']) || !empty($row['triggersDistrictLookup']);
            unset($row['triggersDistrictLookup']);
        }
        unset($row);

        // Newsletter rows carry the same `triggersHook` flag: a checked box flagged
        // this way is a routing MODIFIER (it fires the submission hook so listeners
        // can branch/classify) rather than a plain opt-in. Coerce to a real bool.
        foreach ($clean['newsletterLists'] as &$row) {
            $row['triggersHook'] = !empty($row['triggersHook']);
        }
        unset($row);

        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new \DateTime());
        $existingId = (new Query())->select(['id'])->from(Install::CONFIG_TABLE)->scalar();

        if ($existingId) {
            $db->createCommand()->update(Install::CONFIG_TABLE, [
                'configData'  => Json::encode($clean),
                'dateUpdated' => $now,
            ], ['id' => $existingId])->execute();
        } else {
            $db->createCommand()->insert(Install::CONFIG_TABLE, [
                'configData'  => Json::encode($clean),
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid'         => \craft\helpers\StringHelper::UUID(),
            ])->execute();
        }

        $this->data = $clean;
        return true;
    }
}
