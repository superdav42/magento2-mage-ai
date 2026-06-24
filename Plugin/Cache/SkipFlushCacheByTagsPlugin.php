<?php
/**
 * Mageprince
 *
 * @category    Mageprince
 * @package     Mageprince_MageAI
 */

namespace Mageprince\MageAI\Plugin\Cache;

use Mageprince\MageAI\Model\Cache\FlushSkipper;
use Magento\Framework\App\Cache\FlushCacheByTags;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\AbstractResource;

class SkipFlushCacheByTagsPlugin
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
     * Skip tag-based cache cleaning after model saves while MageAI bulk queue processing opts in.
     *
     * @param FlushCacheByTags $subject
     * @param callable $proceed
     * @param AbstractResource $resource
     * @param AbstractResource $result
     * @param AbstractModel $object
     * @return AbstractResource
     */
    public function aroundAfterSave(
        FlushCacheByTags $subject,
        callable $proceed,
        AbstractResource $resource,
        AbstractResource $result,
        AbstractModel $object
    ): AbstractResource {
        if (!$this->flushSkipper->isActive()) {
            return $proceed($resource, $result, $object);
        }

        return $result;
    }

    /**
     * Skip tag-based cache cleaning after model deletes while MageAI bulk queue processing opts in.
     *
     * @param FlushCacheByTags $subject
     * @param callable $proceed
     * @param AbstractResource $resource
     * @param AbstractResource $result
     * @param AbstractModel $object
     * @return AbstractResource
     */
    public function aroundAfterDelete(
        FlushCacheByTags $subject,
        callable $proceed,
        AbstractResource $resource,
        AbstractResource $result,
        AbstractModel $object
    ): AbstractResource {
        if (!$this->flushSkipper->isActive()) {
            return $proceed($resource, $result, $object);
        }

        return $result;
    }
}
