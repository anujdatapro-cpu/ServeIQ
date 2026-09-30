<?php
declare(strict_types=1);

require_once __DIR__ . '/AiClientInterface.php';

class MockAiClient implements AiClientInterface
{
    private ?array $cannedResponse = null;
    private ?array $cannedQuestions = null;
    private bool $simulateException = false;
    private string $exceptionMessage = 'Mock AI service timeout simulated';
    private bool $simulateMalformed = false;
    private bool $available = true;

    public function setCannedResponse(?array $response): self
    {
        $this->cannedResponse = $response;
        return $this;
    }

    public function setCannedQuestions(?array $questions): self
    {
        $this->cannedQuestions = $questions;
        return $this;
    }

    public function setSimulateException(bool $simulate, string $message = 'Mock AI service timeout simulated'): self
    {
        $this->simulateException = $simulate;
        $this->exceptionMessage = $message;
        return $this;
    }

    public function setSimulateMalformed(bool $simulate): self
    {
        $this->simulateMalformed = $simulate;
        return $this;
    }

    public function setAvailable(bool $available): self
    {
        $this->available = $available;
        return $this;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function getName(): string
    {
        return 'mock_ai_v1';
    }

    public function analyzeProblem(string $description, array $context = []): ?array
    {
        if ($this->simulateException) {
            throw new RuntimeException($this->exceptionMessage);
        }

        if ($this->simulateMalformed) {
            return [
                'invalid_key' => 'corrupt data',
                'problem_type' => 12345, // invalid type
            ];
        }

        if ($this->cannedResponse !== null) {
            return $this->cannedResponse;
        }

        // Default mock response
        return [
            'problem_type' => 'Overheating',
            'affected_entity' => 'Laptop',
            'symptoms' => ['overheating', 'loud fan'],
            'context' => ['Gaming'],
            'urgency' => 'high',
            'keywords' => ['laptop', 'overheat', 'fan', 'hot'],
            'possible_service_types' => ['Laptop Cleaning', 'Fan Inspection'],
            'location_context' => $context['location_context'] ?? ['city' => '', 'area' => ''],
            'confidence_score' => 88,
            'reasoning_evidence' => [
                'entity' => 'Laptop identified from hardware thermal keywords',
                'symptom' => 'High heat combined with fan noise suggests cooling failure',
            ],
            'follow_up_questions' => [
                'Does the laptop feel hot to touch on the bottom or near the exhaust vents?',
                'Does the fan run at maximum speed continuously?',
            ],
        ];
    }

    public function generateFollowUpQuestions(string $description, array $analysis): array
    {
        if ($this->cannedQuestions !== null) {
            return $this->cannedQuestions;
        }

        return [
            'Does the device display any warning or error message?',
            'Has the device had any prior liquid spills or physical drops?',
        ];
    }
}
