<?php

namespace justinholtweb\alarmclock\models;

use craft\base\Model;

/**
 * Plugin-wide settings.
 *
 * Nothing here is `required`. A fresh install has to be able to save its settings before it has
 * been configured, and a `required` rule fails `savePluginSettings()` wholesale — taking every
 * unrelated setting down with the one that has not been filled in yet.
 */
class Settings extends Model
{
    // ---------------------------------------------------------------- detection

    /** Master switch. Off means nothing is detected and no task ever runs. */
    public bool $enabled = true;

    /**
     * Watch front-end requests for crossings, the way the WordPress plugin this borrows its idea
     * from does.
     *
     * This is the trigger that works on hosting with no cron and no queue runner, which is most
     * of the hosting a "why didn't my post publish" complaint comes from. It is throttled by
     * `tickInterval`, runs after the response has been sent, and is bounded by
     * `maxTasksPerWebTick` and `webTickBudget` so a visitor never pays for a slow webhook.
     */
    public bool $webTrigger = true;

    /** Keep a self-rescheduling queue job in flight, so the queue picks crossings up too. */
    public bool $queueTrigger = true;

    /** Minimum seconds between ticks from the web trigger. */
    public int $tickInterval = 60;

    /**
     * How far back before the watermark each scan reaches.
     *
     * Pure belt and braces: the watermark should already be exact, but a clock nudged by NTP or a
     * database and web server that disagree by a few seconds can drop a crossing into the gap.
     * Re-scanning a window costs one indexed query and the ledger throws the duplicates away.
     */
    public int $lookback = 600;

    /**
     * Most crossings to record in a single tick.
     *
     * A site restored from a backup, or switched on after a fortnight off, can have thousands of
     * post dates in its past. Recording them in bounded batches — advancing the watermark only as
     * far as the last one handled — keeps a catch-up from turning into a timeout.
     */
    public int $maxTransitionsPerTick = 250;

    /** Also treat expiry dates passing as transitions worth acting on. */
    public bool $watchExpiry = true;

    /** Section UIDs to watch. Empty watches every section. */
    public array $sections = [];

    // ---------------------------------------------------------------- caches

    /**
     * Clear the caches that mention the element, or the shape of query that would have returned
     * it, when it crosses.
     *
     * This is the whole reason the plugin exists, so it defaults on. Craft caps a `{% cache %}`
     * block's life at the soonest `getExpiryDate()` of the elements it rendered, and a pending
     * entry is not returned by any live query — so a cached listing has no idea a post is coming.
     */
    public bool $invalidateElementCaches = true;

    /**
     * Clear every cache that touched *any* element of that type, not just this one.
     *
     * Off by default because it is a much bigger hammer. Worth switching on for sites that build
     * listings with raw SQL, a search index, or anything else Craft's tag collection cannot see.
     */
    public bool $invalidateTypeCaches = false;

    /** Clear every `{% cache %}` on the site. The biggest hammer there is; almost never needed. */
    public bool $invalidateAllTemplateCaches = false;

    /** Request the element's own URL after it goes live, so the first real visitor gets a warm page. */
    public bool $warmUrls = false;

    /**
     * Extra URLs to request alongside the element's own.
     *
     * Nearly always the pages that *list* the new post rather than the post itself — the home
     * page, the section index, an RSS feed. Those are the ones a visitor notices are stale.
     */
    public array $extraWarmUrls = [];

    /** Seconds to allow each warm request. */
    public int $warmTimeout = 10;

    // ---------------------------------------------------------------- notifications

    /** Send an email when something goes live. */
    public bool $notify = false;

    /** Addresses to notify. */
    public array $notifyEmails = [];

    /** User group UIDs whose members should be notified. */
    public array $notifyUserGroups = [];

    /** Notify about expiries as well as publications. */
    public bool $notifyOnExpiry = false;

    /**
     * Notify when a task gives up.
     *
     * Separate from `notify` on purpose: plenty of sites do not want an email every time a post
     * appears, but every site wants to know when the plugin has stopped being able to do its job.
     */
    public bool $notifyOnFailure = true;

    /** Twig template to render the notification body with. Blank uses the built-in one. */
    public string $notificationTemplate = '';

    /** Subject line. `{title}`, `{site}` and `{transition}` are substituted. */
    public string $notificationSubject = '';

    // ---------------------------------------------------------------- webhook

    /** POST a JSON body to this URL on every transition. */
    public string $webhookUrl = '';

    /** `json` posts the transition as-is; `slack` wraps it in Slack's incoming-webhook shape. */
    public string $webhookFormat = 'json';

    /** Shared secret. When set, the body is signed as `sha256=<hmac>` in `X-AlarmClock-Signature`. */
    public string $webhookSecret = '';

    /** Seconds to allow the webhook request. */
    public int $webhookTimeout = 10;

