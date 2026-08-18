<?php

namespace justinholtweb\alarmclock\models;

use craft\base\Model;

/**
 * One line of a diagnosis: something true about an entry or about the installation, and what it
 * means for scheduled publishing.
 *
 * Findings carry a `fix` rather than only a `message` because the question behind every one of
 * them is "so what do I do?", and a diagnostic that answers only the first half sends the reader
 * to a search engine.
 */
class Finding extends Model
{
    /** Nothing wrong; stated so the reader can see what was checked. */
    public const LEVEL_OK = 'ok';

    /** Worth knowing, not necessarily wrong. */
    public const LEVEL_NOTE = 'note';

    /** Probably why it is not working. */
    public const LEVEL_WARNING = 'warning';

    /** Certainly why it is not working. */
    public const LEVEL_BLOCKER = 'blocker';

    public string $level = self::LEVEL_NOTE;
    public string $label = '';
    public string $message = '';
    public ?string $fix = null;

    public static function ok(string $label, string $message): self
    {
        return new self(['level' => self::LEVEL_OK, 'label' => $label, 'message' => $message]);
    }

    public static function note(string $label, string $message, ?string $fix = null): self
    {
        return new self(['level' => self::LEVEL_NOTE, 'label' => $label, 'message' => $message, 'fix' => $fix]);
    }

    public static function warning(string $label, string $message, ?string $fix = null): self
    {
        return new self(['level' => self::LEVEL_WARNING, 'label' => $label, 'message' => $message, 'fix' => $fix]);
    }

    public static function blocker(string $label, string $message, ?string $fix = null): self
    {
        return new self(['level' => self::LEVEL_BLOCKER, 'label' => $label, 'message' => $message, 'fix' => $fix]);
    }

    public function isProblem(): bool
    {
        return in_array($this->level, [self::LEVEL_WARNING, self::LEVEL_BLOCKER], true);
    }
}
