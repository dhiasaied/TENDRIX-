<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Ai\PricingAgent;
use App\Ai\TenderWriterAgent;
use App\ApiResource\PriceRange;
use App\ApiResource\TenderDraft;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Runs the stateless AI agents. Rate-limited per user, since each call may hit the LLM.
 *
 * @implements ProcessorInterface<TenderDraft|PriceRange, TenderDraft|PriceRange>
 */
final class AiProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly TenderWriterAgent $writer,
        private readonly PricingAgent $pricing,
        private readonly Security $security,
        #[Autowire(service: 'limiter.ai_agents')]
        private readonly RateLimiterFactory $limiter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TenderDraft|PriceRange
    {
        $limit = $this->limiter->create($this->security->getUser()?->getUserIdentifier() ?? 'anonymous')->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException($limit->getRetryAfter()->getTimestamp() - time(), 'AI quota exceeded, please retry later.');
        }

        if ($data instanceof PriceRange) {
            return $this->fillPrice($data, $data->skills, $data->seniority);
        }

        \assert($data instanceof TenderDraft);
        $draft = $this->writer->draft($data->title, $data->context, $data->skills, $data->seniority, $data->estimatedDays);
        $data->title = $draft['title'];
        $data->description = $draft['description'];
        $data->skills = $draft['skills'];
        $data->seniority = $draft['seniority'];
        $data->estimatedDays = $draft['estimatedDays'];
        $data->source = $draft['source'];
        $price = $this->fillPrice(new PriceRange(), $data->skills, $data->seniority);
        $data->suggestedPrice = [
            'low' => $price->low, 'median' => $price->median, 'high' => $price->high, 'sampleSize' => $price->sampleSize,
            'basis' => $price->basis, 'rationale' => $price->rationale, 'source' => $price->source,
        ];

        return $data;
    }

    /** @param list<string> $skills */
    private function fillPrice(PriceRange $range, array $skills, string $seniority): PriceRange
    {
        $suggestion = $this->pricing->suggest($skills, $seniority);
        $range->skills = $skills;
        $range->seniority = $seniority;
        $range->low = $suggestion->low;
        $range->median = $suggestion->median;
        $range->high = $suggestion->high;
        $range->sampleSize = $suggestion->sampleSize;
        $range->basis = $suggestion->basis;
        $range->rationale = $suggestion->rationale;
        $range->source = $suggestion->source;

        return $range;
    }
}
