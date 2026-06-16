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
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Mageprince\MageAI\Helper\Data as HelperData;
use Mageprince\MageAI\Model\Query\QueryException;

class ImageAnalyzer
{
    private const SYSTEM_PROMPT = 'You analyze product images for ecommerce catalog metadata. Return only valid JSON. Do not use markdown, explanations, or code fences.';

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
     * @param Curl $curl
     * @param Json $json
     * @param HelperData $helper
     * @param ImageReader $imageReader
     */
    public function __construct(
        Curl $curl,
        Json $json,
        HelperData $helper,
        ImageReader $imageReader
    ) {
        $this->curl = $curl;
        $this->json = $json;
        $this->helper = $helper;
        $this->imageReader = $imageReader;
    }

    /**
     * Analyze a product image and return generated title, description and keyword tiers.
     *
     * @param ProductInterface $product
     * @return array{title: string, description: string, primary_keywords: string[], secondary_keywords: string[], tertiary_keywords: string[]}
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
        $prompt = $this->helper->getProductImageAnalysisPrompt();
        $context = sprintf(
            "\n\nExisting product context:\n- SKU: %s\n- Current title: %s\n- Current description: %s",
            (string) $product->getSku(),
            (string) $product->getName(),
            trim(strip_tags((string) $product->getDescription()))
        );

        return $this->json->serialize([
            'model' => $this->helper->getModel(),
            'temperature' => min(1, $this->helper->getTemperature()),
            'max_tokens' => $this->helper->getProductImageAnalysisMaxTokens(),
            'messages' => [
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $prompt . $context],
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

        $this->curl->setHeaders([
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ]);
    }

    /**
     * Parse and validate the AI response.
     *
     * @return array{title: string, description: string, primary_keywords: string[], secondary_keywords: string[], tertiary_keywords: string[]}
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

        return [
            'title' => trim((string) ($data['title'] ?? '')),
            'description' => trim((string) ($data['description'] ?? '')),
            'primary_keywords' => $this->normalizeKeywords($data['primary_keywords'] ?? []),
            'secondary_keywords' => $this->normalizeKeywords($data['secondary_keywords'] ?? []),
            'tertiary_keywords' => $this->normalizeKeywords($data['tertiary_keywords'] ?? []),
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
     * Normalize keyword response to a de-duplicated string list.
     *
     * @param mixed $value
     * @return string[]
     */
    private function normalizeKeywords($value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value)) {
            return [];
        }

        $keywords = [];
        foreach ($value as $keyword) {
            $keyword = trim((string) $keyword);
            if ($keyword !== '') {
                $keywords[strtolower($keyword)] = $keyword;
            }
        }

        return array_values($keywords);
    }
}
