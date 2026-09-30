-- Phase 10: AI/ML Intelligence Enhancement
-- ServeIQ Phase 10 leverages the existing problem_fingerprints table schema.
-- Enhanced AI telemetry (ai_used, ai_provider, ai_confidence, deterministic_confidence,
-- disagreement_flag, disagreement_details, follow_up_questions, reasoning_evidence)
-- is stored within the JSON columns `fingerprint_data` and `evidence`.

-- Ensure analysis_method supports hybrid descriptors
-- engine_version: 'serveiq-hybrid-1.0' or 'rule-based-1.0'
-- analysis_method: 'hybrid_ai_v1' or 'rule_based_v1'

-- Optional verification query:
-- SELECT id, request_id, analysis_method, engine_version, confidence_score,
--        JSON_EXTRACT(fingerprint_data, '$.ai_used') AS ai_used,
--        JSON_EXTRACT(fingerprint_data, '$.disagreement_flag') AS disagreement_flag,
--        JSON_EXTRACT(fingerprint_data, '$.follow_up_questions') AS follow_up_questions
-- FROM problem_fingerprints
-- ORDER BY id DESC LIMIT 10;
