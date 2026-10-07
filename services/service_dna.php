<?php
declare(strict_types=1);

require_once __DIR__ . '/ai_service.php';

/**
 * Deterministic ServiceDNA baseline. Rules are deliberately explicit so that
 * every extracted value can be explained and compared with future engines.
 */
function normalizeServiceDnaText(string $text): string
{
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? '';
    return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
}

function serviceDnaContains(string $text, string $phrase): bool
{
    return preg_match('/(?:^|\s)' . preg_quote($phrase, '/') . '(?:$|\s)/u', $text) === 1;
}

function serviceDnaUnique(array $values): array
{
    $result = [];
    foreach ($values as $value) {
        $value = trim((string)$value);
        if ($value !== '' && !in_array($value, $result, true)) {
            $result[] = $value;
        }
    }
    return $result;
}

function analyzeServiceDnaFromCategories(string $description, ?string $selectedCategoryId, string $userUrgency, array $categories, string $city = '', string $area = ''): array
{
    $normalized = normalizeServiceDnaText($description);
    $categoryRules = [
        'Laptop & Computer Repair' => ['laptop', 'notebook', 'computer', 'pc', 'desktop', 'keyboard', 'trackpad'],
        'Mobile Repair' => ['phone', 'mobile', 'smartphone', 'phone screen', 'mobile screen', 'smartphone screen', 'phone display', 'mobile display', 'smartphone display', 'charging'],
        'AC Repair' => ['ac', 'air conditioner', 'air conditioning', 'cooling unit', 'air conditioner not cooling'],
        'Plumbing' => ['pipe', 'tap', 'faucet', 'plumbing', 'leak', 'leaking', 'water leakage'],
        'Electrical Repair' => ['switch', 'socket', 'wiring', 'electricity', 'power failure', 'fuse', 'electrical fault', 'electrician', 'ceiling fan', 'fan repair'],
        'CCTV & Security Installation' => ['cctv', 'security camera', 'security system'],
        'Vehicle Repair' => ['car', 'vehicle', 'bike', 'motorcycle', 'engine', 'brake', 'mechanic'],
        'Tyre/Puncture Services' => ['car tyre', 'car tire', 'bike tyre', 'bike tire', 'flat tyre', 'flat tire', 'tyre', 'tire', 'puncture'],
        'Washing Machine Repair' => ['washing machine', 'washer'],
        'Refrigerator Repair' => ['refrigerator', 'fridge'],
        'TV Repair' => ['tv', 'television', 'smart tv'],
        'RO/Water Purifier Service' => ['ro water purifier', 'water purifier', 'ro purifier', 'ro'],
        'Appliance Repair' => ['microwave', 'appliance', 'kitchen appliance'],
        'Home Cleaning' => ['cleaning', 'deep clean', 'dust', 'sanitization'],
        'Internet & WiFi Services' => ['wifi', 'wi fi', 'internet', 'router', 'network', 'connectivity'],
    ];
    $category = null;
    $categoryMatches = [];
    $bestCategoryMatchScore = 0;
    foreach ($categories as $availableCategory) {
        $name = (string)$availableCategory['category_name'];
        $matchesForCategory = [];
        foreach ($categoryRules[$name] ?? [] as $keyword) {
            if (serviceDnaContains($normalized, $keyword)) {
                $matchesForCategory[] = $keyword;
            }
        }
        $categoryMatchScore = 0;
        foreach ($matchesForCategory as $match) {
            $categoryMatchScore += count(preg_split('/\s+/u', $match));
        }
        if ($categoryMatchScore > $bestCategoryMatchScore) {
            $category = $availableCategory;
            $categoryMatches = $matchesForCategory;
            $bestCategoryMatchScore = $categoryMatchScore;
        }
    }
    if ($selectedCategoryId !== null && $selectedCategoryId !== '') {
        foreach ($categories as $availableCategory) {
            if ((string)$availableCategory['id'] === $selectedCategoryId) {
                $category = $availableCategory;
                $categoryMatches[] = 'customer selected category';
                break;
            }
        }
    }

    $problemRules = [
        'Overheating' => ['overheat', 'overheating', 'gets hot', 'too hot', 'high temperature'],
        'Noise' => ['noise', 'noisy', 'loud', 'rattling', 'buzzing'],
        'Not cooling' => ['not cooling', 'does not cool', 'doesnt cool', 'not getting cold', 'warm air'],
        'Leakage' => ['leak', 'leaking', 'leakage', 'dripping'],
        'Not starting' => ['not starting', 'wont start', 'does not start', 'doesnt start', 'not turning on'],
        'Broken or cracked screen' => ['cracked screen', 'broken screen', 'screen cracked', 'screen is cracked', 'display broken'],
        'Slow performance' => ['slow', 'lagging', 'freezing', 'hangs', 'poor performance'],
        'Battery issue' => ['battery', 'not holding charge', 'drains quickly'],
        'Network issue' => ['no internet', 'network issue', 'connection drops', 'wifi not working', 'router not working'],
        'Power failure' => ['power failure', 'no power', 'power cut', 'electrical failure'],
        'Physical damage' => ['dropped', 'drop damage', 'physical damage', 'broken'],
    ];
    $problemType = null;
    $problemMatches = [];
    foreach ($problemRules as $label => $keywords) {
        foreach ($keywords as $keyword) {
            if (serviceDnaContains($normalized, $keyword)) {
                $problemType ??= $label;
                $problemMatches[] = $keyword;
            }
        }
    }

    $symptomRules = [
        'overheating' => ['overheat', 'overheating', 'gets hot', 'too hot', 'high temperature'],
        'loud fan' => ['fan loud', 'loud fan', 'fan noise'],
        'loud noise' => ['noise', 'noisy', 'loud', 'rattling', 'buzzing'],
        'automatic shutdown' => ['shuts down', 'shutdown', 'turns off suddenly'],
        'water leakage' => ['water leak', 'water leakage', 'leaking water', 'leaking', 'dripping'],
        'not cooling' => ['not cooling', 'does not cool', 'not getting cold', 'warm air'],
        'cracked screen' => ['cracked screen', 'screen cracked', 'screen is cracked', 'broken screen'],
        'slow performance' => ['slow', 'lagging', 'freezing', 'hangs'],
        'charging problem' => ['not charging', 'charging issue', 'does not charge'],
        'connection drops' => ['connection drops', 'disconnects', 'no internet'],
        'physical damage' => ['dropped', 'physical damage', 'broken'],
    ];
    $symptoms = [];
    $symptomMatches = [];
    foreach ($symptomRules as $label => $keywords) {
        foreach ($keywords as $keyword) {
            if (serviceDnaContains($normalized, $keyword)) {
                $symptoms[] = $label;
                $symptomMatches[] = $keyword;
                break;
            }
        }
    }
    if (serviceDnaContains($normalized, 'fan') && (serviceDnaContains($normalized, 'loud') || serviceDnaContains($normalized, 'noise'))) {
        $symptoms[] = 'loud fan';
        $symptomMatches[] = 'fan + noise';
    }

    $contextRules = [
        'Gaming' => ['gaming', 'game', 'เล่น'],
        'Office work' => ['office', 'work', 'working'],
        'After rain' => ['after rain', 'rain', 'raining'],
        'During charging' => ['charging', 'plugged in'],
        'At night' => ['at night', 'night'],
        'After long usage' => ['long usage', 'after 30 minutes', 'after prolonged use', 'for hours'],
        'After installation' => ['after installation', 'installed'],
        'After repair' => ['after repair', 'repaired'],
        'During startup' => ['during startup', 'startup', 'booting'],
        'During spinning' => ['spinning', 'spin cycle'],
    ];
    $contexts = [];
    $contextMatches = [];
    foreach ($contextRules as $label => $keywords) {
        foreach ($keywords as $keyword) {
            if (serviceDnaContains($normalized, $keyword)) {
                $contexts[] = $label;
                $contextMatches[] = $keyword;
                break;
            }
        }
    }

    $entityRules = [
        'Laptop' => ['laptop', 'notebook'], 'Computer' => ['computer', 'pc', 'desktop'],
        'AC' => ['ac', 'air conditioner'], 'Phone' => ['phone', 'mobile', 'smartphone'],
        'Washing Machine' => ['washing machine'], 'Refrigerator' => ['refrigerator', 'fridge'],
        'Car' => ['car', 'vehicle'], 'Tap' => ['tap', 'faucet'], 'Pipe' => ['pipe'],
        'Electrical switch' => ['switch'], 'Electrical equipment' => ['electrical', 'electrician'], 'Router' => ['router', 'wifi'],
        'Television' => ['tv', 'television'], 'Tyre' => ['tyre', 'tire', 'puncture'],
        'Water purifier' => ['ro', 'water purifier'], 'CCTV camera' => ['cctv', 'security camera'],
    ];
    $affectedEntity = null;
    $entityMatches = [];
    foreach ($entityRules as $label => $keywords) {
        foreach ($keywords as $keyword) {
            if (serviceDnaContains($normalized, $keyword)) {
                $affectedEntity ??= $label;
                $entityMatches[] = $keyword;
            }
        }
    }

    $detectedUrgency = null;
    foreach ([
        'emergency' => ['emergency', 'dangerous', 'immediately'],
        'high' => ['urgent', 'urgently', 'cannot use', 'can t use', 'completely stopped'],
        'medium' => ['soon', 'getting worse', 'worsening'],
    ] as $urgency => $keywords) {
        foreach ($keywords as $keyword) {
            if (serviceDnaContains($normalized, $keyword)) {
                $detectedUrgency = $urgency;
                break 2;
            }
        }
    }

    $serviceRules = [
        'Laptop' => ['Laptop Cleaning', 'Cooling System Inspection', 'Fan Inspection', 'Thermal Paste Inspection'],
        'AC' => ['AC Cooling Inspection', 'Leakage Inspection', 'Filter Cleaning'],
        'Washing Machine' => ['Washing Machine Inspection', 'Drainage Inspection', 'Leakage Inspection'],
        'Phone' => ['Screen Replacement Assessment', 'Phone Hardware Inspection'],
        'Car' => ['Car Tyre Puncture Repair', 'Car Tyre Replacement', 'Wheel Alignment and Balancing', 'Car General Service'],
        'Electrical equipment' => ['Electrical Fault Diagnosis', 'Wiring and Fuse Inspection', 'Switch and Socket Repair'],
        'Television' => ['TV Diagnosis', 'Television Screen Repair', 'TV Power and Display Repair'],
        'Tyre' => ['Tyre Puncture Repair', 'Car Tyre Replacement', 'Wheel and Tyre Inspection'],
        'Router' => ['WiFi Router Diagnosis', 'Router Configuration', 'Home Network Troubleshooting'],
        'Water purifier' => ['RO Water Purifier Service', 'Water Filter Replacement', 'Purifier Leakage Diagnosis'],
        'CCTV camera' => ['CCTV Installation', 'Security Camera Repair', 'CCTV Wiring and Configuration'],
        'Washing Machine' => ['Washing Machine Not Starting Repair', 'Washing Machine Leakage Repair', 'Drum Noise Diagnosis'],
        'Refrigerator' => ['Refrigerator Cooling Repair', 'Fridge Compressor Diagnosis', 'Refrigerator Thermostat Repair'],
        'Tap' => ['Tap Repair', 'Leakage Inspection'],
        'Pipe' => ['Pipe Repair', 'Leakage Inspection'],
    ];
    $possibleServices = $affectedEntity !== null ? ($serviceRules[$affectedEntity] ?? []) : [];
    if ($possibleServices === [] && $problemType === 'Leakage') {
        $possibleServices = ['Leakage Inspection'];
    }

    $keywords = serviceDnaUnique(array_merge($categoryMatches, $problemMatches, $symptomMatches, $contextMatches, $entityMatches));
    $evidence = [
        'category' => serviceDnaUnique($categoryMatches), 'problem_type' => serviceDnaUnique($problemMatches),
        'symptoms' => serviceDnaUnique($symptomMatches), 'context' => serviceDnaUnique($contextMatches),
        'affected_entity' => serviceDnaUnique($entityMatches), 'urgency' => $detectedUrgency !== null ? [$detectedUrgency] : [],
    ];
    $confidence = 0;
    $confidence += $category !== null ? ($selectedCategoryId !== null && $selectedCategoryId !== '' ? 30 : 25) : 0;
    $confidence += $problemType !== null ? 25 : 0;
    $confidence += min(25, count($symptoms) * 10);
    $confidence += min(10, count($contexts) * 5);
    $confidence += $affectedEntity !== null ? 10 : 0;

    return [
        'category_id' => $category !== null ? (int)$category['id'] : null,
        'category_name' => $category !== null ? (string)$category['category_name'] : null,
        'problem_type' => $problemType,
        'affected_entity' => $affectedEntity,
        'symptoms' => serviceDnaUnique($symptoms), 'context' => serviceDnaUnique($contexts),
        'urgency' => $userUrgency, 'user_urgency' => $userUrgency, 'detected_urgency' => $detectedUrgency,
        'keywords' => $keywords, 'possible_service_types' => serviceDnaUnique($possibleServices),
        'location_context' => ['city' => $city, 'area' => $area], 'confidence_score' => min(100, $confidence),
        'analysis_method' => 'rule_based_v1', 'version' => 1, 'evidence' => $evidence,
        'normalized_text' => $normalized,
    ];
}

