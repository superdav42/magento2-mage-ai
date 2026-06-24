<?php
/**
 * Mageprince
 *
 * @category    Mageprince
 * @package     Mageprince_MageAI
 */

namespace Mageprince\MageAI\Plugin\Cache;

use Mageprince\MageAI\Model\Cache\FlushSkipper;
use Magento\Framework\App\CacheInterface;

class SkipCacheInterfaceCleanPlugin
{
    /**
     * @var FlushSkipper
     */
    private $flushSkipper;

    /**
     * @param FlushSkipper $flushSkipper
     */
    public function __construct(FlushSkipper $flushSkipper)
    {
        $this->flushSkipper = $flushSkipper;
    }

    /**
     * Skip cache interface clean calls while MageAI bulk queue processing opts in.
     *
     * @param CacheInterface $subject
     * @param callable $proceed
     * @param array $tags
     * @return bool
     */
    public function aroundClean(CacheInterface $subject, callable $proceed, $tags = []): bool
    {
        if ($this->flushSkipper->isActive()) {
            return true;
        }

        return (bool) $proceed($tags);
    }
}
