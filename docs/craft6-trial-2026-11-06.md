# Craft 6 compatibility trial: Downtoll (2026-11-05/06)

A throwaway Craft **6.0.0-alpha.20** site (`~/sw/craft6-trial`, DDEV `craft6-trial`, PHP 8.5, MySQL 8.4) ran all five of Dan’s plugins through `craftcms/yii2-adapter`. This note keeps Downtoll’s part so it is not lost; the full report, with versions, setup gotchas and every citation, is `monetization-ops/research/craft6-trial-2026-11-06.md` (a copy of `~/sw/craft6-trial/REPORT.md`). Craft 6 is alpha: this is a snapshot, to be re-run at beta. Recommendation (2026-11-06): no port work until Craft 6 reaches beta, then one port pass per plugin from this note.

| Plugin | Verdict | Top blocker | Porting |
|---|---|---|---|
| Downtoll (`3caaf20`) | Works with the constraint widened | `craftcms/cms ^5.0`; one deprecation (`View::renderTemplate`) | small |

**Finding #1, every plugin:** `composer.json` requires `craftcms/cms ^5` (or `^4.5.0 || ^5.0.0`), which blocks install on Craft 6; Craft 6 and the adapter need PHP ^8.5.

## Downtoll — small
Tested: install, `craft up`, settings render and save; CP Submissions, Config and the entry editor with the Gated
Content field (200); a `GatedContent` field via the Craft 5 API rendered the full form. End to end
(`scratch/downtoll-submit.mjs`): POST `/actions/downtoll/submit` returned success with a `downloadUrl`, the lead row was
stored, the signed URL downloaded the asset (200, attachment), a tampered token 404’d.
Broke: only the `renderTemplate` deprecation. Not tested: Pro email, webhook, live reCAPTCHA, `reload` mode, CP field
JS, the asset picker. No tests of its own.

## Deprecations naming plugin code
From the `deprecationerrors` table and `storage/logs` (`scratch/deprecations.mjs`):

| Plugin file:line | Deprecated call | Craft 6 change |
|---|---|---|
| sorts `src/Plugin.php:212` | `View::registerTwigExtension()` → `Twig::registerExtension()` | CHANGELOG 6.0.0-alpha.1, Twig section (line 3136) |
| sorts `src/Plugin.php:164`, downtoll `src/web/twig/DowntollVariable.php:36`, videocinch `src/Plugin.php:226` | `View::renderTemplate()` → `TemplateManager::renderTemplate()` / `template()` | same section (line 3137) |
| sorts `src/services/EngineService.php:168`, `ContentFixer.php:225` | `Craft::$app->sites` → `app(Sites::class)` | line 3095 |
| sorts `src/services/ContentFixer.php:167` | `Craft::$app->getIsMultiSite()` → `Sites::isMultiSite()` | line 3095 |
| sorts `src/services/AuditScanner.php:42` | `EntryQuery::orderBy()` with an array | `ElementQuery.php:870`; CHANGELOG lines 1071, 1080 |

## Shared blockers and patterns
1. Composer: all five hit `craftcms/cms ^5`; Craft 6 needs PHP ^8.5.
2. The adapter needs `-W` on today’s starter.
3. Yii-era API use per plugin (downtoll/lithocinch/sesame/sorts/videocinch): `Craft::$app->` 43/16/79/27/13;
   `use yii\` 10/5/12/6/3; `use craft\` 53/33/72/52/25; `Event::on` 9/6/11/8/5. All of it loaded.
4. **Where the adapter does not bridge** (each seen here): a typed-property change (`TranslationMethod`); a changed core
   call convention (`setAttributes`); a removed library class (`FieldArgument`); the response/header model
   (`{% header %}`); DI model defaults (`Craft::$container->set(ImageTransform::class…)`); route-rule parameter binding;
   exception-to-status mapping (403 → 500). A grep for these across the other plugins is a cheap first step.
5. Deprecations `View::renderTemplate`, `Craft::$app->sites`, `getIsMultiSite`, `registerTwigExtension` appear in 3 of 5.
6. The shared test harness (Sorts, Sesame) relies on `ddev craft shell` and Yii REPL semantics, a `queue` table, Craft 5
   CSRF and login, CP HTML scraping, `config/project` (Craft 6: `config/craft/project`), and hard-coded hosts and paths.
7. `config/craft/<handle>.php` plugin config files are picked up; multi-environment config files are deprecated.
