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
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Mageprince\MageAI\Helper\Data as HelperData;
use Mageprince\MageAI\Model\Query\QueryException;

class ImageAnalyzer
{
    private const SYSTEM_PROMPT = 'You analyze Christian art product images for ecommerce catalog metadata. Return only valid JSON. Be specific to the visible Biblical subject, event, people, setting, symbols, and ministry use. Avoid generic filler, generic emotions, bare colors, counts, and media words unless central to the image. Do not use markdown, explanations, or code fences.';
    private const REQUEST_TIMEOUT = 900;

    /**
     * @var Curl
     */
    protected $curl;

    /**
     * @var Json
     */
    protected $json;

    /**
     * @var HelperData
     */
    protected $helper;

    /**
     * @var ImageReader
     */
    protected $imageReader;

    /**
     * @var ProductAttributeRepositoryInterface
     */
    protected $attributeRepository;

    /**
     * @param Curl $curl
     * @param Json $json
     * @param HelperData $helper
     * @param ImageReader $imageReader
     * @param ProductAttributeRepositoryInterface $attributeRepository
     */
    public function __construct(
        Curl $curl,
        Json $json,
        HelperData $helper,
        ImageReader $imageReader,
        ProductAttributeRepositoryInterface $attributeRepository
    ) {
        $this->curl = $curl;
        $this->json = $json;
        $this->helper = $helper;
        $this->imageReader = $imageReader;
        $this->attributeRepository = $attributeRepository;
    }

    /**
     * Analyze a product image and return generated configured product attributes.
     *
     * @param ProductInterface $product
     * @return array<string, mixed>
     * @throws QueryException
     */
    public function analyze(ProductInterface $product): array
    {
        $image = $this->imageReader->read($product);
        $payload = $this->buildPayload($product, $image['data'], $image['mimeType']);

        $this->setHeaders();
        $this->curl->post($this->helper->getOpenAIEndpointUrl('/v1/chat/completions'), $payload);

        return $this->validateResponse();
    }

    /**
     * Build OpenAI-compatible chat completions payload with inline image data.
     *
     * @param ProductInterface $product
     * @param string $imageData
     * @param string $mimeType
     * @return string
     */
    private function buildPayload(ProductInterface $product, string $imageData, string $mimeType): string
    {
        $targetAttributes = $this->helper->getProductImageAnalysisAttributeConfig();
        $prompt = $this->buildPrompt($product, $targetAttributes);

        return $this->json->serialize([
            'model' => $this->helper->getModel(),
            'temperature' => $this->helper->getProductImageAnalysisTemperature(),
            'max_tokens' => $this->helper->getProductImageAnalysisMaxTokens(),
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'product_image_analysis',
                    'strict' => true,
                    'schema' => $this->buildResponseSchema($targetAttributes),
                ],
            ],
            'messages' => [
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $prompt],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => 'data:' . $mimeType . ';base64,' . base64_encode($imageData),
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * Set OpenAI-compatible request headers.
     *
     * @return void
     * @throws QueryException
     */
    private function setHeaders(): void
    {
        $token = $this->helper->getApiSecret();
        if (!$token) {
            throw new QueryException(__('OpenAI-compatible API key not found. Please check MageAI configuration.'));
        }

        $this->curl->setTimeout(self::REQUEST_TIMEOUT);
        $this->curl->setHeaders([
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ]);
    }

    /**
     * Parse and validate the AI response.
     *
     * @return array<string, mixed>
     * @throws QueryException
     */
    private function validateResponse(): array
    {
        $status = $this->curl->getStatus();
        if ($status === 401 || $status === 403) {
            throw new QueryException(__('Unauthorized response. Please check OpenAI-compatible API credentials.'));
        }
        if ($status >= 400) {
            throw new QueryException(__('OpenAI-compatible endpoint returned HTTP %1: %2', $status, $this->curl->getBody()));
        }

        $response = $this->json->unserialize($this->curl->getBody());
        if (isset($response['error'])) {
            throw new QueryException(__($response['error']['message'] ?? 'Unknown OpenAI-compatible API error.'));
        }

        $content = $response['choices'][0]['message']['content'] ?? $response['choices'][0]['text'] ?? '';
        if (!is_string($content) || trim($content) === '') {
            throw new QueryException(__('No image metadata content was returned by the OpenAI-compatible endpoint.'));
        }

        $data = $this->parseJsonObject($content);
        return isset($data['attributes']) && is_array($data['attributes']) ? $data['attributes'] : $data;
    }

    /**
     * Build full user prompt including target instructions and existing values.
     *
     * @param ProductInterface $product
     * @param array<string, array{attribute: string, instruction: string, policy: string, allow_new_options: bool}> $targetAttributes
     * @return string
     */
    private function buildPrompt(ProductInterface $product, array $targetAttributes): string
    {
        $lines = [];
        $lines[] = $this->helper->getProductImageAnalysisPrompt();
        $lines[] = '';
        $lines[] = 'Target attributes to generate:';

        foreach ($targetAttributes as $code => $config) {
            $attribute = $this->getAttribute($code);
            $optionInstruction = $this->getOptionPromptInstruction($attribute, (bool) $config['allow_new_options']);
            $lines[] = sprintf(
                '- %s (%s, input: %s, policy: %s): %s%s',
                $code,
                $attribute ? (string) $attribute->getDefaultFrontendLabel() : $code,
                $attribute ? (string) $attribute->getFrontendInput() : 'text',
                $config['policy'],
                $config['instruction'],
                $optionInstruction
            );
        }

        $lines[] = '';
        $lines[] = 'Quality rules:';
        $lines[] = '- Prefer specific Biblical subjects, named figures, events, places, symbols, doctrine, season, and ministry/worship use cases.';
        $lines[] = '- Primary keywords must be the main searchable subjects or story terms, not colors, numbers, moods, style labels, or generic product/media words.';
        $lines[] = '- Avoid generic keyword labels such as art, image, picture, painting, scene, abstract, modern, good, beautiful, happy, people, person, blue, red, green, purple, yellow, orange, black, white, one, two, three, four, 2nd, or second.';
        $lines[] = '- Descriptions must identify what is visibly happening and should not use vague filler like beautiful image, powerful artwork, inspiring scene, or perfect for any use.';
        $lines[] = '- Do not invent people, locations, objects, scripture references, or doctrine that are not visible or strongly supported by the existing product context.';
        $lines[] = '- If the existing title names the Biblical event or subject, preserve that meaning and use it to improve missing SEO fields.';
        $lines[] = '- Return empty arrays for keyword fields when no specific non-generic terms can be justified.';

        $lines[] = '';
        $lines[] = 'Existing product context. Use these values when helpful, especially when generating one blank field from other populated fields:';
        $lines[] = '- sku: ' . (string) $product->getSku();

        foreach ($targetAttributes as $code => $config) {
            $lines[] = sprintf('- current %s: %s', $code, $this->getProductAttributeText($product, $code));
        }

        return implode("\n", $lines);
    }

    /**
     * Build JSON schema for configured target attributes.
     *
     * @param array<string, array{attribute: string, instruction: string, policy: string, allow_new_options: bool}> $targetAttributes
     * @return array<string, mixed>
     */
    private function buildResponseSchema(array $targetAttributes): array
    {
        $properties = [];
        $required = [];

        foreach ($targetAttributes as $code => $config) {
            $attribute = $this->getAttribute($code);
            $input = $attribute ? (string) $attribute->getFrontendInput() : 'text';
            $enum = $attribute && !$config['allow_new_options'] ? $this->getOptionLabels($attribute) : [];
            $required[] = $code;

            if ($input === 'multiselect') {
                $items = ['type' => 'string'];
                if (!empty($enum) && count($enum) <= 200) {
                    $items['enum'] = $enum;
                }
                $properties[$code] = [
                    'type' => 'array',
                    'description' => $config['instruction'],
                    'items' => $items,
                ];
                continue;
            }

            $properties[$code] = [
                'type' => 'string',
                'description' => $config['instruction'],
            ];
            if ($input === 'select' && !empty($enum) && count($enum) <= 200) {
                $properties[$code]['enum'] = $enum;
            }
        }

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => $properties,
            'required' => $required,
        ];
    }

