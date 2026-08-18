<?php

namespace justinholtweb\alarmclock\actions;

use Craft;
use craft\helpers\App;
use craft\helpers\Json;
use GuzzleHttp\Exception\GuzzleException;
use justinholtweb\alarmclock\models\Task;
use justinholtweb\alarmclock\models\Transition;
use justinholtweb\alarmclock\Plugin;
use RuntimeException;

/**
 * POSTs the transition somewhere — Slack, a CDN purge endpoint, a build hook, anything.
 *
 * This is the extension point that keeps the plugin from having to grow an integration for every
 * service anybody uses. A crossing is a fact with a stable shape; anything that wants to know
 * about it can be told in one HTTP request.
 */
class Webhook implements TaskHandlerInterface
{
    public function run(Task $task, ?Transition $transition): string
    {
        $settings = Plugin::getInstance()->getSettings();
        $url = App::parseEnv($settings->webhookUrl);

        if (!$url || $transition === null) {
            return 'No webhook configured.';
        }

        $body = $settings->webhookFormat === 'slack'
            ? $this->slackBody($transition)
            : $this->jsonBody($transition);

        $encoded = Json::encode($body);
        $headers = ['Content-Type' => 'application/json'];

        $secret = App::parseEnv($settings->webhookSecret);

        if ($secret) {
            // Signed over the exact bytes sent, so the receiver can verify without having to
            // re-encode the JSON and hope its encoder agrees with PHP's about key order.
            $headers['X-AlarmClock-Signature'] = 'sha256=' . hash_hmac('sha256', $encoded, $secret);
        }

        $client = Craft::createGuzzleClient([
            'timeout' => $settings->webhookTimeout,
            'connect_timeout' => min(5, $settings->webhookTimeout),
        ]);

        try {
            $response = $client->post($url, ['headers' => $headers, 'body' => $encoded]);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Webhook request failed: ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();

        if ($status >= 300) {
            throw new RuntimeException("Webhook returned HTTP $status.");
        }

        return "Webhook returned HTTP $status.";
    }

    private function jsonBody(Transition $transition): array
    {
        return [
            'event' => 'alarmclock.' . $transition->transition,
            'sentAt' => (new \DateTime('now'))->format(DATE_ATOM),
            'transition' => $transition->toArray(),
        ];
    }

    /**
     * Slack's incoming-webhook shape.
     *
     * Worth special-casing rather than telling people to write a relay: Slack is where most teams
     * actually want this, and its endpoint rejects a bare JSON object with a 400 that reads like
     * a plugin bug.
     */
    private function slackBody(Transition $transition): array
    {
        $site = $transition->getSite();
        $verb = match ($transition->transition) {
            'published' => 'went live',
            'expired' => 'expired',
            'applied' => 'was updated from a scheduled draft',
            default => $transition->transition,
        };

        $title = $transition->title ?: ('Element ' . $transition->elementId);
        $text = sprintf('*%s* %s on %s.', $title, $verb, $site?->name ?? 'the site');

        if ($transition->url) {
            $text .= "\n" . $transition->url;
        }

        return [
            'text' => $text,
            'blocks' => [
                ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $text]],
                [
                    'type' => 'context',
                    'elements' => [[
                        'type' => 'mrkdwn',
                        'text' => sprintf(
                            'Scheduled for %s · detected %s · %s',
                            $transition->scheduledFor?->format('Y-m-d H:i') ?? '?',
                            $transition->detectedAt?->format('Y-m-d H:i') ?? '?',
                            $transition->getLatency() !== null ? $transition->getLatency() . 's late' : '',
                        ),
                    ]],
                ],
            ],
        ];
    }
}
