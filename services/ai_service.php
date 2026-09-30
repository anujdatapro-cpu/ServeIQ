<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/ai.php';
require_once __DIR__ . '/../includes/ai/AiClientInterface.php';
require_once __DIR__ . '/../includes/ai/RuleEnhancedAiClient.php';
require_once __DIR__ . '/../includes/ai/MockAiClient.php';

class AiService
{
    private static ?AiClientInterface $activeClient = null;

    /**
     * Override the active AI client (used for unit & regression tests).
     */
    public static function setClient(?AiClientInterface $client): void
    {
        self::$activeClient = $client;
    }

    /**
     * Reset active client to configuration default.
     */
    public static function resetClient(): void
    {
        self::$activeClient = null;
    }

    /**
     * Get or instantiate the active AI client based on configuration.
     */
    public static function getClient(): AiClientInterface
    {
        if (self::$activeClient !== null) {
            return self::$activeClient;
        }

        $provider = defined('SERVEIQ_AI_PROVIDER') ? SERVEIQ_AI_PROVIDER : 'local';

        self::$activeClient = match ($provider) {
            'mock' => new MockAiClient(),
            'none' => new class implements AiClientInterface {
                public function analyzeProblem(string $description, array $context = []): ?array { return null; }
                public function generateFollowUpQuestions(string $description, array $analysis): array { return []; }
                public function isAvailable(): bool { return false; }
                public function getName(): string { return 'disabled_client'; }
            },
            default => new RuleEnhancedAiClient(),
        };

        return self::$activeClient;
    }

    /**
     * Sanitize description to prevent customer PII leakage before AI analysis.
     */
    public static function sanitizeDescription(string $description): string
    {
        // Redact phone numbers (7+ digits)
        $clean = preg_replace('/\b(?:\+?\d{1,3}[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}\b/u', '[PHONE REDACTED]', $description) ?? $description;
        // Redact email addresses
        $clean = preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/u', '[EMAIL REDACTED]', $clean) ?? $clean;
        // Redact passwords or secrets if present
        $clean = preg_replace('/password\s*[:=]\s*\S+/ui', 'password: [REDACTED]', $clean) ?? $clean;

        return trim($clean);
    }

