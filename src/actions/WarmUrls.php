<?php

namespace justinholtweb\alarmclock\actions;

use Craft;
use craft\helpers\App;
use GuzzleHttp\Exception\GuzzleException;
use justinholtweb\alarmclock\models\Task;
use justinholtweb\alarmclock\models\Transition;
use justinholtweb\alarmclock\Plugin;
use RuntimeException;

/**
 * Requests the pages a crossing has just changed, so the first real visitor gets a warm one.
 *
 * Clearing a cache and warming it are different jobs and the second is the one people actually
 * want: an empty cache means the next visitor pays to rebuild the page, and on a heavy listing
 * that visitor is the one who notices.
 *
 * The URLs that matter are usually *not* the new post — it has never been cached — but the pages
 * that list it. Hence `extraWarmUrls`.
 */
class WarmUrls implements TaskHandlerInterface
{
    public function run(Task $task, ?Transition $transition): string
    {
        $settings = Plugin::getInstance()->getSettings();
        $urls = $this->urls($transition);

        if (!$urls) {
            return 'No URLs to warm.';
        }

        $client = Craft::createGuzzleClient([
            'timeout' => $settings->warmTimeout,
            'connect_timeout' => min(5, $settings->warmTimeout),
            'allow_redirects' => ['max' => 3],
            'headers' => [
                'User-Agent' => 'AlarmClock/' . Plugin::getInstance()->getVersion() . ' (+cache warmer)',

                // Say so plainly, so a site that wants to exclude the warmer from its analytics,
                // rate limits or bot rules has something honest to match on.
                'X-Alarm-Clock' => 'warm',
            ],
        ]);

        $warmed = [];
        $failed = [];

        foreach ($urls as $url) {
            try {
                $status = $client->get($url)->getStatusCode();

                if ($status >= 400) {
                    $failed[] = "$url ($status)";
                } else {
                    $warmed[] = $url;
                }
            } catch (GuzzleException $e) {
                $failed[] = $url . ' (' . $e->getMessage() . ')';
            }
        }

        if ($failed) {
            // Partial success is still a failure worth retrying: the whole point is that these
            // pages are warm, and "three of four" leaves a stale one in front of visitors.
            throw new RuntimeException(sprintf(
                'Warmed %d of %d URLs. Failed: %s',
                count($warmed),
                count($urls),
                implode('; ', array_slice($failed, 0, 5)),
            ));
        }

        return sprintf('Warmed %d URL%s.', count($warmed), count($warmed) === 1 ? '' : 's');
    }

    /**
     * @return string[]
     */
    private function urls(?Transition $transition): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $urls = [];

        if ($transition?->url) {
            $urls[] = $transition->url;
        }

        $site = $transition?->getSite() ?? Craft::$app->getSites()->getPrimarySite();
        $base = rtrim((string)App::parseEnv($site->getBaseUrl()), '/');

        foreach ($settings->extraWarmUrls as $url) {
            // Relative entries are resolved against the site the crossing happened in, which is
            // what a multi-site author means by "/news" — not the primary site's copy of it.
            $urls[] = str_starts_with($url, 'http') ? $url : $base . '/' . ltrim($url, '/');
        }

        return array_values(array_unique(array_filter($urls)));
    }
}