    // ---------------------------------------------------------------- retries

    /** Attempts a task gets before it is abandoned. */
    public int $maxAttempts = 5;

    /** First retry delay in seconds. Doubles each attempt, up to `retryMaxDelay`. */
    public int $retryBaseDelay = 60;

    /** Ceiling on the backoff. */
    public int $retryMaxDelay = 3600;

    /**
     * Seconds after which a task still marked running is assumed dead and returned to the queue.
     *
     * A runner killed mid-task — a deploy, an OOM, a timeout — leaves its row claimed forever
     * otherwise, and the single most confusing failure to debug is work that is neither done nor
     * waiting.
     */
    public int $taskTtr = 300;

    /** Most tasks a single web-triggered tick will run. */
    public int $maxTasksPerWebTick = 3;

    /** Seconds a web-triggered tick may spend running tasks before it stops and leaves the rest. */
    public int $webTickBudget = 3;

    /** Most tasks a console or queue tick will run in one pass. */
    public int $maxTasksPerRun = 100;

    // ---------------------------------------------------------------- housekeeping

    /** Days of transition history to keep. 0 keeps everything. */
    public int $historyRetentionDays = 180;

    /** Write a line to the log for every detection and every task outcome. */
    public bool $verboseLogging = false;

    protected function defineRules(): array
    {
        return [
            [[
                'enabled', 'webTrigger', 'queueTrigger', 'watchExpiry',
                'invalidateElementCaches', 'invalidateTypeCaches', 'invalidateAllTemplateCaches',
                'warmUrls', 'notify', 'notifyOnExpiry', 'notifyOnFailure', 'verboseLogging',
            ], 'boolean'],

            [['tickInterval', 'lookback', 'warmTimeout', 'webhookTimeout', 'taskTtr'], 'integer', 'min' => 1],
            [['maxTransitionsPerTick', 'maxTasksPerRun', 'maxAttempts'], 'integer', 'min' => 1],
            [['maxTasksPerWebTick', 'webTickBudget', 'historyRetentionDays'], 'integer', 'min' => 0],
            [['retryBaseDelay', 'retryMaxDelay'], 'integer', 'min' => 1],

            [['webhookFormat'], 'in', 'range' => ['json', 'slack']],
            [['webhookUrl'], 'url', 'defaultScheme' => 'https', 'skipOnEmpty' => true],
            [['webhookSecret', 'notificationTemplate', 'notificationSubject'], 'string'],

            [['sections', 'extraWarmUrls', 'notifyEmails', 'notifyUserGroups'], 'safe'],

            [['notifyEmails'], 'validateEmails'],
            [['retryMaxDelay'], 'validateBackoffCeiling'],
        ];
    }

    /**
     * The control panel posts every list as a table of rows; `config/alarm-clock.php` posts a
     * plain list of strings. Both have to end up as the same list, and the blank trailing row the
     * table always carries is not a setting.
     */
    public function beforeValidate(): bool
    {
        foreach (['sections', 'extraWarmUrls', 'notifyEmails', 'notifyUserGroups'] as $attribute) {
            $this->$attribute = self::flattenRows($this->$attribute);
        }

        return parent::beforeValidate();
    }

    public function validateEmails(string $attribute): void
    {
        foreach ($this->$attribute as $email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->addError($attribute, "“{$email}” is not an email address.");
            }
        }
    }

    /** A ceiling below the floor would make the backoff shrink with every attempt. */
    public function validateBackoffCeiling(): void
    {
        if ($this->retryMaxDelay < $this->retryBaseDelay) {
            $this->addError('retryMaxDelay', 'The longest retry delay cannot be shorter than the first one.');
        }
    }

    /**
     * Turns whatever a list setting arrived as into a list of non-empty strings.
     *
     * @param mixed $value
     * @return string[]
     */
    public static function flattenRows(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $flattened = array_map(function($row) {
            if (is_array($row)) {
                // An editable table row is `['value' => …]`; take the first column whatever it is
                // called, so a renamed column does not silently empty the setting.
                $row = reset($row);
            }

            return trim((string)$row);
        }, $value);

        return array_values(array_filter($flattened, fn(string $entry) => $entry !== ''));
    }

    /**
     * The delay before attempt number `$attempt`, in seconds.
     *
     * Exponential, capped, and jittered. The jitter is not decoration: without it, a hundred
     * webhooks that all failed against the same downed endpoint retry in the same second and
     * knock it over again the moment it comes back.
     */
    public function backoffFor(int $attempt): int
    {
        $delay = $this->retryBaseDelay * (2 ** max(0, $attempt - 1));
        $delay = (int)min($delay, $this->retryMaxDelay);

        return $delay + random_int(0, (int)max(1, $delay * 0.1));
    }
}
