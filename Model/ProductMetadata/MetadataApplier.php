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
use Mageprince\MageAI\Helper\Data as HelperData;

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
     * @var HelperData
     */
    protected $helper;

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
     * @param HelperData $helper
     */
    public function __construct(
        ProductAttributeRepositoryInterface $attributeRepository,
        TableFactory $tableFactory,
        AttributeOptionLabelInterfaceFactory $optionLabelFactory,
        AttributeOptionInterfaceFactory $optionFactory,
        AttributeOptionManagementInterface $attributeOptionManagement,
        HelperData $helper
    ) {
        $this->attributeRepository = $attributeRepository;
        $this->tableFactory = $tableFactory;
        $this->optionLabelFactory = $optionLabelFactory;
        $this->optionFactory = $optionFactory;
        $this->attributeOptionManagement = $attributeOptionManagement;
        $this->helper = $helper;
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

        foreach ($this->helper->getProductImageAnalysisAttributes() as $attributeCode => $instruction) {
            if (!array_key_exists($attributeCode, $metadata)) {
                continue;
            }
            $this->applyAttribute($product, $attributeCode, $metadata[$attributeCode], $force, $dryRun, $changes);
        }

        return $changes;
    }

    /**
     * Build admin product form values for generated metadata without saving a product.
     *
     * Attribute option IDs are created as needed so Magento select and multiselect
     * fields can receive real option values immediately.
     *
     * @param array<string, mixed> $metadata
     * @return array{fields: array<string, mixed>, options: array<string, array<int, array{id: string|int, label: string}>>}
     */
    public function buildFormData(array $metadata): array
    {
        $fields = [];
        $options = [];

        foreach ($this->helper->getProductImageAnalysisAttributes() as $attributeCode => $instruction) {
            if (!array_key_exists($attributeCode, $metadata)) {
                continue;
            }

            $attribute = $this->getAttribute($attributeCode);
            $input = (string) $attribute->getFrontendInput();

            if ($input === 'multiselect' || $input === 'select') {
                $attributeOptions = $this->createAttributeOptions($attributeCode, $metadata[$attributeCode]);
                $options[$attributeCode] = $attributeOptions;
                $ids = array_column($attributeOptions, 'id');
                $fields[$attributeCode] = $input === 'multiselect' ? $ids : (string) reset($ids);
                continue;
            }

            $fields[$attributeCode] = $this->normalizeScalarValue($metadata[$attributeCode]);
        }

        return [
            'fields' => $fields,
            'options' => $options,
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
        foreach (array_keys($this->helper->getProductImageAnalysisAttributes()) as $attributeCode) {
            if (!$this->hasValue($product->getData($attributeCode))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Apply a generated value to one product attribute.
     *
     * @param ProductInterface $product
     * @param string $attributeCode
     * @param mixed $value
     * @param bool $force
     * @param bool $dryRun
     * @param array<string, mixed> $changes
     * @return void
     */
    private function applyAttribute(
        ProductInterface $product,
        string $attributeCode,
        $value,
        bool $force,
        bool $dryRun,
        array &$changes
    ): void {
        if (!$this->canUpdateAttribute($product, $attributeCode, $force)) {
            return;
        }

        $attribute = $this->getAttribute($attributeCode);
        $input = (string) $attribute->getFrontendInput();

        if ($input === 'multiselect' || $input === 'select') {
            if ($dryRun) {
                $labels = $this->normalizeListValue($value);
                if (!empty($labels)) {
                    $changes[$attributeCode] = $labels;
                }
                return;
            }

            $options = $this->createAttributeOptions($attributeCode, $value);
            $optionIds = array_column($options, 'id');
            if (empty($optionIds)) {
                return;
            }
            $changes[$attributeCode] = array_column($options, 'label');
            $product->setData(
                $attributeCode,
                $input === 'multiselect' ? implode(',', array_unique($optionIds)) : reset($optionIds)
            );
            return;
        }

        $normalized = $this->normalizeScalarValue($value);
        if ($normalized === '') {
            return;
        }

        $changes[$attributeCode] = $normalized;
        if (!$dryRun) {
            $product->setData($attributeCode, $normalized);
        }
    }

    /**
     * Create or resolve option records for generated option labels.
     *
     * @param string $attributeCode
     * @param mixed $values
     * @return array<int, array{id: string|int, label: string}>
     */
    private function createAttributeOptions(string $attributeCode, $values): array
    {
        $options = [];
        foreach ($this->normalizeListValue($values) as $label) {
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
     * Normalize a generated list value.
     *
     * @param mixed $values
     * @return string[]
     */
    private function normalizeListValue($values): array
    {
        if (is_string($values)) {
            $values = explode(',', $values);
        }
        if (!is_array($values)) {
            return [];
        }

        $normalized = [];
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $normalized[strtolower($value)] = $value;
            }
        }

        return array_values($normalized);
    }

    /**
     * Normalize a generated scalar value.
     *
     * @param mixed $value
     * @return string
     */
    private function normalizeScalarValue($value): string
    {
        if (is_array($value)) {
            $value = implode(', ', $this->normalizeListValue($value));
        }

        return trim((string) $value);
    }

    /**
     * Determine whether a product attribute may be updated.
     *
     * @param ProductInterface $product
     * @param string $attributeCode
     * @param bool $force
     * @return bool
     */
    private function canUpdateAttribute(ProductInterface $product, string $attributeCode, bool $force): bool
    {
        if ($force) {
            return true;
        }

        if ($attributeCode === 'name') {
            return $this->isPlaceholderTitle($product);
        }

        return !$this->hasValue($product->getData($attributeCode));
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
