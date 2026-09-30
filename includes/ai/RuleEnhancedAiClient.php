<?php
declare(strict_types=1);

require_once __DIR__ . '/AiClientInterface.php';

/**
 * RuleEnhancedAiClient
 *
 * An offline, deterministic semantic AI diagnostic engine.
 * Employs heuristic semantic extraction, entity-symptom clustering,
 * diagnostic completeness analysis, and transparent explainability evidence.
 * Operates with 100% offline reliability, zero external cost, and zero network dependencies.
 */
class RuleEnhancedAiClient implements AiClientInterface
{
    public function isAvailable(): bool
    {
        return true;
    }

    public function getName(): string
    {
        return 'rule_enhanced_ai_v1';
    }

    public function analyzeProblem(string $description, array $context = []): ?array
    {
        $normalized = $this->normalizeText($description);
        if ($normalized === '') {
            return $this->buildEmptyResponse($context);
        }

        $entity = $this->detectAffectedEntity($normalized);
        $problemType = $this->detectProblemType($normalized, $entity);
        $symptoms = $this->extractSymptoms($normalized);
        $detectedContexts = $this->extractContexts($normalized);
        $urgency = $this->inferUrgency($normalized, $context['user_urgency'] ?? 'medium');
        $possibleServices = $this->inferServiceTypes($entity, $problemType, $symptoms);
        $keywords = $this->extractKeywords($normalized);

        // Reasoning evidence for explainability
        $reasoningEvidence = [];
        if ($entity !== null) {
            $reasoningEvidence['entity'] = "Identified {$entity} from semantic hardware/appliance markers.";
        }
        if ($problemType !== null) {
            $reasoningEvidence['problem_type'] = "Inferred '{$problemType}' diagnosis from observed behavioral pattern.";
        }
        if (!empty($symptoms)) {
            $reasoningEvidence['symptoms'] = "Extracted symptoms: " . implode(', ', $symptoms) . ".";
        }
        if (!empty($detectedContexts)) {
            $reasoningEvidence['context'] = "Contextual triggers detected: " . implode(', ', $detectedContexts) . ".";
        }

        // Calculate AI confidence score (0-100) based on diagnostic density
        $confidence = 0;
        if ($entity !== null) {
            $confidence += 30;
        }
        if ($problemType !== null) {
            $confidence += 25;
        }
        $confidence += min(25, count($symptoms) * 10);
        $confidence += min(10, count($detectedContexts) * 5);
        $confidence += min(10, count($keywords) * 2);
        $confidence = min(95, max(0, $confidence));

        $analysis = [
            'problem_type' => $problemType,
            'affected_entity' => $entity,
            'symptoms' => $symptoms,
            'context' => $detectedContexts,
            'urgency' => $urgency,
            'keywords' => $keywords,
            'possible_service_types' => $possibleServices,
            'location_context' => [
                'city' => (string)($context['city'] ?? ''),
                'area' => (string)($context['area'] ?? ''),
            ],
            'confidence_score' => $confidence,
            'reasoning_evidence' => $reasoningEvidence,
        ];

        // Generate intelligent follow-up questions
        $analysis['follow_up_questions'] = $this->generateFollowUpQuestions($description, $analysis);

        return $analysis;
    }

