<?php

namespace justinholtweb\alarmclock\services;

use Craft;
use craft\elements\User;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use justinholtweb\alarmclock\models\Task;
use justinholtweb\alarmclock\models\Transition;
use justinholtweb\alarmclock\Plugin;
use Throwable;
use yii\base\Component;

/**
 * Tells people what happened.
 */
class Notifier extends Component
{
    /**
     * @return int Recipients emailed, or -1 if the mailer refused the message.
     */
    public function sendTransitionEmail(Transition $transition): int
    {
        $recipients = $this->recipients();

        if (!$recipients) {
            return 0;
        }

        $site = $transition->getSite();
        $subject = $this->subjectFor($transition);
        $body = $this->renderBody($transition);

        $mailer = Craft::$app->getMailer();
        $sent = 0;

        foreach ($recipients as $to) {
            try {
                $message = $mailer->compose()
                    ->setTo($to)
                    ->setSubject($subject)
                    ->setHtmlBody($body)
                    ->setTextBody($this->textBody($transition));

                if ($message->send()) {
                    $sent++;
                }
            } catch (Throwable $e) {
                Craft::warning(
                    "Could not email $to about transition {$transition->id}: " . $e->getMessage(),
                    Plugin::LOG_CATEGORY,
                );
            }
        }

        // Nobody at all got it, though there were people to get it. That is a failure worth
        // retrying, and the caller turns a negative into a thrown exception.
        return $sent === 0 ? -1 : $sent;
    }

    /**
     * Tells the site's administrators that a task has given up.
     *
     * Sent directly rather than as another task, on purpose: a notification whose job is to report
     * that notifications are failing must not be able to fail the same silent way. If this one
     * cannot be sent, the log is the last line of defence and it says so plainly.
     */
    public function sendFailureEmail(Task $task, Throwable $error): void
    {
        $recipients = $this->recipients() ?: $this->fallbackRecipients();

        if (!$recipients) {
            return;
        }

        $transition = $task->transitionId !== null
            ? Plugin::getInstance()->transitions->getById($task->transitionId)
            : null;

        $what = $transition?->title ?: ('task ' . $task->id);

        $body = implode("\n", array_filter([
            sprintf('Alarm Clock gave up on “%s” after %d attempts.', $task->getLabel(), $task->attempts),
            '',
            'What it was about: ' . $what,
            $transition?->url ? 'URL: ' . $transition->url : null,
            'Last error: ' . $error->getMessage(),
            '',
            'Nothing further will be tried automatically. You can retry it from the control panel:',
            UrlHelper::cpUrl('alarm-clock/problems'),
        ]));

        foreach ($recipients as $to) {
            try {
                Craft::$app->getMailer()->compose()
                    ->setTo($to)
                    ->setSubject(sprintf('[%s] Scheduled publishing task failed', Craft::$app->getSites()->getPrimarySite()->name))
                    ->setTextBody($body)
                    ->send();
            } catch (Throwable $e) {
                Craft::error(
                    'Alarm Clock could not send its own failure notice: ' . $e->getMessage(),
                    Plugin::LOG_CATEGORY,
                );
            }
        }
    }

    /**
     * Everyone who should hear about a transition.
     *
     * @return string[]
     */
    public function recipients(): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $emails = array_map(fn(string $email) => App::parseEnv($email), $settings->notifyEmails);

        foreach ($settings->notifyUserGroups as $uid) {
            $group = Craft::$app->getUserGroups()->getGroupByUid($uid);

            if (!$group) {
                continue;
            }

            $users = User::find()->groupId($group->id)->status(User::STATUS_ACTIVE)->all();

            foreach ($users as $user) {
                $emails[] = $user->email;
            }
        }

        return array_values(array_unique(array_filter($emails, fn($e) => $e && filter_var($e, FILTER_VALIDATE_EMAIL))));
    }

    /**
     * Where a failure notice goes when nobody has configured recipients.
     *
     * The system email address, because "the plugin has stopped working" reaching nobody is the
     * worst outcome available and an unconfigured site is exactly the site it happens to.
     *
     * @return string[]
     */
    private function fallbackRecipients(): array
    {
        $from = App::parseEnv(Craft::$app->getProjectConfig()->get('email.fromEmail'));

        return $from && filter_var($from, FILTER_VALIDATE_EMAIL) ? [$from] : [];
    }

    public function subjectFor(Transition $transition): string
    {
        $settings = Plugin::getInstance()->getSettings();
        $site = $transition->getSite();

        $template = $settings->notificationSubject ?: '[{site}] {title} — {transition}';

        return strtr($template, [
            '{site}' => $site?->name ?? Craft::$app->getSites()->getPrimarySite()->name,
            '{title}' => $transition->title ?: ('Element ' . $transition->elementId),
            '{transition}' => strtolower($transition->getLabel()),
        ]);
    }

    /**
     * Renders the HTML body.
     *
     * Falls back to the built-in template if a custom one is configured but missing or broken —
     * a typo in a template path should cost you the nice formatting, not the notification.
     */
    public function renderBody(Transition $transition): string
    {
        $view = Craft::$app->getView();
        $custom = Plugin::getInstance()->getSettings()->notificationTemplate;
        $variables = [
            'transition' => $transition,
            'element' => $transition->getElement(),
            'site' => $transition->getSite(),
        ];

        if ($custom) {
            try {
                return $view->renderTemplate($custom, $variables, $view::TEMPLATE_MODE_SITE);
            } catch (Throwable $e) {
                Craft::warning(
                    "Could not render the notification template “{$custom}”: " . $e->getMessage(),
                    Plugin::LOG_CATEGORY,
                );
            }
        }

        return $view->renderTemplate('alarm-clock/_emails/transition', $variables, $view::TEMPLATE_MODE_CP);
    }

    public function textBody(Transition $transition): string
    {
        return implode("\n", array_filter([
            sprintf(
                '%s %s on %s.',
                $transition->title ?: ('Element ' . $transition->elementId),
                strtolower($transition->getLabel()),
                $transition->getSite()?->name ?? 'the site',
            ),
            $transition->url,
            '',
            'Scheduled for: ' . ($transition->scheduledFor?->format('Y-m-d H:i') ?? 'unknown'),
            'Noticed at: ' . ($transition->detectedAt?->format('Y-m-d H:i') ?? 'unknown'),
        ]));
    }
}
