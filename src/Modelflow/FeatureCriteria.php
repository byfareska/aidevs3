<?php declare(strict_types=1);

namespace App\Modelflow;

use ModelflowAi\DecisionTree\Criteria\CriteriaInterface;
use ModelflowAi\DecisionTree\DecisionEnum;

enum FeatureCriteria: string implements CriteriaInterface
{
    case IMAGE_VISION = 'image_vision';
    case TEXT_GENERATION = 'text_generation';

    public function matches(CriteriaInterface $toMatch): DecisionEnum
    {
        if (!$toMatch instanceof self) {
            return DecisionEnum::ABSTAIN;
        }

        return $this->value === $toMatch->value ? DecisionEnum::MATCH : DecisionEnum::NO_MATCH;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
