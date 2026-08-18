<?php

namespace justinholtweb\alarmclock\controllers;

use Craft;
use craft\elements\Entry;
use craft\helpers\DateTimeHelper;
use craft\web\Controller;
use craft\web\Response;
use DateTime;
use justinholtweb\alarmclock\Plugin;
use justinholtweb\alarmclock\records\ScheduleRecord;
use justinholtweb\alarmclock\records\TransitionRecord;
use yii\web\ForbiddenHttpException;

class DashboardController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_VIEW);

        return true;
    }

    /** What is coming, and what is being held. */
    public function actionUpcoming(): Response
    {
        $plugin = Plugin::getInstance();

        return $this->renderTemplate('alarm-clock/upcoming', [
            'entries' => $plugin->ticker->upcoming(100),
            'schedules' => $plugin->schedules->upcoming(100),
            'pendingCount' => $plugin->ticker->pendingCount(),
            'lastTick' => $plugin->ticker->lastTickAt(),
            'lastTickSource' => $plugin->ticker->getState(\justinholtweb\alarmclock\services\Ticker::LAST_TICK_SOURCE),
            'staleStatuses' => $plugin->ticker->staleStatusEntries(50),
            'canManage' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE),
        ]);
    }

    /** What has already happened, and how late it was. */
    public function actionHistory(): Response
    {
        $plugin = Plugin::getInstance();
        $transition = Craft::$app->getRequest()->getParam('transition');

        $transitions = $plugin->transitions->recent(200, $transition ?: null);
        $tasks = [];

        foreach ($transitions as $item) {
            $tasks[$item->id] = $plugin->tasks->forTransition((int)$item->id);
        }

        return $this->renderTemplate('alarm-clock/history', [
            'transitions' => $transitions,
            'tasksByTransition' => $tasks,
            'filter' => $transition,
            'counts' => $plugin->transitions->countsSince((new DateTime('now'))->modify('-30 days')),
            'canManage' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE),
        ]);
    }

    /** Everything that failed, with its error and a way to try again. */
    public function actionProblems(): Response
    {
        $plugin = Plugin::getInstance();
        $tasks = $plugin->tasks->problems(200);
        $transitions = [];

        foreach ($tasks as $task) {
            if ($task->transitionId !== null && !isset($transitions[$task->transitionId])) {
                $transitions[$task->transitionId] = $plugin->transitions->getById($task->transitionId);
            }
        }

        return $this->renderTemplate('alarm-clock/problems', [
            'tasks' => $tasks,
            'transitions' => $transitions,
            'failedSchedules' => $plugin->schedules->failed(50),
            'counts' => $plugin->tasks->statusCounts(),
            'canManage' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE),
        ]);
    }

    // ------------------------------------------------------------------ actions

    public function actionRetry(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $id = (int)$this->request->getRequiredBodyParam('id');
        $ok = Plugin::getInstance()->tasks->retry($id);

        // Run it straight away rather than waiting for the next tick. Somebody has just pressed a
        // button labelled "retry"; leaving them to wonder whether anything happened is how a
        // working feature gets reported as broken.
        if ($ok) {
            Plugin::getInstance()->tasks->runDue(1);
        }

        return $ok
            ? $this->asSuccess(Craft::t('alarm-clock', 'Task retried.'))
            : $this->asFailure(Craft::t('alarm-clock', 'Could not retry that task.'));
    }

    public function actionRetryAll(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $count = Plugin::getInstance()->tasks->retryAllAbandoned();
        Plugin::getInstance()->tasks->runDue(50);

        return $this->asSuccess(Craft::t('alarm-clock', '{count} tasks queued to run again.', ['count' => $count]));
    }

    /** Runs a tick by hand, for people who would like to see it work. */
    public function actionRunNow(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $result = Plugin::getInstance()->ticker->tick(TransitionRecord::SOURCE_MANUAL);

        return $this->asSuccess($result->getSummary(), ['result' => $result->toArray()]);
    }

    public function actionCancelSchedule(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_SCHEDULE);

        $id = (int)$this->request->getRequiredBodyParam('id');

        return Plugin::getInstance()->schedules->cancel($id)
            ? $this->asSuccess(Craft::t('alarm-clock', 'Schedule canceled.'))
            : $this->asFailure(Craft::t('alarm-clock', 'That schedule could not be canceled.'));
    }

    /**
     * Schedules a draft from the entry-editor sidebar.
     *
     * Posted over Ajax because the panel it comes from renders inside Craft's element-editor form,
     * and a nested `<form>` does not merely fail — the parser keeps its children and drops the
     * tag, so the hidden action ends up in the page form and Save runs this instead.
     */
    public function actionScheduleDraft(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_SCHEDULE);

        $draftId = (int)$this->request->getRequiredBodyParam('draftId');
        $siteId = (int)$this->request->getRequiredBodyParam('siteId');
        $publishAt = $this->request->getBodyParam('publishAt');
        $enableAfter = (bool)$this->request->getBodyParam('enableAfter');
        $note = $this->request->getBodyParam('note');

        $date = DateTimeHelper::toDateTime($publishAt);

        if (!$date) {
            return $this->asFailure(Craft::t('alarm-clock', 'That is not a date and time.'));
        }

        if ($date <= new DateTime('now')) {
            return $this->asFailure(Craft::t('alarm-clock', 'Pick a time in the future.'));
        }

        // Found by draft ID, which is the identifier the schedule row is itself keyed on. Taking
        // an element ID as well would mean two identifiers that have to agree, and the one that
        // is easy to post by mistake is the *canonical* entry's — which resolves to a perfectly
        // real element that is not a draft, and fails the guard below with a message that makes
        // no sense to somebody looking straight at a draft.
        $draft = Entry::find()
            ->draftId($draftId)
            ->siteId($siteId)
            ->status(null)
            ->drafts(true)
            ->provisionalDrafts(null)
            ->one();

        if (!$draft) {
            return $this->asFailure(Craft::t('alarm-clock', 'That draft no longer exists.'));
        }

        if (!Craft::$app->getElements()->canSave($draft)) {
            throw new ForbiddenHttpException('You are not allowed to edit this entry.');
        }

        $record = Plugin::getInstance()->schedules->schedule($draft, $date, $enableAfter, $note);

        return $this->asSuccess(
            Craft::t('alarm-clock', 'Draft scheduled.'),
            ['scheduleId' => $record->id, 'publishAt' => $record->publishAt],
        );
    }
}
