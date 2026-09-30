# Phase 6 Provider Matching

Phase 6 ranks approved local providers against a customer's ServiceDNA using deterministic, explainable rules. It does not diagnose the problem, book a provider, process payments, compare quotes, or implement ADCS.

## Inputs and candidate generation

The matching layer reads the request and its existing ServiceDNA fields: category, problem type, affected entity, symptoms, context, keywords, possible service types, city, area, and confidence. Provider candidates come from active services joined to approved provider profiles and provider users. Offline providers are excluded. Missing optional descriptions, ratings, job history, or area values do not eliminate a provider; they reduce the relevant score component.

The customer request is loaded with an ownership condition before candidate results are exposed. Candidate retrieval uses one SQL query with aggregated review and completed-booking statistics, then aggregates multiple services per provider in memory.

## Scoring

The initial research configuration is centralized in `matchingWeights()` and totals 100 points:

- Category compatibility: 25
- Service type compatibility: 20
- Problem/symptom/context compatibility: 20
- Keyword/skill overlap: 15
- Location compatibility: 10
- Provider quality: 10

A minimum score of 40 is required for a strong match. These are implementation weights, not a claim of scientific optimality.

Category compatibility requires the ServiceDNA/request category to match one of the provider's service categories. Service-type and technical compatibility use normalized phrase overlap. Keyword compatibility uses normalized token overlap. Location awards points for matching city and area. Provider quality uses verified status, availability, rating evidence, and completed jobs with bounded contributions so a single review cannot dominate a larger history.

## Ranking and explanations

Results sort deterministically by total score descending, technical score descending, provider quality descending, and provider ID ascending. Each result includes a score breakdown, matching method/version, rank, and reasons generated only from positive score evidence or explicit missing/limited evidence.

Current results are stored in `matching_results`, keyed by request, provider, method, and version. Recalculation removes the current method/version rows first, then upserts the current ranked results. This prevents stale or duplicate current rankings while preserving the schema for later versioned experiments.

## Edge cases and limitations

No ServiceDNA falls back to request text/category where available but produces conservative results. Unknown ServiceDNA usually stays below the threshold. No candidates or no strong candidates produce an explicit empty state. There is no coordinate distance calculation because the current schema has only city and area. Provider ratings and job history are optional. Matching is a recommendation system and does not guarantee technical diagnosis, availability, price, or service outcome.

Future versions may add learned ranking, embeddings, or richer location signals behind a new matching method/version. ADCS, booking, payments, reviews, notifications, and provider analytics are outside Phase 6.

Run the development-only CLI scenarios with:

```text
D:\xampp\php\php.exe services\matching_test.php
```
