<?php
/**
 * Mageprince
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the mageprince.com license that is
 * available through the world-wide-web at this URL:
 * https://mageprince.com/end-user-license-agreement
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to newer
 * version in the future.
 *
 * @category    Mageprince
 * @package     Mageprince_MageAI
 * @copyright   Copyright (c) Mageprince (https://mageprince.com/)
 * @license     https://mageprince.com/end-user-license-agreement
 */

namespace Mageprince\MageAI\Helper;

use Magento\Framework\App\Helper\AbstractHelper;

class Data extends AbstractHelper
{
    public const XML_PATH_IS_ENABLED = 'mageai/general/enabled';
    public const XML_PATH_BASELINE_PROMPT = 'mageai/general/baseline_prompt';
    public const XML_PATH_PROVIDER = 'mageai/api/provider';
    public const XML_PATH_API_BASE_URL = 'mageai/api/base_url';
    public const XML_PATH_API_KEY = 'mageai/api/api_secret';
    public const XML_PATH_API_MODEL = 'mageai/api/model';
    public const XML_PATH_API_CUSTOM_MODEL = 'mageai/api/custom_model';
    public const XML_PATH_ANTHROPIC_BASE_URL = 'mageai/api/anthropic_base_url';
    public const XML_PATH_ANTHROPIC_API_KEY = 'mageai/api/anthropic_api_secret';
    public const XML_PATH_ANTHROPIC_MODEL = 'mageai/api/anthropic_model';
    public const XML_PATH_GEMINI_BASE_URL = 'mageai/api/gemini_base_url';
    public const XML_PATH_GEMINI_API_KEY = 'mageai/api/gemini_api_secret';
    public const XML_PATH_GEMINI_MODEL = 'mageai/api/gemini_model';
    public const XML_PATH_PRODUCT_ATTRIBUTE = 'mageai/product_description/attribute';
    public const XML_PATH_TEMPERATURE = 'mageai/product_description/temperature';
    public const XML_PATH_DESCRIPTION_PROMPT = 'mageai/product_description/description_prompt';
    public const XML_PATH_DESCRIPTION_MAX_TOKENS = 'mageai/product_description/description_max_tokens';
    public const XML_PATH_SHORT_SHORT_DESCRIPTION_PROMPT = 'mageai/product_description/short_description_prompt';
    public const XML_PATH_SHORT_DESCRIPTION_MAX_TOKENS = 'mageai/product_description/short_description_max_tokens';
    public const XML_PATH_IMAGE_DEFAULT_PROMPT = 'mageai/image_generation/default_prompt';
    public const XML_PATH_IMAGE_MODIFY_DEFAULT_PROMPT = 'mageai/image_generation/modify_default_prompt';
    public const XML_PATH_IMAGE_ATTRIBUTE = 'mageai/image_generation/attribute';
    public const XML_PATH_IMAGE_MODEL = 'mageai/image_generation/openai_image_model';
    public const XML_PATH_GPT_IMAGE_SIZE = 'mageai/image_generation/gpt_image_size';
    public const XML_PATH_GPT_IMAGE_QUALITY = 'mageai/image_generation/gpt_image_quality';
    public const XML_PATH_GEMINI_IMAGE_MODEL = 'mageai/image_generation/gemini_image_model';
    public const XML_PATH_IMAGE_ANALYSIS_PROMPT = 'mageai/product_image_analysis/prompt';
    public const XML_PATH_IMAGE_ANALYSIS_MAX_TOKENS = 'mageai/product_image_analysis/max_tokens';
    public const XML_PATH_IMAGE_ANALYSIS_TEMPERATURE = 'mageai/product_image_analysis/temperature';
    public const XML_PATH_IMAGE_ANALYSIS_ATTRIBUTES = 'mageai/product_image_analysis/attributes';

    /**
     * Get config value
     *
     * @param string $path
     * @return mixed
     */
    public function getConfig($path)
    {
        return $this->scopeConfig->getValue($path);
    }

