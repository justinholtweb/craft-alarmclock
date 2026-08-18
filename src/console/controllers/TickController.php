<?php

namespace justinholtweb\alarmclock\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\alarmclock\models\Finding;
use justinholtweb\alarmclock\Plugin;
use justinholtweb\alarmclock\records\TransitionRecord;
use justinholtweb\alarmclock\services\Ticker;
use yii\console\ExitCode;

/**
 * `craft alarm-clock/tick`
 *
 * The trigger to use if the site has real cron. Once a minute is right for most sites:
 *
 *     * * * * * cd /path/to/site && php craft alarm-clock/tick >> /dev/null 2>&1
 */
class TickController extends Controller
{
    public $defaultAction = 'index';

    /** Detect crossings but do not run any of the follow-up work. */
    public bool $detectOnly = false;

    /** Print each transition as it is recorded. */
    public bool $verbose = false;

    public function options($actionID): array
    {
        return match ($actionID) {
            'index' => array_merge(parent::options($actionID), ['detectOnly', 'verbose']),
            default => parent::options($actionID),
        };
    }

    /**
     * Looks for scheduled content that has come due, and does what it is owed.
     */
    public function actionIndex(): int
    {
        $result = Plugin::getInstance()->ticker->tick(TransitionRecord::SOURCE_CONSOLE, !$this->detectOnly);

        if (!$result->ran) {
            $this->stdout("Another tick is already running; nothing to do.\n", Console::FG_YELLOW);

            foreach ($result->notes as $note) {
                $this->stdout("  $note\n", Console::FG_YELLOW);
            }

            return ExitCode::OK;
        }

        $this->stdout($result->getSummary() . "\n", $result->tasksFailed ? Console::FG_YELLOW : Console::FG_GREEN);
        $this->stdout(sprintf("Took %.2fs.\n", $result->duration));

        if ($result->tasksReclaimed) {
            $this->stdout(
                "Reclaimed {$result->tasksReclaimed} task(s) whose runner had died.\n",
                Console::FG_YELLOW,
            );
        }

        if ($result->more) {
            $this->stdout("There is more waiting — run again to carry on catching up.\n", Console::FG_YELLOW);
        }

        // A non-zero exit only for things a person needs to act on, so `cron` mail stays quiet on
        // an ordinary run and loud on a real one.
        return $result->tasksFailed ? ExitCode::TEMPFAIL : ExitCode::OK;
    }

    /**
     * Runs waiting tasks without scanning for new crossings.
     *
     * Useful right after fixing whatever was breaking them.
     */
    public function actionWork(int $limit = 100): int
    {
        $result = Plugin::getInstance()->tasks->runDue($limit);

        $this->stdout(sprintf(
            "Ran %d task(s); %d failed; %d reclaimed.\n",
            $result['run'],
            $result['failed'],
            $result['reclaimed'],
        ), $result['failed'] ? Console::FG_YELLOW : Console::FG_GREEN);

        return $result['failed'] ? ExitCode::TEMPFAIL : ExitCode::OK;
    }

    /**
     * Retries every task that has been abandoned.
     */
    public function actionRetryFailed(): int
    {
        $count = Plugin::getInstance()->tasks->retryAllAbandoned();
        $this->stdout("Requeued $count abandoned task(s).\n", Console::FG_GREEN);

        $result = Plugin::getInstance()->tasks->runDue(max(1, $count));
        $this->stdout("Ran {$result['run']}; {$result['failed']} failed again.\n");

        return ExitCode::OK;
    }

    /**
     * Reports on whether scheduled publishing is working here.
     */
    public function actionStatus(): int
    {
        $plugin = Plugin::getInstance();
        $findings = $plugin->diagnostics->health();

        $this->stdout("\nAlarm Clock\n", Console::FG_CYAN, Console::BOLD);
        $this->stdout(str_repeat('─', 60) . "\n");

        foreach ($findings as $finding) {
            [$mark, $colour] = match ($finding->level) {
                Finding::LEVEL_OK => ['✓', Console::FG_GREEN],
                Finding::LEVEL_NOTE => ['·', Console::FG_GREY],
                Finding::LEVEL_WARNING => ['!', Console::FG_YELLOW],
                default => ['✗', Console::FG_RED],
            };

            $this->stdout("  $mark ", $colour);
            $this->stdout($finding->label . ': ', Console::BOLD);
            $this->stdout($finding->message . "\n");

            if ($finding->fix) {
                $this->stdout('      → ' . $finding->fix . "\n", Console::FG_GREY);
            }
        }

        $ticker = $plugin->ticker;
        $counts = $plugin->tasks->statusCounts();

        $this->stdout("\n");
        $this->stdout('  Pending entries: ' . $ticker->pendingCount() . "\n");
        $this->stdout('  Scheduled drafts: ' . count($plugin->schedules->upcoming(1000)) . "\n");
        $this->stdout('  Tasks: ' . implode(', ', array_map(
            fn($status, $n) => "$n $status",
            array_keys($counts),
            $counts,
        )) . "\n");
        $this->stdout('  Watermark (published): ' . ($ticker->getState(Ticker::WATERMARK_PUBLISHED) ?? 'unset') . "\n");
        $this->stdout('  Watermark (expired): ' . ($ticker->getState(Ticker::WATERMARK_EXPIRED) ?? 'unset') . "\n\n");

        return $plugin->diagnostics->hasProblems($findings) ? ExitCode::TEMPFAIL : ExitCode::OK;
    }

    /**
     * Deletes history past the retention setting, and cleans up after deleted drafts.
     */
    public function actionPrune(?int $days = null): int
    {
        $plugin = Plugin::getInstance();

        $transitions = $plugin->transitions->prune($days);
        $tasks = $plugin->tasks->prune($days);
        $orphans = $plugin->schedules->collectGarbage();

        $this->stdout(
            "Deleted $transitions transition(s), $tasks finished task(s) and $orphans orphaned schedule(s).\n",
            Console::FG_GREEN,
        );

        return ExitCode::OK;
    }
}