    /**
     * Strictly validate structured AI analysis payload.
     * Returns sanitized array on success, or null on validation failure (malformed payload).
     */
    public static function validateAiOutput(mixed $output): ?array
    {
        if (!is_array($output)) {
            return null;
        }

        // Check required schema fields
        $requiredKeys = [
            'problem_type',
            'affected_entity',
            'symptoms',
            'context',
            'urgency',
            'keywords',
            'possible_service_types',
            'confidence_score',
        ];

        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $output)) {
                return null;
            }
        }

        // Validate types
        if ($output['problem_type'] !== null && !is_string($output['problem_type'])) {
            return null;
        }
        if ($output['affected_entity'] !== null && !is_string($output['affected_entity'])) {
            return null;
        }
        if (!is_array($output['symptoms']) || !is_array($output['context']) || !is_array($output['keywords']) || !is_array($output['possible_service_types'])) {
            return null;
        }
        if (!is_int($output['confidence_score']) || $output['confidence_score'] < 0 || $output['confidence_score'] > 100) {
            return null;
        }

        $allowedUrgencies = ['low', 'medium', 'high', 'emergency', null];
        if (!in_array($output['urgency'], $allowedUrgencies, true)) {
            return null;
        }

        // Sanitize string arrays
        $sanitizeStringArray = static function (array $items): array {
            $result = [];
            foreach ($items as $item) {
                if (is_scalar($item)) {
                    $val = trim((string)$item);
                    if ($val !== '' && !in_array($val, $result, true)) {
                        $result[] = $val;
                    }
                }
            }
            return $result;
        };

        $followUpQuestions = [];
        if (isset($output['follow_up_questions']) && is_array($output['follow_up_questions'])) {
            $followUpQuestions = $sanitizeStringArray($output['follow_up_questions']);
            $maxQuestions = defined('SERVEIQ_AI_MAX_QUESTIONS') ? SERVEIQ_AI_MAX_QUESTIONS : 3;
            $followUpQuestions = array_slice($followUpQuestions, 0, $maxQuestions);
        }

        $reasoningEvidence = [];
        if (isset($output['reasoning_evidence']) && is_array($output['reasoning_evidence'])) {
            $reasoningEvidence = $output['reasoning_evidence'];
        }

        return [
            'problem_type' => $output['problem_type'] !== null ? trim((string)$output['problem_type']) : null,
            'affected_entity' => $output['affected_entity'] !== null ? trim((string)$output['affected_entity']) : null,
            'symptoms' => $sanitizeStringArray($output['symptoms']),
            'context' => $sanitizeStringArray($output['context']),
            'urgency' => $output['urgency'] !== null ? (string)$output['urgency'] : null,
            'keywords' => $sanitizeStringArray($output['keywords']),
            'possible_service_types' => $sanitizeStringArray($output['possible_service_types']),
            'location_context' => is_array($output['location_context'] ?? null) ? $output['location_context'] : [],
            'confidence_score' => (int)$output['confidence_score'],
            'follow_up_questions' => $followUpQuestions,
            'reasoning_evidence' => $reasoningEvidence,
        ];
    }

    /**
     * Enhance deterministic ServiceDNA with AI intelligence layer.
     * Guaranteed zero-downtime: if AI fails, times out, is disabled, or produces malformed output,
     * returns deterministic DNA safely.
     */
    public static function enhanceServiceDna(array $deterministicDna, string $description, array $context = []): array
    {
        $enabled = defined('SERVEIQ_AI_ENABLED') ? SERVEIQ_AI_ENABLED : true;
        if (!$enabled) {
            return self::applyFallbackMetadata($deterministicDna, 'ai_disabled');
        }

        try {
            $client = self::getClient();
            if (!$client->isAvailable()) {
                return self::applyFallbackMetadata($deterministicDna, 'ai_unavailable');
            }

            $sanitizedDescription = self::sanitizeDescription($description);
            $rawAiAnalysis = $client->analyzeProblem($sanitizedDescription, array_merge($context, [
                'user_urgency' => $deterministicDna['user_urgency'] ?? 'medium',
                'category_name' => $deterministicDna['category_name'] ?? null,
            ]));

            if ($rawAiAnalysis === null) {
                return self::applyFallbackMetadata($deterministicDna, 'ai_returned_null');
            }

            $validatedAi = self::validateAiOutput($rawAiAnalysis);
            if ($validatedAi === null) {
                // Malformed schema: reject and safely fall back
                error_log('[ServeIQ AI] Malformed AI schema detected. Safely falling back to deterministic baseline.');
                return self::applyFallbackMetadata($deterministicDna, 'malformed_schema');
            }

            // Combine deterministic baseline and AI intelligence
            return self::mergeHybridInterpretation($deterministicDna, $validatedAi, $client->getName());

        } catch (Throwable $exception) {
            error_log('[ServeIQ AI Error] ' . $exception->getMessage());
            return self::applyFallbackMetadata($deterministicDna, 'exception: ' . $exception->getMessage());
        }
    }

    /**
     * Merge deterministic baseline and AI output into unified hybrid ServiceDNA.
     */
    private static function mergeHybridInterpretation(array $det, array $ai, string $providerName): array
    {
        $detProblem = $det['problem_type'] ?? null;
        $aiProblem = $ai['problem_type'] ?? null;
        $detEntity = $det['affected_entity'] ?? null;
        $aiEntity = $ai['affected_entity'] ?? null;

        // Determine agreement
        $problemAgrees = ($detProblem === null && $aiProblem === null)
            || ($detProblem !== null && $aiProblem !== null && mb_strtolower($detProblem) === mb_strtolower($aiProblem));
        $entityAgrees = ($detEntity === null && $aiEntity === null)
            || ($detEntity !== null && $aiEntity !== null && mb_strtolower($detEntity) === mb_strtolower($aiEntity));

        $isAgreement = $problemAgrees && $entityAgrees;
        $detConf = (int)($det['confidence_score'] ?? 0);
        $aiConf = (int)($ai['confidence_score'] ?? 0);

        $disagreementDetails = [];

        if ($isAgreement) {
            // If neither engine detected any problem/entity or both confidences are 0, final confidence is 0
            if (($detProblem === null && $aiProblem === null) || ($detConf === 0 && $aiConf === 0)) {
                $finalConfidence = 0;
            } else {
                // Both methods agree: Multi-method consensus boost
                // Calculation: 50% deterministic + 40% AI + 10% multi-method consensus bonus
                $boosted = (int)round(($detConf * 0.50) + ($aiConf * 0.40) + 12);
                $finalConfidence = min(98, max($detConf, $boosted));
            }
            $disagreementFlag = false;

            // Resolve primary fields (aligned)
            $resolvedProblem = $detProblem ?? $aiProblem;
            $resolvedEntity = $detEntity ?? $aiEntity;
        } else {
            // Disagreement: do NOT blindly trust AI.
            // Retain conservative deterministic baseline for core classification as primary anchor.
            $disagreementFlag = true;
            $disagreementDetails = [
                'deterministic_problem' => $detProblem,
                'ai_problem' => $aiProblem,
                'deterministic_entity' => $detEntity,
                'ai_entity' => $aiEntity,
                'resolution' => 'Retained deterministic classification as primary baseline; enriched non-conflicting diagnostic keywords and symptoms.',
            ];

            // Conservative confidence penalty when signals conflict
            $finalConfidence = max(0, min($detConf, (int)round(($detConf * 0.60) + ($aiConf * 0.40) - 8)));

            // Anchor to deterministic baseline if it detected something; otherwise allow AI entity
            $resolvedProblem = $detProblem ?? $aiProblem;
            $resolvedEntity = $detEntity ?? $aiEntity;
        }

        // Union non-conflicting symptoms, context, keywords, services
        $mergedSymptoms = self::unionArrays($det['symptoms'] ?? [], $ai['symptoms'] ?? []);
        $mergedContext = self::unionArrays($det['context'] ?? [], $ai['context'] ?? []);
        $mergedKeywords = self::unionArrays($det['keywords'] ?? [], $ai['keywords'] ?? []);
        $mergedServices = self::unionArrays($det['possible_service_types'] ?? [], $ai['possible_service_types'] ?? []);

        // Evidence combination
        $mergedEvidence = $det['evidence'] ?? [];
        if (!empty($ai['reasoning_evidence'])) {
            $mergedEvidence['ai_reasoning'] = $ai['reasoning_evidence'];
        }
        if ($isAgreement) {
            $mergedEvidence['consensus_status'] = 'Full multi-method consensus achieved.';
        } else {
            $mergedEvidence['consensus_status'] = 'Disagreement detected; deterministic baseline retained as primary anchor.';
            $mergedEvidence['disagreement_resolution'] = $disagreementDetails;
        }

        $hybrid = $det;
        $hybrid['problem_type'] = $resolvedProblem;
        $hybrid['affected_entity'] = $resolvedEntity;
        $hybrid['symptoms'] = $mergedSymptoms;
        $hybrid['context'] = $mergedContext;
        $hybrid['keywords'] = $mergedKeywords;
        $hybrid['possible_service_types'] = $mergedServices;
        $hybrid['confidence_score'] = $finalConfidence;
        $hybrid['analysis_method'] = 'hybrid_ai_v1';
        $hybrid['engine_version'] = 'serveiq-hybrid-1.0';
        $hybrid['evidence'] = $mergedEvidence;

        // Telemetry payload stored in fingerprint_data
        $hybrid['ai_used'] = true;
        $hybrid['ai_status'] = 'success';
        $hybrid['ai_provider'] = $providerName;
        $hybrid['ai_confidence'] = $aiConf;
        $hybrid['deterministic_confidence'] = $detConf;
        $hybrid['final_confidence'] = $finalConfidence;
        $hybrid['disagreement_flag'] = $disagreementFlag;
        $hybrid['disagreement_details'] = $disagreementDetails;
        $hybrid['follow_up_questions'] = $ai['follow_up_questions'] ?? [];
        $hybrid['reasoning_evidence'] = $ai['reasoning_evidence'] ?? [];

        return $hybrid;
    }

    /**
     * Mark deterministic DNA with fallback telemetry.
     */
    private static function applyFallbackMetadata(array $deterministicDna, string $statusReason): array
    {
        $dna = $deterministicDna;
        $dna['ai_used'] = false;
        $dna['ai_status'] = $statusReason;
        $dna['ai_provider'] = 'none';
        $dna['ai_confidence'] = 0;
        $dna['deterministic_confidence'] = (int)($deterministicDna['confidence_score'] ?? 0);
        $dna['final_confidence'] = (int)($deterministicDna['confidence_score'] ?? 0);
        $dna['disagreement_flag'] = false;
        $dna['disagreement_details'] = [];
        $dna['follow_up_questions'] = [];
        $dna['reasoning_evidence'] = ['fallback_reason' => $statusReason];

        return $dna;
    }

    private static function unionArrays(array $a, array $b): array
    {
        $result = [];
        foreach (array_merge($a, $b) as $item) {
            $item = trim((string)$item);
            if ($item !== '' && !in_array($item, $result, true)) {
                $result[] = $item;
            }
        }
        return $result;
    }
}
