<?php
/**
 * Mageprince
 *
 * @category    Mageprince
 * @package     Mageprince_MageAI
 */
// phpcs:disable Generic.Files.LineLength

namespace Mageprince\MageAI\Console;

use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\Area;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Mageprince\MageAI\Helper\Data as HelperData;
use Mageprince\MageAI\Model\ProductMetadata\Queue\MissingDataScorer;
use Mageprince\MageAI\Model\ProductMetadata\Queue\QueueManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class QueueImageMetadataCommand extends Command
{
    private const OPTION_REBUILD = 'rebuild';
    private const OPTION_APPEND = 'append';
    private const OPTION_STATUS = 'status';
    private const OPTION_RETRY_FAILED = 'retry-failed';
    private const OPTION_RELEASE_STALE = 'release-stale';
    private const OPTION_MAX_ATTEMPTS = 'max-attempts';
    private const OPTION_PRODUCT_ID = 'product-id';
    private const OPTION_SKU = 'sku';
    private const OPTION_TYPE = 'type';
    private const OPTION_LIMIT = 'limit';
    private const OPTION_REPORT = 'report';
    private const OPTION_FORMAT = 'format';
    private const OPTION_INCLUDE_ZERO_SCORE = 'include-zero-score';
    private const DEFAULT_PAGE_SIZE = 250;

    /**
     * @var CollectionFactory
     */
    private $collectionFactory;

    /**
     * @var State
     */
    private $appState;

    /**
     * @var HelperData
     */
    private $helper;

    /**
     * @var MissingDataScorer
     */
    private $missingDataScorer;

    /**
     * @var QueueManager
     */
    private $queueManager;

    /**
     * @var DirectoryList
     */
    private $directoryList;

    /**
     * @param CollectionFactory $collectionFactory
     * @param State $appState
     * @param HelperData $helper
     * @param MissingDataScorer $missingDataScorer
     * @param QueueManager $queueManager
     * @param DirectoryList $directoryList
     */
    public function __construct(
        CollectionFactory $collectionFactory,
        State $appState,
        HelperData $helper,
        MissingDataScorer $missingDataScorer,
        QueueManager $queueManager,
        DirectoryList $directoryList
    ) {
        $this->collectionFactory = $collectionFactory;
        $this->appState = $appState;
        $this->helper = $helper;
        $this->missingDataScorer = $missingDataScorer;
        $this->queueManager = $queueManager;
        $this->directoryList = $directoryList;
        parent::__construct();
    }

    /**
     * Configure command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('mageai:queue:image-metadata');
        $this->setDescription('Audit, build, inspect, and maintain the MageAI image metadata queue without AI calls.');
        $this->addOption(self::OPTION_REBUILD, null, InputOption::VALUE_NONE, 'Clear and rebuild queue rows for matched products.');
        $this->addOption(self::OPTION_APPEND, null, InputOption::VALUE_NONE, 'Append/upsert missing or changed matched products without clearing existing queue rows.');
        $this->addOption(self::OPTION_STATUS, null, InputOption::VALUE_NONE, 'Print queue counts by status and the pending score range.');
        $this->addOption(self::OPTION_RETRY_FAILED, null, InputOption::VALUE_NONE, 'Move failed rows back to pending.');
        $this->addOption(self::OPTION_RELEASE_STALE, null, InputOption::VALUE_OPTIONAL, 'Release processing locks older than MINUTES back to pending.', false);
        $this->addOption(self::OPTION_MAX_ATTEMPTS, null, InputOption::VALUE_OPTIONAL, 'Only retry failed rows with attempts less than or equal to this value.');
        $this->addOption(self::OPTION_PRODUCT_ID, null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Product ID(s) to audit or enqueue.');
        $this->addOption(self::OPTION_SKU, null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Product SKU(s) to audit or enqueue.');
        $this->addOption(self::OPTION_TYPE, null, InputOption::VALUE_OPTIONAL, 'Product type filter; use empty string for all product types.', 'image');
        $this->addOption(self::OPTION_LIMIT, null, InputOption::VALUE_OPTIONAL, 'Maximum products to scan; use 0 for all matched products.', 100);
        $this->addOption(self::OPTION_REPORT, null, InputOption::VALUE_OPTIONAL, 'Write CSV audit report path, relative to Magento root unless absolute.');
        $this->addOption(self::OPTION_FORMAT, null, InputOption::VALUE_OPTIONAL, 'Status output format: table or json.', 'table');
        $this->addOption(self::OPTION_INCLUDE_ZERO_SCORE, null, InputOption::VALUE_NONE, 'Include zero-score products in queue/report output.');
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

        $format = strtolower((string) $input->getOption(self::OPTION_FORMAT));
        if (!in_array($format, ['table', 'json'], true)) {
            $output->writeln('<error>Invalid --format. Use table or json.</error>');
            return Command::FAILURE;
        }

        $changed = [];
        if ((bool) $input->getOption(self::OPTION_RETRY_FAILED)) {
            $maxAttempts = $input->getOption(self::OPTION_MAX_ATTEMPTS);
            $changed['retried_failed'] = $this->queueManager->retryFailed($maxAttempts === null ? null : (int) $maxAttempts);
        }

        $releaseStale = $input->getOption(self::OPTION_RELEASE_STALE);
        if ($releaseStale !== false) {
            $minutes = $releaseStale === null || $releaseStale === '' ? 60 : (int) $releaseStale;
            if ($minutes <= 0) {
                $output->writeln('<error>--release-stale minutes must be greater than zero.</error>');
                return Command::FAILURE;
            }
            $changed['released_stale'] = $this->queueManager->releaseStaleLocks($minutes * 60);
        }

        $shouldBuild = (bool) $input->getOption(self::OPTION_REBUILD) || (bool) $input->getOption(self::OPTION_APPEND) || (string) $input->getOption(self::OPTION_REPORT) !== '';
        $buildSummary = null;
        if ($shouldBuild) {
            $buildSummary = $this->buildQueueOrReport($input);
        }

        if ((bool) $input->getOption(self::OPTION_STATUS) || (!$shouldBuild && empty($changed))) {
            $this->writeStatus($output, $format, $changed, $buildSummary);
            return Command::SUCCESS;
        }

        foreach ($changed as $label => $count) {
            $output->writeln(sprintf('<info>%s: %d row(s).</info>', str_replace('_', ' ', ucfirst($label)), $count));
        }
        if ($buildSummary !== null) {
            $this->writeBuildSummary($output, $buildSummary);
        }

        return Command::SUCCESS;
    }

    /**
     * Build queue rows and/or a CSV report without calling AI services.
     *
     * @param InputInterface $input
     * @return array<string, mixed>
     */
    private function buildQueueOrReport(InputInterface $input): array
    {
        $rebuild = (bool) $input->getOption(self::OPTION_REBUILD);
        $append = (bool) $input->getOption(self::OPTION_APPEND);
        $reportPath = (string) $input->getOption(self::OPTION_REPORT);
        $includeZeroScore = (bool) $input->getOption(self::OPTION_INCLUDE_ZERO_SCORE);
        $limit = max(0, (int) $input->getOption(self::OPTION_LIMIT));
        $productIds = $this->normalizeIntArrayOption($input->getOption(self::OPTION_PRODUCT_ID));
        $skus = $this->normalizeArrayOption($input->getOption(self::OPTION_SKU));
        $type = (string) $input->getOption(self::OPTION_TYPE);

        $reportHandle = null;
        if ($reportPath !== '') {
            $absoluteReportPath = $this->getAbsolutePath($reportPath);
            $this->ensureDirectory(dirname($absoluteReportPath));
            $reportHandle = fopen($absoluteReportPath, 'w');
            if ($reportHandle === false) {
                throw new \RuntimeException(sprintf('Unable to write report: %s', $absoluteReportPath));
            }
            fputcsv($reportHandle, ['product_id', 'sku', 'type', 'missing_score', 'missing_fields', 'queue_status']);
        }

        if ($rebuild) {
            $matchedProductIds = $this->getMatchedProductIds($productIds, $skus, $type, $limit);
            if (!empty($matchedProductIds)) {
                $this->queueManager->clear($matchedProductIds);
            }
        }

        $summary = [
            'scanned' => 0,
            'queued' => 0,
            'reported' => 0,
            'skipped_zero_score' => 0,
            'report' => $reportPath,
        ];

        $pageSize = $this->getPageSize($limit);
        $collection = $this->createCollection($productIds, $skus, $type, $limit);
        $lastPage = $limit > 0 ? (int) ceil($limit / $pageSize) : (int) $collection->getLastPageNumber();
        for ($page = 1; $page <= $lastPage; $page++) {
            $collection = $this->createCollection($productIds, $skus, $type, $limit);
            $collection->setPageSize($pageSize);
            $collection->setCurPage($page);
            $collection->load();

            $pageProductIds = [];
            foreach ($collection as $product) {
                $pageProductIds[] = (int) $product->getId();
            }
            $statuses = $this->queueManager->getStatusesByProductIds($pageProductIds);
            $rows = [];

            foreach ($collection as $product) {
                if ($limit > 0 && $summary['scanned'] >= $limit) {
                    break;
                }
                $productId = (int) $product->getId();
                $score = $this->missingDataScorer->score($product);
                $missingScore = (int) $score['score'];
                $missingFields = $score['fields'];
                $summary['scanned']++;

                if ($missingScore <= 0 && !$includeZeroScore) {
                    $summary['skipped_zero_score']++;
                    continue;
                }

                if ($reportHandle !== null) {
                    fputcsv($reportHandle, [
                        $productId,
                        (string) $product->getSku(),
                        (string) $product->getTypeId(),
                        $missingScore,
                        implode('|', $missingFields),
                        $statuses[$productId] ?? '',
                    ]);
                    $summary['reported']++;
                }

                if ($rebuild || $append) {
                    if (($statuses[$productId] ?? null) === QueueManager::STATUS_PROCESSING) {
                        continue;
                    }

                    $rows[] = [
                        'product_id' => $productId,
                        'sku' => (string) $product->getSku(),
                        'product_type' => (string) $product->getTypeId(),
                        'missing_score' => $missingScore,
                        'missing_fields' => $missingFields,
                    ];
                }
            }

            if (!empty($rows)) {
                usort($rows, function (array $left, array $right): int {
                    if ($left['missing_score'] !== $right['missing_score']) {
                        return $right['missing_score'] <=> $left['missing_score'];
                    }
                    return $left['product_id'] <=> $right['product_id'];
                });
                $this->queueManager->enqueueRows($rows);
                $summary['queued'] += count($rows);
            }
            $collection->clear();
        }

        if ($reportHandle !== null) {
            fclose($reportHandle);
        }

        return $summary;
    }

    /**
     * Create a product collection selecting only fields needed for scoring and reporting.
     *
     * @param int[] $productIds
     * @param string[] $skus
     * @param string $type
     * @param int $limit
     * @return Collection
     */
    private function createCollection(array $productIds, array $skus, string $type, int $limit): Collection
    {
        $collection = $this->collectionFactory->create();
        $collection->addAttributeToSelect($this->getAttributesToSelect());
        if (!empty($productIds)) {
            $collection->addFieldToFilter('entity_id', ['in' => $productIds]);
        }
        if (!empty($skus)) {
            $collection->addAttributeToFilter('sku', ['in' => $skus]);
        }
        if ($type !== '') {
            $collection->addFieldToFilter('type_id', $type);
        }
        $collection->setOrder('entity_id', 'ASC');
        $collection->setPageSize($this->getPageSize($limit));

        return $collection;
    }

    /**
     * @return string[]
     */
    private function getAttributesToSelect(): array
    {
        return array_values(array_unique(array_merge(
            ['name', 'image', 'small_image', 'thumbnail'],
            array_keys($this->helper->getProductImageAnalysisAttributeConfig())
        )));
    }

    /**
     * @param int[] $productIds
     * @param string[] $skus
     * @param string $type
     * @param int $limit
     * @return int[]
     */
    private function getMatchedProductIds(array $productIds, array $skus, string $type, int $limit): array
    {
        $matchedProductIds = [];
        $pageSize = $this->getPageSize($limit);
        $collection = $this->createCollection($productIds, $skus, $type, $limit);
        $lastPage = $limit > 0 ? (int) ceil($limit / $pageSize) : (int) $collection->getLastPageNumber();

        for ($page = 1; $page <= $lastPage; $page++) {
            $collection = $this->createCollection($productIds, $skus, $type, $limit);
            $collection->setPageSize($pageSize);
            $collection->setCurPage($page);
            $collection->load();
            foreach ($collection->getColumnValues('entity_id') as $productId) {
                if ($limit > 0 && count($matchedProductIds) >= $limit) {
                    break 2;
                }
                $matchedProductIds[] = (int) $productId;
            }
            $collection->clear();
        }

        return $matchedProductIds;
    }

    /**
     * @param OutputInterface $output
     * @param string $format
     * @param array<string, int> $changed
     * @param array<string, mixed>|null $buildSummary
     * @return void
     */
    private function writeStatus(OutputInterface $output, string $format, array $changed = [], ?array $buildSummary = null): void
    {
        $counts = $this->queueManager->getStatusCounts();
        $scoreRange = $this->queueManager->getPendingScoreRange();
        if ($format === 'json') {
            $output->writeln(json_encode([
                'counts' => $counts,
                'pending_score_range' => $scoreRange,
                'changed' => $changed,
                'build' => $buildSummary,
            ], JSON_PRETTY_PRINT));
            return;
        }

        $table = new Table($output);
        $table->setHeaders(['Status', 'Count']);
        foreach ([QueueManager::STATUS_PENDING, QueueManager::STATUS_PROCESSING, QueueManager::STATUS_DONE, QueueManager::STATUS_FAILED, QueueManager::STATUS_SKIPPED] as $status) {
            $table->addRow([$status, $counts[$status] ?? 0]);
        }
        $table->render();

        $range = $scoreRange['max'] === null ? 'none' : sprintf('%d-%d', $scoreRange['min'], $scoreRange['max']);
        $output->writeln(sprintf('<info>Pending score range: %s</info>', $range));
        foreach ($changed as $label => $count) {
            $output->writeln(sprintf('<info>%s: %d row(s).</info>', str_replace('_', ' ', ucfirst($label)), $count));
        }
        if ($buildSummary !== null) {
            $this->writeBuildSummary($output, $buildSummary);
        }
    }

    /**
     * @param OutputInterface $output
     * @param array<string, mixed> $summary
     * @return void
     */
    private function writeBuildSummary(OutputInterface $output, array $summary): void
    {
        $output->writeln(sprintf(
            '<info>Queue audit complete. Scanned: %d, queued: %d, reported: %d, zero-score skipped: %d.</info>',
            $summary['scanned'],
            $summary['queued'],
            $summary['reported'],
            $summary['skipped_zero_score']
        ));
        if (!empty($summary['report'])) {
            $output->writeln(sprintf('<info>Report: %s</info>', $summary['report']));
        }
    }

    /**
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

    /**
     * @param mixed $value
     * @return int[]
     */
    private function normalizeIntArrayOption($value): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $this->normalizeArrayOption($value)))));
    }

    /**
     * @param string $path
     * @return string
     */
    private function getAbsolutePath(string $path): string
    {
        if ($path !== '' && $path[0] === '/') {
            return $path;
        }

        return rtrim($this->directoryList->getRoot(), '/') . '/' . ltrim($path, '/');
    }

    /**
     * @param string $directory
     * @return void
     */
    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create report directory: %s', $directory));
        }
    }

    /**
     * @param int $limit
     * @return int
     */
    private function getPageSize(int $limit): int
    {
        if ($limit > 0) {
            return min($limit, self::DEFAULT_PAGE_SIZE);
        }

        return self::DEFAULT_PAGE_SIZE;
    }

    /**
     * Set adminhtml area for product collection and EAV operations.
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
}
