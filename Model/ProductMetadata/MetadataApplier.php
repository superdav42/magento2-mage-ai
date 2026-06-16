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
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Eav\Api\AttributeOptionManagementInterface;
use Magento\Eav\Api\Data\AttributeOptionInterfaceFactory;
use Magento\Eav\Api\Data\AttributeOptionLabelInterfaceFactory;
use Magento\Eav\Model\Entity\Attribute\OptionLabel;
use Magento\Eav\Model\Entity\Attribute\Source\TableFactory;
use Magento\Framework\Exception\InputException;

class MetadataApplier
{
    /**
     * @var ProductAttributeRepositoryInterface
     */
    protected $attributeRepository;

    /**
     * @var TableFactory
     */
    protected $tableFactory;

    /**
     * @var AttributeOptionLabelInterfaceFactory
     */
    protected $optionLabelFactory;

    /**
     * @var AttributeOptionInterfaceFactory
     */
    protected $optionFactory;

    /**
     * @var AttributeOptionManagementInterface
     */
    protected $attributeOptionManagement;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [];

    /**
     * @var array<int|string, array<string, string|int>>
     */
    protected $attributeValues = [];

    /**
     * @param ProductAttributeRepositoryInterface $attributeRepository
     * @param TableFactory $tableFactory
     * @param AttributeOptionLabelInterfaceFactory $optionLabelFactory
     * @param AttributeOptionInterfaceFactory $optionFactory
     * @param AttributeOptionManagementInterface $attributeOptionManagement
     */
    public function __construct(
        ProductAttributeRepositoryInterface $attributeRepository,
        TableFactory $tableFactory,
        AttributeOptionLabelInterfaceFactory $optionLabelFactory,
        AttributeOptionInterfaceFactory $optionFactory,
        AttributeOptionManagementInterface $attributeOptionManagement
    ) {
        $this->attributeRepository = $attributeRepository;
        $this->tableFactory = $tableFactory;
        $this->optionLabelFactory = $optionLabelFactory;
        $this->optionFactory = $optionFactory;
        $this->attributeOptionManagement = $attributeOptionManagement;
    }

    /**
     * Apply generated metadata to the product and return a summary of fields changed.
     *
     * @param ProductInterface $product
     * @param array<string, mixed> $metadata
     * @param bool $force
     * @param bool $dryRun
     * @return array<string, mixed>
     */
    public function apply(ProductInterface $product, array $metadata, bool $force = false, bool $dryRun = false): array
    {
        $changes = [];

        $title = trim((string) ($metadata['title'] ?? ''));
        if ($title !== '' && ($force || $this->isPlaceholderTitle($product))) {
            $changes['name'] = $title;
            if (!$dryRun) {
                $product->setName($title);
            }
        }

        $description = trim((string) ($metadata['description'] ?? ''));
        if ($description !== '' && ($force || !$this->hasValue($product->getDescription()))) {
            $changes['description'] = $description;
            if (!$dryRun) {
                $product->setDescription($description);
            }
        }

        $this->applyKeywordTier($product, 'keywords', $metadata['primary_keywords'] ?? [], $force, $dryRun, $changes);
        $this->applyKeywordTier($product, 'secondary_keywords', $metadata['secondary_keywords'] ?? [], $force, $dryRun, $changes);
        $this->applyKeywordTier($product, 'tertiary_keywords', $metadata['tertiary_keywords'] ?? [], $force, $dryRun, $changes);

        return $changes;
    }

    /**
     * Build admin product form values for generated metadata without saving a product.
     *
     * Keyword option IDs are created as needed so Magento's multiselect fields can
     * receive real option values immediately.
     *
     * @param array<string, mixed> $metadata
     * @return array{title: string, description: string, keywords: array<string, array<int, string|int>>, keyword_options: array<string, array<int, array{id: string|int, label: string}>>}
     */
    public function buildFormData(array $metadata): array
    {
        $primaryKeywords = $this->createKeywordOptions('keywords', $metadata['primary_keywords'] ?? []);
        $secondaryKeywords = $this->createKeywordOptions('secondary_keywords', $metadata['secondary_keywords'] ?? []);
        $tertiaryKeywords = $this->createKeywordOptions('tertiary_keywords', $metadata['tertiary_keywords'] ?? []);

        return [
            'title' => trim((string) ($metadata['title'] ?? '')),
            'description' => trim((string) ($metadata['description'] ?? '')),
            'keywords' => [
                'keywords' => array_column($primaryKeywords, 'id'),
                'secondary_keywords' => array_column($secondaryKeywords, 'id'),
                'tertiary_keywords' => array_column($tertiaryKeywords, 'id'),
            ],
            'keyword_options' => [
                'keywords' => $primaryKeywords,
                'secondary_keywords' => $secondaryKeywords,
                'tertiary_keywords' => $tertiaryKeywords,
            ],
        ];
    }

    /**
     * Determine whether all target metadata fields are already populated.
     *
     * @param ProductInterface $product
     * @return bool
     */
    public function hasGeneratedMetadata(ProductInterface $product): bool
    {
        return $this->hasValue($product->getDescription())
            && $this->hasValue($product->getData('keywords'))
            && $this->hasValue($product->getData('secondary_keywords'))
            && $this->hasValue($product->getData('tertiary_keywords'));
    }