    /**
     * Extract a JSON object from a model response.
     *
     * @param string $content
     * @return array<string, mixed>
     * @throws QueryException
     */
    private function parseJsonObject(string $content): array
    {
        $content = trim($content);
        $content = preg_replace('/^```[a-z]*\r?\n?/i', '', $content);
        $content = preg_replace('/\r?\n?```\s*$/i', '', $content);

        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $content = substr($content, $start, $end - $start + 1);
        }

        try {
            $data = $this->json->unserialize($content);
        } catch (\Exception $e) {
            throw new QueryException(__('Image metadata response was not valid JSON: %1', $e->getMessage()));
        }

        if (!is_array($data)) {
            throw new QueryException(__('Image metadata response was not a JSON object.'));
        }

        return $data;
    }

    /**
     * Get attribute metadata by code.
     *
     * @param string $code
     * @return \Magento\Catalog\Api\Data\ProductAttributeInterface|null
     */
    private function getAttribute(string $code)
    {
        try {
            return $this->attributeRepository->get($code);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Build option-specific prompt instructions for select and multiselect fields.
     *
     * @param mixed $attribute
     * @param bool $allowNewOptions
     * @return string
     */
    private function getOptionPromptInstruction($attribute, bool $allowNewOptions): string
    {
        if (!$attribute) {
            return '';
        }

        $input = (string) $attribute->getFrontendInput();
        if ($input !== 'select' && $input !== 'multiselect') {
            return '';
        }

        if ($allowNewOptions) {
            return ' You may return concise new option labels when existing options do not cover the image. Use specific subject phrases only; avoid generic colors, counts, moods, media words, and style labels.';
        }

        $labels = $this->getOptionLabels($attribute);
        if (empty($labels)) {
            return ' Choose only an existing option label; do not invent a new option.';
        }

        return ' Choose only from these existing option labels: ' . implode(', ', array_slice($labels, 0, 200)) . '.';
    }

    /**
     * Get non-empty option labels for an attribute.
     *
     * @param mixed $attribute
     * @return string[]
     */
    private function getOptionLabels($attribute): array
    {
        $input = (string) $attribute->getFrontendInput();
        if ($input !== 'select' && $input !== 'multiselect') {
            return [];
        }

        $labels = [];
        try {
            foreach ($attribute->getSource()->getAllOptions(false, false) as $option) {
                $label = trim((string) ($option['label'] ?? ''));
                if ($label !== '') {
                    $labels[$label] = $label;
                }
            }
        } catch (\Exception $e) {
            return [];
        }

        natcasesort($labels);
        return array_values($labels);
    }

    /**
     * Render a product attribute value as prompt context.
     *
     * @param ProductInterface $product
     * @param string $code
     * @return string
     */
    private function getProductAttributeText(ProductInterface $product, string $code): string
    {
        $attribute = $this->getAttribute($code);
        if (!$attribute) {
            return trim(strip_tags((string) $product->getData($code)));
        }

        try {
            $value = $attribute->getFrontend()->getValue($product);
            if (is_array($value)) {
                $value = implode(', ', $value);
            }
            return trim(strip_tags((string) $value));
        } catch (\Exception $e) {
            return trim(strip_tags((string) $product->getData($code)));
        }
    }
}
