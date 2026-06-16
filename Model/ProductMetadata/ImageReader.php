<?php
/**
 * Mageprince
 *
 * @category    Mageprince
 * @package     Mageprince_MageAI
 */
// phpcs:disable Generic.Files.LineLength

namespace Mageprince\MageAI\Model\ProductMetadata;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Mageprince\MageAI\Model\Query\QueryException;

class ImageReader
{
    /**
     * @var Filesystem
     */
    protected $filesystem;

    /**
     * @var MediaConfig
     */
    protected $mediaConfig;

    /**
     * @param Filesystem $filesystem
     * @param MediaConfig $mediaConfig
     */
    public function __construct(Filesystem $filesystem, MediaConfig $mediaConfig)
    {
        $this->filesystem = $filesystem;
        $this->mediaConfig = $mediaConfig;
    }

    /**
     * Read the best available catalog product image for AI analysis.
     *
     * @param ProductInterface $product
     * @return array{data: string, mimeType: string, file: string}
     * @throws QueryException
     */
    public function read(ProductInterface $product): array
    {
        $file = $this->resolveProductImageFile($product);
        if ($file === '') {
            throw new QueryException(__('Product %1 has no image to analyze.', $product->getSku()));
        }

        $mediaPath = $this->mediaConfig->getMediaPath($file);

        try {
            $mediaDirectory = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
            if (!$mediaDirectory->isExist($mediaPath)) {
                throw new QueryException(__('Product image %1 could not be found on the server.', $file));
            }
            $data = $mediaDirectory->readFile($mediaPath);
        } catch (QueryException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new QueryException(__('Failed to read product image %1: %2', $file, $e->getMessage()));
        }

        if ($data === '' || $data === false) {
            throw new QueryException(__('Product image %1 is empty or unreadable.', $file));
        }

        return [
            'data' => $data,
            'mimeType' => $this->resolveMimeType($file),
            'file' => $file,
        ];
    }

    /**
     * Resolve product image from image/small_image/thumbnail, then media gallery entries.
     *
     * @param ProductInterface $product
     * @return string
     */
    private function resolveProductImageFile(ProductInterface $product): string
    {
        foreach (['image', 'small_image', 'thumbnail'] as $attributeCode) {
            $value = trim((string) $product->getData($attributeCode));
            if ($value !== '' && $value !== 'no_selection') {
                return $value;
            }
        }

        foreach ((array) $product->getMediaGalleryEntries() as $entry) {
            $file = trim((string) $entry->getFile());
            if ($file !== '') {
                return $file;
            }
        }

        return '';
    }

    /**
     * Resolve mime type from filename extension.
     *
     * @param string $file
     * @return string
     */
    private function resolveMimeType(string $file): string
    {
        $ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
        switch ($ext) {
            case 'png':
                return 'image/png';
            case 'webp':
                return 'image/webp';
            case 'gif':
                return 'image/gif';
            default:
                return 'image/jpeg';
        }
    }
}
