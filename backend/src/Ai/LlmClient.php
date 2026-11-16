<?php

namespace App\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Thin gateway to Claude (official Anthropic PHP SDK) returning schema-validated JSON.
 *
 * Every agent in the platform has a deterministic fallback, so a missing API key, a network
 * failure or a refusal degrades gracefully instead of breaking a business flow: callers get
 * null and switch to their heuristic.
 */
class LlmClient
{
    private ?Client $client = null;

    public function __construct(
        #[Autowire('%env(default::ANTHROPIC_API_KEY)%')]
        private readonly ?string $apiKey,
        #[Autowire('%env(ANTHROPIC_MODEL)%')]
        private readonly string $model,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isEnabled(): bool
    {
        return null !== $this->apiKey && '' !== trim($this->apiKey);
    }

    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * @param array<string, mixed> $schema JSON schema of the expected object (additionalProperties: false)
     *
     * @return array<string, mixed>|null decoded object, or null when the LLM is unavailable
     */
    public function json(string $system, string $prompt, array $schema, int $maxTokens = 4000): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        try {
            $message = $this->client()->messages->create(
                maxTokens: $maxTokens,
                messages: [['role' => 'user', 'content' => $prompt]],
                model: $this->model,
                outputConfig: [
                    'effort' => 'low',
                    'format' => ['type' => 'json_schema', 'schema' => $schema],
                ],
                system: $system,
            );
        } catch (AnthropicException $e) {
            $this->logger->warning('LLM call failed, using fallback.', ['exception' => $e]);

            return null;
        }

        if ('end_turn' !== $message->stopReason) {
            $this->logger->notice('LLM did not complete normally, using fallback.', ['stop_reason' => $message->stopReason]);

            return null;
        }

        foreach ($message->content as $block) {
            if ('text' === $block->type) {
                $data = json_decode($block->text, true);

                return \is_array($data) ? $data : null;
            }
        }

        return null;
    }

    private function client(): Client
    {
        return $this->client ??= new Client(apiKey: $this->apiKey, requestOptions: ['timeout' => 60.0, 'maxRetries' => 2]);
    }
}
