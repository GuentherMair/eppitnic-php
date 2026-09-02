<?php

namespace Eppitnic\Persistence;

/**
 * Which of an object's fields changed since it was loaded -- the registry only
 * accepts a change expressed against what it holds. Previously a bitmask, where
 * `$changes & 508` meant "any address field" and said so nowhere.
 *
 * @category    Net
 * @package     Eppitnic\Persistence\ChangeTracking
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
trait ChangeTracking
{
    /** @var array<string, true> field name => changed */
    protected array $changes = [];

    /**
     * Record that a field was changed.
     */
    protected function markChanged(string $field): void {
        $this->changes[$field] = true;
    }

    /**
     * @return bool whether any of the named fields changed
     */
    protected function changed(string ...$fields): bool {
        foreach ($fields as $field) {
            if (isset($this->changes[$field])) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return bool whether anything at all changed
     */
    public function hasChanges(): bool {
        return $this->changes !== [];
    }

    /**
     * The changed fields, for a caller that must hold on to them: update()
     * clears the set once the registry accepts it, so persisting the same
     * change locally afterwards needs a copy taken first.
     *
     * @return string[]
     */
    public function changedFields(): array {
        return array_keys($this->changes);
    }

    protected function clearChanges(): void {
        $this->changes = [];
    }
}