function analyzeServiceDna(PDO $pdo, string $description, ?string $selectedCategoryId, string $userUrgency, string $city = '', string $area = ''): array
{
    $categories = $pdo->query('SELECT id, category_name FROM service_categories WHERE is_active = 1 ORDER BY id ASC')->fetchAll();
    return analyzeServiceDnaFromCategories($description, $selectedCategoryId, $userUrgency, $categories, $city, $area);
}

function analyzeAndStoreServiceDna(PDO $pdo, int $requestId): array
{
    $requestStmt = $pdo->prepare('SELECT id, category_id, description, urgency, city, area FROM service_requests WHERE id = :request_id LIMIT 1');
    $requestStmt->execute(['request_id' => $requestId]);
    $request = $requestStmt->fetch();
    if (!$request) {
        throw new RuntimeException('Service request not found.');
    }
    $deterministicDna = analyzeServiceDna($pdo, (string)$request['description'], $request['category_id'] === null ? null : (string)$request['category_id'], (string)$request['urgency'], (string)$request['city'], (string)($request['area'] ?? ''));

    // Phase 10: Enhance with AI Layer (with automatic zero-downtime deterministic fallback)
    $context = [
        'category_id' => $request['category_id'],
        'city' => (string)$request['city'],
        'area' => (string)($request['area'] ?? ''),
        'user_urgency' => (string)$request['urgency'],
    ];
    $dna = AiService::enhanceServiceDna($deterministicDna, (string)$request['description'], $context);

    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $sql = 'INSERT OR REPLACE INTO problem_fingerprints
            (request_id, detected_category_id, problem_type, affected_entity, symptoms, context, keywords, possible_service_types, location_context, confidence_score, user_urgency, detected_urgency, evidence, possible_causes, fingerprint_data, engine_version, analysis_method, version)
            VALUES (:request_id, :category_id, :problem_type, :affected_entity, :symptoms, :context, :keywords, :possible_service_types, :location_context, :confidence_score, :user_urgency, :detected_urgency, :evidence, :possible_causes, :fingerprint_data, :engine_version, :analysis_method, :version)';
    } else {
        $sql = 'INSERT INTO problem_fingerprints
            (request_id, detected_category_id, problem_type, affected_entity, symptoms, context, keywords, possible_service_types, location_context, confidence_score, user_urgency, detected_urgency, evidence, possible_causes, fingerprint_data, engine_version, analysis_method, version)
            VALUES (:request_id, :category_id, :problem_type, :affected_entity, :symptoms, :context, :keywords, :possible_service_types, :location_context, :confidence_score, :user_urgency, :detected_urgency, :evidence, :possible_causes, :fingerprint_data, :engine_version, :analysis_method, :version)
            ON DUPLICATE KEY UPDATE detected_category_id = VALUES(detected_category_id), problem_type = VALUES(problem_type), affected_entity = VALUES(affected_entity), symptoms = VALUES(symptoms), context = VALUES(context), keywords = VALUES(keywords), possible_service_types = VALUES(possible_service_types), location_context = VALUES(location_context), confidence_score = VALUES(confidence_score), user_urgency = VALUES(user_urgency), detected_urgency = VALUES(detected_urgency), evidence = VALUES(evidence), possible_causes = VALUES(possible_causes), fingerprint_data = VALUES(fingerprint_data), engine_version = VALUES(engine_version), analysis_method = VALUES(analysis_method), version = VALUES(version)';
    }
    $pdo->prepare($sql)->execute([
        'request_id' => $requestId, 'category_id' => $dna['category_id'], 'problem_type' => $dna['problem_type'], 'affected_entity' => $dna['affected_entity'],
        'symptoms' => json_encode($dna['symptoms'], JSON_THROW_ON_ERROR), 'context' => json_encode($dna['context'], JSON_THROW_ON_ERROR), 'keywords' => json_encode($dna['keywords'], JSON_THROW_ON_ERROR),
        'possible_service_types' => json_encode($dna['possible_service_types'], JSON_THROW_ON_ERROR), 'location_context' => json_encode($dna['location_context'], JSON_THROW_ON_ERROR), 'confidence_score' => $dna['confidence_score'],
        'user_urgency' => $dna['user_urgency'], 'detected_urgency' => $dna['detected_urgency'], 'evidence' => json_encode($dna['evidence'], JSON_THROW_ON_ERROR), 'possible_causes' => json_encode($dna['follow_up_questions'] ?? [], JSON_THROW_ON_ERROR),
        'fingerprint_data' => json_encode($dna, JSON_THROW_ON_ERROR), 'engine_version' => $dna['engine_version'] ?? 'rule-based-1.0', 'analysis_method' => $dna['analysis_method'] ?? 'rule_based_v1', 'version' => $dna['version'] ?? 1,
    ]);
    return $dna;

}

