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
use Magento\Downloadable\Api\LinkRepositoryInterface;
use Magento\Downloadable\Helper\Download;
use Magento\Downloadable\Helper\File as DownloadableFile;
use Magento\Downloadable\Model\Link as DownloadableLink;
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
     * @var LinkRepositoryInterface
     */
    protected $linkRepository;

    /**
     * @var DownloadableFile
     */
    protected $downloadableFile;

    /**
     * @var DownloadableLink
     */
    protected $downloadableLink;

    /**
     * @param Filesystem $filesystem
     * @param MediaConfig $mediaConfig
     * @param LinkRepositoryInterface $linkRepository
     * @param DownloadableFile $downloadableFile
     * @param DownloadableLink $downloadableLink
     */
    public function __construct(
        Filesystem $filesystem,
        MediaConfig $mediaConfig,
        LinkRepositoryInterface $linkRepository,
        DownloadableFile $downloadableFile,
        DownloadableLink $downloadableLink
    ) {
        $this->filesystem = $filesystem;
        $this->mediaConfig = $mediaConfig;
        $this->linkRepository = $linkRepository;
        $this->downloadableFile = $downloadableFile;
        $this->downloadableLink = $downloadableLink;
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
        $imageFiles = $this->resolveProductImageFiles($product);
        if (!$imageFiles) {
            throw new QueryException(__('Product %1 has no image to analyze.', $product->getSku()));
        }

        try {
            $mediaDirectory = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
            foreach ($imageFiles as $imageFile) {
                if (!$mediaDirectory->isExist($imageFile['path'])) {
                    continue;
                }

                $data = $mediaDirectory->readFile($imageFile['path']);
                if ($data === '' || $data === false) {
                    continue;
                }

                return [
                    'data' => $data,
                    'mimeType' => $this->resolveMimeType($imageFile['file']),
                    'file' => $imageFile['file'],
                ];
            }
        } catch (QueryException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new QueryException(__('Failed to read product image for %1: %2', $product->getSku(), $e->getMessage()));
        }

        throw new QueryException(__('Product %1 has no readable image to analyze.', $product->getSku()));
    }

    /**
     * Resolve candidate media paths from catalog images, then downloadable file links.
     *
     * @param ProductInterface $product
     * @return array<int, array{file: string, path: string}>
     */
    private function resolveProductImageFiles(ProductInterface $product): array
    {
        $imageFiles = [];

        foreach (['image', 'small_image', 'thumbnail'] as $attributeCode) {
            $value = trim((string) $product->getData($attributeCode));
            if ($value !== '' && $value !== 'no_selection') {
                $this->addImageFile($imageFiles, $value, $this->mediaConfig->getMediaPath($value));
            }
        }

        foreach ((array) $product->getMediaGalleryEntries() as $entry) {
            $file = trim((string) $entry->getFile());
            if ($file !== '') {
                $this->addImageFile($imageFiles, $file, $this->mediaConfig->getMediaPath($file));
            }
        }

        foreach ($this->resolveDownloadableImageFiles($product) as $file) {
            $this->addImageFile($imageFiles, $file, $file);
        }

        return array_values($imageFiles);
    }

    /**
     * Resolve downloadable product file links as fallback source images.
     *
     * @param ProductInterface $product
     * @return string[]
     */
    private function resolveDownloadableImageFiles(ProductInterface $product): array
    {
        $files = [];

        try {
            $links = $this->linkRepository->getLinksByProduct($product);
        } catch (\Exception $e) {
            return $files;
        }

        foreach ((array) $links as $link) {
            if ((string) $link->getLinkType() !== Download::LINK_TYPE_FILE) {
                continue;
            }

            $linkFile = trim((string) $link->getLinkFile());
            if ($linkFile === '') {
                continue;
            }

            $extension = strtolower((string) pathinfo($linkFile, PATHINFO_EXTENSION));
            if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'avif'], true)) {
                continue;
            }

            $files[] = $this->downloadableFile->getFilePath($this->downloadableLink->getBasePath(), $linkFile);
        }

        return $files;
    }

    /**
     * Add a unique image file candidate.
     *
     * @param array<string, array{file: string, path: string}> $imageFiles
     * @param string $file
     * @param string $path
     * @return void
     */
    private function addImageFile(array &$imageFiles, string $file, string $path): void
    {
        if (!isset($imageFiles[$path])) {
            $imageFiles[$path] = [
                'file' => $file,
                'path' => $path,
            ];
        }
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
