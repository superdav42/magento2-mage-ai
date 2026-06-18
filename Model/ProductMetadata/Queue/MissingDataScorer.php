<?php
/**
 * Mageprince
 *
 * @category    Mageprince
 * @package     Mageprince_MageAI
 */

namespace Mageprince\MageAI\Model\ProductMetadata\Queue;

use Magento\Catalog\Api\Data\ProductInterface;
use Mageprince\MageAI\Helper\Data as HelperData;
use Mageprince\MageAI\Model\ProductMetadata\MetadataApplier;

class MissingDataScorer
{
    /**
     * @var HelperData
     */
    private $helper;

    /**
     * @var MetadataApplier
     */
    private $metadataApplier;

    /**
     * @param HelperData $helper
     * @param MetadataApplier $metadataApplier
     */
    public function __construct(
        HelperData $helper,
        MetadataApplier $metadataApplier
    ) {
        $this->helper = $helper;
        $this->metadataApplier = $metadataApplier;
    }

    /**
     * Score a product by configured image-analysis attributes that can still be improved.
     *
     * @param ProductInterface $product
     * @return array{score: int, fields: string[]}
     */
    public function score(ProductInterface $product): array
    {
        $score = 0;
        $fields = [];

        foreach ($this->helper->getProductImageAnalysisAttributeConfig() as $attributeCode => $config) {
            $policy = (string) $config['policy'];
            if (!$this->metadataApplier->canUpdateConfiguredAttribute($product, $attributeCode, false, $policy)) {
                continue;
            }

            $fieldScore = $this->getFieldScore($product, $attributeCode, $policy);
            if ($fieldScore <= 0) {
                continue;
            }

            $score += $fieldScore;
            $fields[] = $attributeCode;
        }

        return [
            'score' => $score,
            'fields' => $fields,
        ];
    }

    /**
     * Calculate one configured attribute's queue weight.
     *
     * @param ProductInterface $product
     * @param string $attributeCode
     * @param string $policy
     * @return int
     */
    private function getFieldScore(ProductInterface $product, string $attributeCode, string $policy): int
    {
        $baseScore = $this->getBaseScore($attributeCode);
        $hasValue = $this->hasValue($product->getData($attributeCode));

        if (!$hasValue) {
            return $baseScore;
        }

        if ($policy === HelperData::IMAGE_ANALYSIS_POLICY_PLACEHOLDER && $attributeCode === 'name') {
            return $this->isPlaceholderTitle($product) ? $baseScore : 0;
        }

        if (in_array($policy, [HelperData::IMAGE_ANALYSIS_POLICY_MERGE, HelperData::IMAGE_ANALYSIS_POLICY_MERGE_PROMOTE], true)) {
            return 1;
        }

        return $policy === HelperData::IMAGE_ANALYSIS_POLICY_REPLACE ? max(1, (int) floor($baseScore / 2)) : 0;
    }

    /**
     * Return GoodSalt-friendly defaults while supporting custom configured attributes.
     *
     * @param string $attributeCode
     * @return int
     */
    private function getBaseScore(string $attributeCode): int
    {
        $weights = [
            'name' => 5,
            'description' => 4,
            'meta_title' => 2,
            'meta_description' => 2,
            'meta_keyword' => 1,
            'keywords' => 3,
            'secondary_keywords' => 2,
            'tertiary_keywords' => 1,
        ];

        return $weights[$attributeCode] ?? 1;
    }

    /**
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
