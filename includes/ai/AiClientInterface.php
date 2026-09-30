<?php
declare(strict_types=1);

interface AiClientInterface
{
    /**
     * Analyze a problem description and return structured diagnostic interpretation.
     *
     * @param string $description The raw problem description from the customer.
     * @param array $context Contextual data (category hint, city, area, user urgency).
     * @return array|null Structured analysis array, or null on failure.
     */
    public function analyzeProblem(string $description, array $context = []): ?array;

    /**
     * Generate 2-3 targeted follow-up questions if critical details are missing,
     * or return an empty array if description is already complete.
     *
     * @param string $description The problem description.
     * @param array $analysis Current analysis or ServiceDNA.
     * @return array List of string questions.
     */
    public function generateFollowUpQuestions(string $description, array $analysis): array;

    /**
     * Check if the AI provider is available and ready to handle requests.
     */
    public function isAvailable(): bool;

    /**
     * Return provider name for audit logs and explainability telemetry.
     */
    public function getName(): string;
}