    /**
     * Check if extension is enabled
     *
     * @return bool
     */
    public function isEnabled()
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_IS_ENABLED);
    }

    /**
     * Get the global baseline (system) prompt applied to every text generation request
     *
     * Merchant-configured brand voice / compliance / SEO instructions. Empty by default.
     *
     * @return string
     */
    public function getBaselinePrompt(): string
    {
        return trim((string) $this->getConfig(self::XML_PATH_BASELINE_PROMPT));
    }

    /**
     * Get selected AI provider
     *
     * @return string  'openai' or 'anthropic'
     */
    public function getProvider()
    {
        return (string) $this->getConfig(self::XML_PATH_PROVIDER) ?: 'openai';
    }

    /**
     * Get OpenAI API base URL
     *
     * @return string
     */
    public function getApiBaseUrl()
    {
        return $this->getConfig(self::XML_PATH_API_BASE_URL);
    }

    /**
     * Build an OpenAI-compatible endpoint URL from the configured base URL.
     *
     * Merchants commonly enter either "https://host" or "https://host/v1".
     * This method supports both so OpenAI-compatible providers do not get a
     * duplicated /v1 segment.
     *
     * @param string $path Endpoint path, with or without a leading /v1
     * @return string
     */
    public function getOpenAIEndpointUrl(string $path): string
    {
        $baseUrl = rtrim((string) $this->getApiBaseUrl(), '/');
        $path = '/' . ltrim($path, '/');

        if (substr($path, 0, 4) === '/v1/') {
            $pathWithoutVersion = substr($path, 3);
        } else {
            $pathWithoutVersion = $path;
            $path = '/v1' . $path;
        }

        return substr($baseUrl, -3) === '/v1'
            ? $baseUrl . $pathWithoutVersion
            : $baseUrl . $path;
    }

    /**
     * Get OpenAI API secret
     *
     * @return string
     */
    public function getApiSecret()
    {
        return $this->getConfig(self::XML_PATH_API_KEY);
    }

    /**
     * Get OpenAI model
     *
     * @return string
     */
    public function getModel()
    {
        $customModel = trim((string) $this->getConfig(self::XML_PATH_API_CUSTOM_MODEL));
        return $customModel !== '' ? $customModel : $this->getConfig(self::XML_PATH_API_MODEL);
    }

    /**
     * Get Anthropic API base URL
     *
     * @return string
     */
    public function getAnthropicBaseUrl()
    {
        return $this->getConfig(self::XML_PATH_ANTHROPIC_BASE_URL);
    }

    /**
     * Get Anthropic API secret
     *
     * @return string
     */
    public function getAnthropicApiSecret()
    {
        return $this->getConfig(self::XML_PATH_ANTHROPIC_API_KEY);
    }

    /**
     * Get Anthropic model
     *
     * @return string
     */
    public function getAnthropicModel()
    {
        return $this->getConfig(self::XML_PATH_ANTHROPIC_MODEL);
    }

    /**
     * Get Gemini API base URL
     *
     * @return string
     */
    public function getGeminiBaseUrl()
    {
        return $this->getConfig(self::XML_PATH_GEMINI_BASE_URL);
    }

    /**
     * Get Gemini API secret
     *
     * @return string
     */
    public function getGeminiApiSecret()
    {
        return $this->getConfig(self::XML_PATH_GEMINI_API_KEY);
    }

    /**
     * Get Gemini model
     *
     * @return string
     */
    public function getGeminiModel()
    {
        return $this->getConfig(self::XML_PATH_GEMINI_MODEL);
    }

    /**
     * Get description prompt
     *
     * @return string
     */
    public function getDescriptionPrompt()
    {
        return $this->getConfig(self::XML_PATH_DESCRIPTION_PROMPT);
    }

    /**
     * Get short description prompt
     *
     * @return string
     */
    public function getShortDescriptionPrompt()
    {
        return $this->getConfig(self::XML_PATH_SHORT_SHORT_DESCRIPTION_PROMPT);
    }

    /**
     * Get sampling temperature
     *
     * @return float
     */
    public function getTemperature(): float
    {
        return (float) ($this->getConfig(self::XML_PATH_TEMPERATURE) ?? 0.5);
    }

    /**
     * Get max tokens for a description type
     *
     * @param string $type  'short' or 'full'
     * @return int
     */
    public function getMaxTokens(string $type): int
    {
        $path = $type === 'short'
            ? self::XML_PATH_SHORT_DESCRIPTION_MAX_TOKENS
            : self::XML_PATH_DESCRIPTION_MAX_TOKENS;
        return (int) ($this->getConfig($path) ?: 2048);
    }

    /**
     * Get selected product attribute codes as an array
     *
     * @return string[]
     */
    public function getProductAttributes(): array
    {
        $value = (string) $this->getConfig(self::XML_PATH_PRODUCT_ATTRIBUTE);
        return array_filter(array_map('trim', explode(',', $value)));
    }

    /**
     * Get default image generation prompt template
     *
     * @return string
     */
    public function getImageDefaultPrompt(): string
    {
        return (string) ($this->getConfig(self::XML_PATH_IMAGE_DEFAULT_PROMPT) ?: '');
    }

    /**
     * Get default image modification prompt template
     *
     * @return string
     */
    public function getImageModifyDefaultPrompt(): string
    {
        return (string) ($this->getConfig(self::XML_PATH_IMAGE_MODIFY_DEFAULT_PROMPT) ?: '');
    }

    /**
     * Get selected product attribute codes for image generation as an array
     *
     * @return string[]
     */
    public function getImageAttributes(): array
    {
        $value = (string) $this->getConfig(self::XML_PATH_IMAGE_ATTRIBUTE);
        return array_filter(array_map('trim', explode(',', $value)));
    }

    /**
     * Get OpenAI image generation model (dall-e-3 / dall-e-2)
     *
     * @return string
     */
    public function getImageModel(): string
    {
        return (string) ($this->getConfig(self::XML_PATH_IMAGE_MODEL) ?: 'gpt-image-2');
    }

    /**
     * Get the configured image size (all GPT Image models share the same size set)
     *
     * @return string
     */
    public function getImageSize(): string
    {
        return (string) ($this->getConfig(self::XML_PATH_GPT_IMAGE_SIZE) ?: '1024x1024');
    }

    /**
     * Get the configured OpenAI image quality (low / medium / high / auto)
     *
     * Lower quality generates significantly faster.
     *
     * @return string
     */
    public function getImageQuality(): string
    {
        return (string) ($this->getConfig(self::XML_PATH_GPT_IMAGE_QUALITY) ?: 'medium');
    }

    /**
     * Get Gemini Imagen model
     *
     * @return string
     */
    public function getGeminiImageModel(): string
    {
        return (string) ($this->getConfig(self::XML_PATH_GEMINI_IMAGE_MODEL) ?: 'gemini-2.5-flash-image');
    }

    /**
     * Get prompt used to analyze an existing product image into catalog metadata.
     *
     * @return string
     */
    public function getProductImageAnalysisPrompt(): string
    {
        return (string) ($this->getConfig(self::XML_PATH_IMAGE_ANALYSIS_PROMPT) ?: '');
    }

    /**
     * Get max tokens for product image metadata analysis.
     *
     * @return int
     */
    public function getProductImageAnalysisMaxTokens(): int
    {
        return (int) ($this->getConfig(self::XML_PATH_IMAGE_ANALYSIS_MAX_TOKENS) ?: 1200);
    }

    /**
     * Get sampling temperature dedicated to product image analysis.
     *
     * Defaults to 0 for deterministic catalog metadata generation.
     *
     * @return float
     */
    public function getProductImageAnalysisTemperature(): float
    {
        $value = $this->getConfig(self::XML_PATH_IMAGE_ANALYSIS_TEMPERATURE);
        return $value === null || $value === '' ? 0.0 : (float) $value;
    }

    /**
     * Get configured image-analysis target attributes and their prompt instructions.
     *
     * @return array<string, string> Attribute code => instruction
     */
    public function getProductImageAnalysisAttributes(): array
    {
        $configured = $this->getConfig(self::XML_PATH_IMAGE_ANALYSIS_ATTRIBUTES);
        $attributes = [];

        if (is_array($configured)) {
            foreach ($configured as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $code = trim((string) ($row['attribute'] ?? ''));
                $instruction = trim((string) ($row['instruction'] ?? ''));
                if ($code !== '' && $code !== '__empty' && $instruction !== '') {
                    $attributes[$code] = $instruction;
                }
            }
        }

        if (!empty($attributes)) {
            return $attributes;
        }

        return [
            'name' => 'Generate a short, natural, descriptive product title based on the visible image content. Do not keyword-stuff.',
            'description' => 'Generate one clear ecommerce product description paragraph, 80-180 words. Use the image as the primary source and use any existing title or keywords as supporting context only when helpful.',
            'keywords' => 'Generate the strongest primary catalog/search keywords as concise phrases. Return an array of 3-8 phrases.',
            'secondary_keywords' => 'Generate supporting subject, setting, style, season, emotion, and usage keywords. Return an array of 5-12 phrases.',
            'tertiary_keywords' => 'Generate additional related search terms. Return an array of 5-15 phrases.',
        ];
    }
}
