<?php

namespace Net\EPP\Persistence;

/**
 * Which of an object's fields have been changed since it was loaded.
 *
 * The registry only accepts a change expressed against what it currently
 * holds, so both Contact and Domain have to know which fields a caller
 * touched. That used to be a bitmask, with the bit for each field spelled out
 * at every site that tested it -- including composites like `$changes & 508`,
 * which meant "any of the seven address fields" and said so nowhere.
 *
 * A set of field names needs no legend.
 *
 * @category    Net
 * @package     Net\EPP\Persistence\ChangeTracking
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
     * The changed fields, for a caller that needs to hold on to them.
     *
     * update() clears the set once the registry has accepted it, so a caller
     * that wants to persist the same change locally afterwards has to take a
     * copy first -- see Domain::updateDB()'s $changes argument.
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