    public function generateFollowUpQuestions(string $description, array $analysis): array
    {
        $normalized = $this->normalizeText($description);
        if ($normalized === '') {
            return [];
        }

        $entity = $analysis['affected_entity'] ?? null;
        $problemType = $analysis['problem_type'] ?? null;
        $symptoms = $analysis['symptoms'] ?? [];

        // Check if description is already detailed and comprehensive
        // If it specifies entity, problem, context, and diagnostic specifics (sufficient length, power/state details present)
        $wordCount = count(preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $hasPowerInfo = preg_match('/\b(power|led|turned off|turning on|turned on|running|stopped|battery|charger|charging|plugged|adapter|spill|drop|compressor|motor|fan)\b/u', $normalized) === 1;
        $hasTimingInfo = preg_match('/\b(after|during|minutes|hours|days|yesterday|suddenly|while|continuously|always)\b/u', $normalized) === 1;

        if ($wordCount >= 20 && $entity !== null && $problemType !== null && ($hasPowerInfo || $hasTimingInfo)) {
            // Description is sufficiently complete; no unnecessary questions
            return [];
        }

        $questions = [];

        // Entity / Symptom specific questions
        if ($entity === 'Laptop' || $entity === 'Computer') {
            if ($problemType === 'Overheating' || in_array('overheating', $symptoms, true)) {
                if (!str_contains($normalized, 'fan') && !str_contains($normalized, 'noise')) {
                    $questions[] = 'Is the cooling fan spinning loudly or does it remain completely silent?';
                }
                if (!str_contains($normalized, 'shutdown') && !str_contains($normalized, 'turns off')) {
                    $questions[] = 'Does the computer shut down or freeze abruptly when it gets hot?';
                }
                $questions[] = 'Does the heat feel concentrated near the bottom vents or across the keyboard?';
            } elseif ($problemType === 'Not starting' || $problemType === 'Power failure') {
                $questions[] = 'Does any power LED or charging indicator illuminate when the charger is connected?';
                $questions[] = 'Did the device receive any recent liquid exposure or physical impact?';
                $questions[] = 'Does the screen display any logo, backlight, or error code before turning off?';
            } elseif ($problemType === 'Broken or cracked screen') {
                $questions[] = 'Does the display show lines or internal ink bleeds, or is only the outer glass cracked?';
                $questions[] = 'Does the computer still output video when connected to an external monitor?';
            }
        } elseif ($entity === 'AC') {
            if ($problemType === 'Not cooling' || in_array('not cooling', $symptoms, true)) {
                $questions[] = 'Is air coming out of the indoor vents, and is it blowing warm or completely room temperature?';
                $questions[] = 'Can you hear the outdoor compressor unit turning on and humming?';
                if (!str_contains($normalized, 'leak') && !str_contains($normalized, 'water')) {
                    $questions[] = 'Is there any water dripping or ice formation on the indoor evaporator unit?';
                }
            } elseif ($problemType === 'Leakage') {
                $questions[] = 'Is water leaking from the front indoor casing or around the outdoor drain pipe?';
                $questions[] = 'Does the leakage occur continuously or only when the AC runs on high fan speed?';
            }
        } elseif ($entity === 'Washing Machine') {
            if (!str_contains($normalized, 'drain') && !str_contains($normalized, 'spin')) {
                $questions[] = 'Does the issue happen during the wash cycle, the spin cycle, or water draining?';
            }
            $questions[] = 'Is the machine producing excessive shaking, vibrating, or unusual grinding noises?';
        } elseif ($entity === 'Pipe' || $entity === 'Tap') {
            $questions[] = 'Does the leak stop when the main water shutoff valve is closed?';
            $questions[] = 'Is the water leaking from the fixture connection, threaded joints, or a visible pipe crack?';
        }

        // Generic fallback diagnostic questions for incomplete or vague descriptions
        if (empty($questions)) {
            if ($entity === null) {
                $questions[] = 'Which specific appliance, computer, or fixture is experiencing the problem?';
            }
            if ($problemType === null) {
                $questions[] = 'Does the device turn on, show power lights, or fail to respond completely?';
                $questions[] = 'Did this issue start suddenly, or has it gradually worsened over time?';
            } else {
                $questions[] = 'Does the issue occur continuously or intermittently under certain conditions?';
                $questions[] = 'Have any error codes, warning lights, or unusual noises been observed?';
            }
        }

        // Limit to max 3 targeted questions
        return array_slice($questions, 0, 3);
    }

    private function normalizeText(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? '';
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    private function detectAffectedEntity(string $text): ?string
    {
        $rules = [
            'Laptop' => ['laptop', 'notebook', 'macbook', 'thinkpad', 'ultrabook'],
            'Computer' => ['computer', 'pc', 'desktop', 'tower', 'cpu cabinet'],
            'AC' => ['ac', 'air conditioner', 'air conditioning', 'cooling unit', 'split ac', 'window ac'],
            'Phone' => ['phone', 'mobile', 'smartphone', 'iphone', 'android', 'handset'],
            'Washing Machine' => ['washing machine', 'washer', 'laundry machine'],
            'Refrigerator' => ['refrigerator', 'fridge', 'freezer'],
            'Car' => ['car', 'vehicle', 'automobile'],
            'Tap' => ['tap', 'faucet'],
            'Pipe' => ['pipe', 'pipeline', 'drain pipe', 'plumbing pipe'],
            'Router' => ['router', 'modem', 'wifi router'],
            'Electrical switch' => ['switch', 'socket', 'switchboard', 'mcbm', 'fuse box'],
        ];

        foreach ($rules as $entity => $keywords) {
            foreach ($keywords as $kw) {
                if (preg_match('/(?:^|\s)' . preg_quote($kw, '/') . '(?:$|\s)/u', $text) === 1) {
                    return $entity;
                }
            }
        }

        return null;
    }

    private function detectProblemType(string $text, ?string $entity): ?string
    {
        $rules = [
            'Overheating' => ['overheat', 'overheating', 'gets hot', 'too hot', 'high temperature', 'burning hot'],
            'Not cooling' => ['not cooling', 'does not cool', 'doesnt cool', 'not getting cold', 'warm air', 'no cool air'],
            'Noise' => ['noise', 'noisy', 'loud', 'rattling', 'buzzing', 'grinding', 'screeching', 'clicking sound'],
            'Leakage' => ['leak', 'leaking', 'leakage', 'dripping', 'water seepage', 'water drop'],
            'Not starting' => ['not starting', 'wont start', 'does not start', 'doesnt start', 'not turning on', 'wont turn on', 'wont boot'],
            'Broken or cracked screen' => ['cracked screen', 'broken screen', 'screen cracked', 'display broken', 'shattered screen'],
            'Slow performance' => ['slow', 'lagging', 'freezing', 'hangs', 'poor performance', 'very slow', 'unresponsive'],
            'Battery issue' => ['battery', 'not holding charge', 'drains quickly', 'battery drain', 'swollen battery'],
            'Network issue' => ['no internet', 'network issue', 'connection drops', 'wifi not working', 'no signal'],
            'Power failure' => ['power failure', 'no power', 'power cut', 'electrical failure', 'short circuit', 'tripping'],
            'Physical damage' => ['dropped', 'drop damage', 'physical damage', 'broken body', 'hinge broken'],
        ];

        foreach ($rules as $problem => $keywords) {
            foreach ($keywords as $kw) {
                if (preg_match('/(?:^|\s)' . preg_quote($kw, '/') . '(?:$|\s)/u', $text) === 1) {
                    return $problem;
                }
            }
        }

        return null;
    }

    private function extractSymptoms(string $text): array
    {
        $rules = [
            'overheating' => ['overheat', 'overheating', 'gets hot', 'too hot', 'high temperature', 'burning hot'],
            'loud fan' => ['fan loud', 'loud fan', 'fan noise', 'fan rattling', 'fan buzzing'],
            'loud noise' => ['noise', 'noisy', 'loud', 'rattling', 'buzzing', 'grinding sound'],
            'automatic shutdown' => ['shuts down', 'shutdown', 'turns off suddenly', 'restarts randomly'],
            'water leakage' => ['water leak', 'water leakage', 'leaking water', 'leaking', 'dripping'],
            'not cooling' => ['not cooling', 'does not cool', 'not getting cold', 'warm air'],
            'cracked screen' => ['cracked screen', 'screen cracked', 'broken screen', 'display broken'],
            'slow performance' => ['slow', 'lagging', 'freezing', 'hangs'],
            'charging problem' => ['not charging', 'charging issue', 'does not charge', 'charging port loose'],
            'connection drops' => ['connection drops', 'disconnects', 'no internet', 'wifi drops'],
            'physical damage' => ['dropped', 'physical damage', 'broken body'],
        ];

        $symptoms = [];
        foreach ($rules as $label => $keywords) {
            foreach ($keywords as $kw) {
                if (preg_match('/(?:^|\s)' . preg_quote($kw, '/') . '(?:$|\s)/u', $text) === 1) {
                    $symptoms[] = $label;
                    break;
                }
            }
        }

        if (preg_match('/\bfan\b/u', $text) === 1 && (preg_match('/\b(loud|noise|rattl|buzz)\b/u', $text) === 1)) {
            if (!in_array('loud fan', $symptoms, true)) {
                $symptoms[] = 'loud fan';
            }
        }

        return array_values(array_unique($symptoms));
    }

    private function extractContexts(string $text): array
    {
        $rules = [
            'Gaming' => ['gaming', 'game', 'playing game'],
            'Office work' => ['office', 'work', 'working', 'typing'],
            'After rain' => ['after rain', 'rain', 'raining', 'monsoon'],
            'During charging' => ['charging', 'plugged in', 'on charger'],
            'At night' => ['at night', 'night', 'midnight'],
            'After long usage' => ['long usage', 'after 30 minutes', 'after prolonged use', 'for hours', 'heavy use'],
            'After installation' => ['after installation', 'newly installed', 'installed recently'],
            'After repair' => ['after repair', 'repaired', 'serviced recently'],
            'During startup' => ['during startup', 'startup', 'booting', 'when turned on'],
            'During spinning' => ['spinning', 'spin cycle'],
        ];

        $contexts = [];
        foreach ($rules as $label => $keywords) {
            foreach ($keywords as $kw) {
                if (preg_match('/(?:^|\s)' . preg_quote($kw, '/') . '(?:$|\s)/u', $text) === 1) {
                    $contexts[] = $label;
                    break;
                }
            }
        }

        return array_values(array_unique($contexts));
    }

    private function inferUrgency(string $text, string $userUrgency): string
    {
        if (preg_match('/\b(emergency|dangerous|fire|sparking|flooding|immediately)\b/u', $text) === 1) {
            return 'emergency';
        }
        if (preg_match('/\b(urgent|urgently|cannot work|completely stopped|shut down|shuts down|unusable)\b/u', $text) === 1) {
            return 'high';
        }
        if (preg_match('/\b(soon|getting worse|worsening|irritating)\b/u', $text) === 1) {
            return 'medium';
        }

        return in_array($userUrgency, ['low', 'medium', 'high', 'emergency'], true) ? $userUrgency : 'medium';
    }

    private function inferServiceTypes(?string $entity, ?string $problemType, array $symptoms): array
    {
        $serviceMap = [
            'Laptop' => ['Laptop Cleaning', 'Cooling System Inspection', 'Fan Inspection', 'Thermal Paste Inspection', 'Hardware Diagnostic'],
            'AC' => ['AC Cooling Inspection', 'Leakage Inspection', 'Filter Cleaning', 'Gas Level Inspection'],
            'Washing Machine' => ['Washing Machine Inspection', 'Drainage Inspection', 'Leakage Inspection'],
            'Phone' => ['Screen Replacement Assessment', 'Phone Hardware Inspection', 'Battery Health Diagnostic'],
            'Tap' => ['Tap Repair', 'Leakage Inspection'],
            'Pipe' => ['Pipe Repair', 'Leakage Inspection', 'Drainage Clearing'],
            'Router' => ['Network Troubleshooting', 'Router Configuration'],
            'Electrical switch' => ['Switch Replacement', 'Wiring Inspection'],
        ];

        $services = $entity !== null ? ($serviceMap[$entity] ?? []) : [];
        if (empty($services) && $problemType === 'Leakage') {
            $services = ['Leakage Inspection', 'Plumbing Assessment'];
        }

        return array_values(array_unique($services));
    }

    private function extractKeywords(string $text): array
    {
        $stopWords = ['a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'for', 'from', 'has', 'he', 'in', 'is', 'it', 'its', 'of', 'on', 'that', 'the', 'to', 'was', 'were', 'will', 'with', 'my', 'me', 'i'];
        $tokens = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $keywords = [];
        foreach ($tokens as $token) {
            if (mb_strlen($token) >= 3 && !in_array($token, $stopWords, true) && !in_array($token, $keywords, true)) {
                $keywords[] = $token;
            }
        }

        return array_slice($keywords, 0, 15);
    }

    private function buildEmptyResponse(array $context): array
    {
        return [
            'problem_type' => null,
            'affected_entity' => null,
            'symptoms' => [],
            'context' => [],
            'urgency' => $context['user_urgency'] ?? 'medium',
            'keywords' => [],
            'possible_service_types' => [],
            'location_context' => [
                'city' => (string)($context['city'] ?? ''),
                'area' => (string)($context['area'] ?? ''),
            ],
            'confidence_score' => 0,
            'reasoning_evidence' => ['status' => 'Empty problem description provided; insufficient information for analysis.'],
            'follow_up_questions' => [
                'Please describe the issue you are experiencing with your appliance or equipment.',
                'Which device or home fixture requires service?',
            ],
        ];
    }
}