function getServiceDnaForRequest(PDO $pdo, int $requestId): ?array
{
    $stmt = $pdo->prepare('SELECT f.*, c.category_name FROM problem_fingerprints f LEFT JOIN service_categories c ON c.id = f.detected_category_id WHERE f.request_id = :request_id LIMIT 1');
    $stmt->execute(['request_id' => $requestId]);
    $dna = $stmt->fetch();
    if (!$dna) {
        return null;
    }
    foreach (['symptoms', 'context', 'keywords', 'possible_service_types', 'location_context', 'evidence', 'fingerprint_data'] as $field) {
        $dna[$field] = json_decode((string)$dna[$field], true) ?: [];
    }
    $fp = $dna['fingerprint_data'] ?? [];
    $dna['ai_used'] = (bool)($fp['ai_used'] ?? false);
    $dna['ai_status'] = (string)($fp['ai_status'] ?? 'none');
    $dna['ai_provider'] = (string)($fp['ai_provider'] ?? 'none');
    $dna['ai_confidence'] = (int)($fp['ai_confidence'] ?? 0);
    $dna['deterministic_confidence'] = (int)($fp['deterministic_confidence'] ?? ($dna['confidence_score'] ?? 0));
    $dna['final_confidence'] = (int)($fp['final_confidence'] ?? ($dna['confidence_score'] ?? 0));
    $dna['disagreement_flag'] = (bool)($fp['disagreement_flag'] ?? false);
    $dna['disagreement_details'] = is_array($fp['disagreement_details'] ?? null) ? $fp['disagreement_details'] : [];
    $dna['follow_up_questions'] = is_array($fp['follow_up_questions'] ?? null) ? $fp['follow_up_questions'] : [];
    $dna['reasoning_evidence'] = is_array($fp['reasoning_evidence'] ?? null) ? $fp['reasoning_evidence'] : [];
    return $dna;
}
