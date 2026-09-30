# Phase 7 ADCS

ADCS compares independent preliminary provider assessments for the same service request. It reports agreement, partial agreement, disagreement, and assessment outliers as decision-support evidence. It does not diagnose a problem or replace physical inspection.

## Workflow

Phase 6 matching results determine which approved, non-offline provider may assess a request. Each provider submits one independent assessment per request. The unique `(request_id, provider_id)` constraint makes later submissions controlled updates. Providers see the original request and ServiceDNA context but not other provider assessments or consensus before submitting.

Assessment fields are problem type, affected entity, symptoms, suggested service types, urgency, estimated severity, confidence, notes, and status. Withdrawal removes the assessment from active consensus while retaining its record and history.

## Consensus algorithm

`adcs_rule_based_v1` normalizes categorical values case-insensitively and counts list values by provider occurrence. A value at or above the configurable 60% threshold is consensus. A nonzero value below the threshold is partial consensus unless the field is strongly tied, which is disagreement. Zero assessments produce `no_evidence`; one produces `insufficient_evidence` and a score of zero.

The overall score is the average of nonempty field agreement ratios across problem type, affected entity, symptoms, suggested service types, urgency, and severity, normalized to 0-100. It is not an accuracy claim. Multiple strongly conflicting fields produce the overall `disagreement` status.

For three or more assessments, an assessment is an outlier when it differs from the majority on at least 60% of the five important comparison areas. The UI uses neutral language such as "assessment outlier" and never labels a provider wrong.

## Storage and security

`provider_assessments` preserves provider identity, request identity, matching-result eligibility, timestamps, structured JSON lists, and status. `adcs_results` stores one recalculated aggregate per request with JSON consensus, disagreement, and outlier data, score, method, version, and timestamps. Both tables use foreign keys and indexes.

Customer views verify request ownership. Provider submission verifies the authenticated provider profile and a current Phase 6 matching result. Admin diagnostics require the admin role. POST actions use CSRF validation, prepared statements are used for database access, and rendered values are escaped.

## Limitations

The initial threshold and rule set are implementation parameters for reproducible experiments, not scientifically optimized values. The system does not infer truth from agreement, does not use paid AI, and does not implement booking, payments, reviews, notifications, or learned consensus.

Run the development-only fixtures with:

```text
D:\xampp\php\php.exe services\adcs_test.php
```