<?php
/**
 * Mageprince
 *
 * @category    Mageprince
 * @package     Mageprince_MageAI
 */

namespace Mageprince\MageAI\Plugin\Cache;

use Mageprince\MageAI\Model\Cache\FlushSkipper;
use Magento\Framework\App\Cache\Type\AccessProxy;

class SkipCacheAccessProxyCleanPlugin
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
     * Skip cache frontend clean calls while MageAI bulk queue processing opts in.
     *
     * @param AccessProxy $subject
     * @param callable $proceed
     * @param string $mode
     * @param array $tags
     * @return bool
     */
    public function aroundClean(AccessProxy $subject, callable $proceed, $mode = \Zend_Cache::CLEANING_MODE_ALL, array $tags = []): bool
    {
        if ($this->flushSkipper->isActive()) {
            return true;
        }

        return (bool) $proceed($mode, $tags);
    }
}
