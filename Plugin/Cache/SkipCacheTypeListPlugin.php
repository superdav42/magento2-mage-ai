<?php
/**
 * Mageprince
 *
 * @category    Mageprince
 * @package     Mageprince_MageAI
 */

namespace Mageprince\MageAI\Plugin\Cache;

use Mageprince\MageAI\Model\Cache\FlushSkipper;
use Magento\Framework\App\Cache\TypeListInterface;

class SkipCacheTypeListPlugin
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
     * Skip cache type clean calls while MageAI bulk queue processing opts in.
     *
     * @param TypeListInterface $subject
     * @param callable $proceed
     * @param string $typeCode
     * @return void
     */
    public function aroundCleanType(TypeListInterface $subject, callable $proceed, $typeCode): void
    {
        if ($this->flushSkipper->isActive()) {
            return;
        }

        $proceed($typeCode);
    }

    /**
     * Skip cache invalidation calls while MageAI bulk queue processing opts in.
     *
     * @param TypeListInterface $subject
     * @param callable $proceed
     * @param string|array $typeCode
     * @return void
     */
    public function aroundInvalidate(TypeListInterface $subject, callable $proceed, $typeCode): void
    {
        if ($this->flushSkipper->isActive()) {
            return;
        }

        $proceed($typeCode);
    }
}