    /**
     * Apply a keyword list to a multiselect product attribute.
     *
     * @param ProductInterface $product
     * @param string $attributeCode
     * @param mixed $keywords
     * @param bool $force
     * @param bool $dryRun
     * @param array<string, mixed> $changes
     * @return void
     */
    private function applyKeywordTier(
        ProductInterface $product,
        string $attributeCode,
        $keywords,
        bool $force,
        bool $dryRun,
        array &$changes
    ): void {
        $labels = $this->normalizeKeywordLabels($keywords);
        if (empty($labels) || (!$force && $this->hasValue($product->getData($attributeCode)))) {
            return;
        }

        $changes[$attributeCode] = $labels;
        if ($dryRun) {
            return;
        }

        $optionIds = $this->createKeywordOptionIds($attributeCode, $labels);

        $product->setData($attributeCode, implode(',', array_unique(array_filter($optionIds))));
    }

    /**
     * Create or resolve option IDs for keyword labels.
     *
     * @param string $attributeCode
     * @param mixed $keywords
     * @return array<int, string|int>
     */
    private function createKeywordOptionIds(string $attributeCode, $keywords): array
    {
        return array_column($this->createKeywordOptions($attributeCode, $keywords), 'id');
    }

    /**
     * Create or resolve option records for keyword labels.
     *
     * @param string $attributeCode
     * @param mixed $keywords
     * @return array<int, array{id: string|int, label: string}>
     */
    private function createKeywordOptions(string $attributeCode, $keywords): array
    {
        $options = [];
        foreach ($this->normalizeKeywordLabels($keywords) as $label) {
            $optionId = $this->createOrGetOptionId($attributeCode, $label);
            if ($optionId) {
                $options[(string) $optionId] = ['id' => $optionId, 'label' => $label];
            }
        }

        return array_values($options);
    }

    /**
     * Create or resolve a keyword option ID.
     *
     * @param string $attributeCode
     * @param string $label
     * @return string|int|false
     */
    private function createOrGetOptionId(string $attributeCode, string $label)
    {
        $optionId = $this->getOptionId($attributeCode, $label);
        if ($optionId) {
            return $optionId;
        }

        /** @var OptionLabel $optionLabel */
        $optionLabel = $this->optionLabelFactory->create();
        $optionLabel->setStoreId(0);
        $optionLabel->setLabel($label);

        $option = $this->optionFactory->create();
        $option->setLabel($label);
        $option->setStoreLabels([$optionLabel]);
        $option->setSortOrder(0);
        $option->setIsDefault(false);

        try {
            $optionId = $this->attributeOptionManagement->add(
                Product::ENTITY,
                $this->getAttribute($attributeCode)->getAttributeId(),
                $option
            );
        } catch (InputException $e) {
            $optionId = $this->getOptionId($attributeCode, $label, true);
        }

        return $optionId ?: $this->getOptionId($attributeCode, $label, true);
    }

    /**
     * Resolve an existing option ID by label.
     *
     * @param string $attributeCode
     * @param string $label
     * @param bool $force
     * @return string|int|false
     */
    private function getOptionId(string $attributeCode, string $label, bool $force = false)
    {
        $attribute = $this->getAttribute($attributeCode);
        $attributeId = $attribute->getAttributeId();

        if ($force || !isset($this->attributeValues[$attributeId])) {
            $this->attributeValues[$attributeId] = [];
            $sourceModel = $this->tableFactory->create();
            $sourceModel->setAttribute($attribute);

            foreach ($sourceModel->getAllOptions(true, false) as $option) {
                $this->attributeValues[$attributeId][strtolower((string) $option['label'])] = $option['value'];
            }
        }

        return $this->attributeValues[$attributeId][strtolower($label)] ?? false;
    }

    /**
     * Get product attribute metadata.
     *
     * @param string $attributeCode
     * @return mixed
     */
    private function getAttribute(string $attributeCode)
    {
        if (!isset($this->attributes[$attributeCode])) {
            $this->attributes[$attributeCode] = $this->attributeRepository->get($attributeCode);
        }

        return $this->attributes[$attributeCode];
    }

    /**
     * Normalize keyword labels.
     *
     * @param mixed $keywords
     * @return string[]
     */
    private function normalizeKeywordLabels($keywords): array
    {
        if (is_string($keywords)) {
            $keywords = explode(',', $keywords);
        }
        if (!is_array($keywords)) {
            return [];
        }

        $normalized = [];
        foreach ($keywords as $keyword) {
            $keyword = trim((string) $keyword);
            if ($keyword !== '') {
                $normalized[strtolower($keyword)] = $keyword;
            }
        }

        return array_values($normalized);
    }

    /**
     * Check whether a value contains useful data.
     *
     * @param mixed $value
     * @return bool
     */
    private function hasValue($value): bool
    {
        if (is_array($value)) {
            return !empty(array_filter($value));
        }

        return trim((string) $value) !== '';
    }

    /**
     * Product import often uses the SKU as a temporary title; allow replacing it.
     *
     * @param ProductInterface $product
     * @return bool
     */
    private function isPlaceholderTitle(ProductInterface $product): bool
    {
        $name = trim((string) $product->getName());
        $sku = trim((string) $product->getSku());

        return $name === '' || ($sku !== '' && strcasecmp($name, $sku) === 0);
    }
}
