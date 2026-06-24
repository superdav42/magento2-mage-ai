<?php
/**
 * Mageprince
 *
 * @category    Mageprince
 * @package     Mageprince_MageAI
 */

namespace Mageprince\MageAI\Model\Cache;

class FlushSkipper
{
    /**
     * @var int
     */
    private $depth = 0;

    /**
     * Run a callback while cache clean/flush/invalidation plugins are disabled.
     *
     * @param callable $callback
     * @return mixed
     */
    public function run(callable $callback)
    {
        $this->depth++;
        try {
            return $callback();
        } finally {
            $this->depth = max(0, $this->depth - 1);
        }
    }

    /**
     * Return whether cache clean/flush/invalidation calls should be skipped.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->depth > 0;
    }
}
