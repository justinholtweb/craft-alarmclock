<?php

namespace justinholtweb\alarmclock\controllers;

use Craft;
use craft\elements\Entry;
use craft\web\Controller;
use craft\web\Response;
use justinholtweb\alarmclock\Plugin;
use yii\web\NotFoundHttpException;

class DiagnosticsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_VIEW);

        return true;
    }

    /** Is scheduled publishing working on this installation at all? */
    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $findings = $plugin->diagnostics->health();

        return $this->renderTemplate('alarm-clock/diagnostics', [
            'findings' => $findings,
            'hasProblems' => $plugin->diagnostics->hasProblems($findings),
            'settings' => $plugin->getSettings(),
            'canManage' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE),
        ]);
    }

    /** Why is this one entry not on the site? */
    public function actionEntry(int $entryId, ?string $siteHandle = null): Response
    {
        $siteId = $siteHandle
            ? Craft::$app->getSites()->getSiteByHandle($siteHandle)?->id
            : Craft::$app->getSites()->getCurrentSite()->id;

        $entry = Entry::find()
            ->id($entryId)
            ->siteId($siteId)
            ->status(null)
            ->drafts(null)
            ->provisionalDrafts(null)
            ->revisions(null)
            ->one();

        if (!$entry) {
            throw new NotFoundHttpException('Entry not found.');
        }

        $findings = Plugin::getInstance()->diagnostics->explain($entry);

        return $this->renderTemplate('alarm-clock/diagnose-entry', [
            'entry' => $entry,
            'findings' => $findings,
            'hasProblems' => Plugin::getInstance()->diagnostics->hasProblems($findings),
        ]);
    }
}
