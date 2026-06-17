<?php
/**
 * Mageprince
 *
 * @category    Mageprince
 * @package     Mageprince_MageAI
 */
// phpcs:disable Generic.Files.LineLength

namespace Mageprince\MageAI\Console;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Mageprince\MageAI\Helper\Data as HelperData;
use Mageprince\MageAI\Model\ProductMetadata\ImageAnalyzer;
use Mageprince\MageAI\Model\ProductMetadata\MetadataApplier;
use Mageprince\MageAI\Model\Query\QueryException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateImageMetadataCommand extends Command
{
    private const OPTION_PRODUCT_ID = 'product-id';
    private const OPTION_SKU = 'sku';
    private const OPTION_LIMIT = 'limit';
    private const OPTION_FORCE = 'force';
    private const OPTION_DRY_RUN = 'dry-run';
    private const OPTION_TYPE = 'type';

    /**
     * @var CollectionFactory
     */
    protected $collectionFactory;

    /**
     * @var ProductRepositoryInterface
     */
    protected $productRepository;

    /**
     * @var State
     */
    protected $appState;

    /**
     * @var HelperData
     */
    protected $helper;

    /**
     * @var ImageAnalyzer
     */
    protected $imageAnalyzer;

    /**
     * @var MetadataApplier
     */
    protected $metadataApplier;

    /**
     * @param CollectionFactory $collectionFactory
     * @param ProductRepositoryInterface $productRepository
     * @param State $appState
     * @param HelperData $helper
     * @param ImageAnalyzer $imageAnalyzer
     * @param MetadataApplier $metadataApplier
     */
    public function __construct(
        CollectionFactory $collectionFactory,
        ProductRepositoryInterface $productRepository,
        State $appState,
        HelperData $helper,
        ImageAnalyzer $imageAnalyzer,
        MetadataApplier $metadataApplier
    ) {
        $this->collectionFactory = $collectionFactory;
        $this->productRepository = $productRepository;
        $this->appState = $appState;
        $this->helper = $helper;
        $this->imageAnalyzer = $imageAnalyzer;
        $this->metadataApplier = $metadataApplier;
        parent::__construct();
    }

    /**
     * Configure command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('mageai:generate:image-metadata');
        $this->setDescription('Generate configured product attributes from product images using an OpenAI-compatible endpoint.');
        $this->addOption(self::OPTION_PRODUCT_ID, null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Product ID(s) to process.');
        $this->addOption(self::OPTION_SKU, null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Product SKU(s) to process.');
        $this->addOption(self::OPTION_LIMIT, null, InputOption::VALUE_OPTIONAL, 'Maximum products to process when no product-id/sku filter is supplied.', 50);
        $this->addOption(self::OPTION_TYPE, null, InputOption::VALUE_OPTIONAL, 'Product type to process when no product-id/sku filter is supplied. Use empty string for all types.', 'image');
        $this->addOption(self::OPTION_FORCE, 'f', InputOption::VALUE_NONE, 'Overwrite existing configured image-analysis attributes.');
        $this->addOption(self::OPTION_DRY_RUN, null, InputOption::VALUE_NONE, 'Analyze and report changes without saving products or creating attribute options.');
    }

    /**
     * Execute command.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->setAreaCode();

        if (!$this->helper->isEnabled()) {
            $output->writeln('<error>MageAI is disabled in configuration.</error>');
            return Command::FAILURE;
        }

        $force = (bool) $input->getOption(self::OPTION_FORCE);
        $dryRun = (bool) $input->getOption(self::OPTION_DRY_RUN);
        $productIds = $this->normalizeArrayOption($input->getOption(self::OPTION_PRODUCT_ID));
        $skus = $this->normalizeArrayOption($input->getOption(self::OPTION_SKU));
        $ids = $this->getProductIds($productIds, $skus, (string) $input->getOption(self::OPTION_TYPE), (int) $input->getOption(self::OPTION_LIMIT));

        if (empty($ids)) {
            $output->writeln('<comment>No products matched the requested filters.</comment>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<info>Processing %d product(s)%s.</info>', count($ids), $dryRun ? ' (dry run)' : ''));

        $processed = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($ids as $productId) {
            $processed++;
            try {
                $product = $this->productRepository->getById((int) $productId, false, 0, true);

                if (!$force && $this->metadataApplier->hasGeneratedMetadata($product)) {
                    $skipped++;
                    $output->writeln(sprintf('[%d/%d] Skipped %s: metadata already populated.', $processed, count($ids), $product->getSku()));
                    continue;
                }

                $metadata = $this->imageAnalyzer->analyze($product);
                $changes = $this->metadataApplier->apply($product, $metadata, $force, $dryRun);

                if (empty($changes)) {
                    $skipped++;
                    $output->writeln(sprintf('[%d/%d] Skipped %s: no applicable changes.', $processed, count($ids), $product->getSku()));
                    continue;
                }

                if (!$dryRun) {
                    $product->setStoreId(0);
                    $this->productRepository->save($product);
                }

                $updated++;
                $output->writeln(sprintf('[%d/%d] %s %s: %s', $processed, count($ids), $dryRun ? 'Would update' : 'Updated', $product->getSku(), implode(', ', array_keys($changes))));
            } catch (QueryException $e) {
                $failed++;
                $output->writeln(sprintf('[%d/%d] <error>Failed product ID %s: %s</error>', $processed, count($ids), $productId, $e->getMessage()));
            } catch (\Exception $e) {
                $failed++;
                $output->writeln(sprintf('[%d/%d] <error>Failed product ID %s: %s</error>', $processed, count($ids), $productId, $e->getMessage()));
            }
        }

        $output->writeln(sprintf('<info>Done. Processed: %d, updated: %d, skipped: %d, failed: %d.</info>', $processed, $updated, $skipped, $failed));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Set adminhtml area for product repository and EAV operations.
     *
     * @return void
     */
    private function setAreaCode(): void
    {
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (LocalizedException $e) {
            return;
        }
    }

    /**
     * Build product ID list from CLI filters.
     *
     * @param string[] $productIds
     * @param string[] $skus
     * @param string $type
     * @param int $limit
     * @return int[]
     */
    private function getProductIds(array $productIds, array $skus, string $type, int $limit): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addAttributeToSelect(array_unique(array_merge(
            ['name', 'image', 'small_image', 'thumbnail'],
            array_keys($this->helper->getProductImageAnalysisAttributes())
        )));

        if (!empty($productIds)) {
            $collection->addFieldToFilter('entity_id', ['in' => $productIds]);
        }
        if (!empty($skus)) {
            $collection->addAttributeToFilter('sku', ['in' => $skus]);
        }
        if (empty($productIds) && empty($skus)) {
            if ($type !== '') {
                $collection->addFieldToFilter('type_id', $type);
            }
            $collection->setPageSize(max(1, $limit));
            $collection->setCurPage(1);
        }

        $collection->load();
        return array_map('intval', $collection->getColumnValues('entity_id'));
    }

    /**
     * Normalize repeatable option values.
     *
     * @param mixed $value
     * @return string[]
     */
    private function normalizeArrayOption($value): array
    {
        if (!is_array($value)) {
            $value = [$value];
        }

        $items = [];
        foreach ($value as $item) {
            foreach (explode(',', (string) $item) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $items[] = $part;
                }
            }
        }

        return array_values(array_unique($items));
    }
}
