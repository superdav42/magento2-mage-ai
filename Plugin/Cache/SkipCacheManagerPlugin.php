<?php
/**
 * Mageprince
 *
 * @category    Mageprince
 * @package     Mageprince_MageAI
 */

namespace Mageprince\MageAI\Plugin\Cache;

use Mageprince\MageAI\Model\Cache\FlushSkipper;
use Magento\Framework\App\Cache\Manager as CacheManager;

class SkipCacheManagerPlugin
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
     * Skip cache-manager clean calls while MageAI bulk queue processing opts in.
     *
     * @param CacheManager $subject
     * @param callable $proceed
     * @param array $types
     * @return void
     */
    public function aroundClean(CacheManager $subject, callable $proceed, array $types): void
    {
        if ($this->flushSkipper->isActive()) {
            return;
        }

        $proceed($types);
    }

    /**
     * Skip cache-manager flush calls while MageAI bulk queue processing opts in.
     *
     * @param CacheManager $subject
     * @param callable $proceed
     * @param array $types
     * @return void
     */
    public function aroundFlush(CacheManager $subject, callable $proceed, array $types): void
    {
        if ($this->flushSkipper->isActive()) {
            return;
        }

        $proceed($types);
    }
}
