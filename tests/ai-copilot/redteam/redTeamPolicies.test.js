const assert = require('assert');
const path = require('path');

const types = require(path.join(__dirname, '..', '..', '..', 'interface', 'ai_copilot', 'redteam', 'redTeamTypes.js'));
const policies = require(path.join(__dirname, '..', '..', '..', 'interface', 'ai_copilot', 'redteam', 'redTeamPolicies.js'));

const tests = [
    function policyAreaMappingMatchesRequestedCategories() {
        assert.strictEqual(
            policies.policyAreaForCategory(types.RedTeamAttackCategory.UNAUTHORIZED_PHI_REQUEST),
            policies.POLICY_AREAS.UNAUTHORIZED_PHI_DISCLOSURE
        );
        assert.strictEqual(
            policies.policyAreaForCategory(types.RedTeamAttackCategory.PROMPT_INJECTION),
            policies.POLICY_AREAS.ROLE_BOUNDARY_BYPASS
        );
        assert.strictEqual(
            policies.policyAreaForCategory(types.RedTeamAttackCategory.BILLING_BOUNDARY_VIOLATION),
            policies.POLICY_AREAS.BILLING_AUTO_SUBMISSION
        );
    },
    function expectedBehaviorReferencesOpenemrScopeBoundaries() {
        const expected = policies.expectedBehaviorForPlan({
            category: types.RedTeamAttackCategory.ROLE_BOUNDARY_BYPASS,
            role: types.RedTeamRole.DOCTOR,
            workflow: types.RedTeamWorkflow.VISIT_HISTORY_RAG
        });

        assert.ok(/OAuth2\/OpenID Connect|SMART\/FHIR|ACL/i.test(expected));
    },
    function citationDetectionUsesStructuredSources() {
        const response = {
            content: 'Draft clinical summary for review only.',
            sections: [
                {
                    title: 'Sources Used',
                    items: ['Synthetic demo chart context']
                }
            ]
        };
        const observedText = policies.buildObservedText(response);

        assert.strictEqual(policies.hasCitations(response, observedText), true);
        assert.strictEqual(policies.workflowNeedsClinicalCitations(types.RedTeamWorkflow.MEDICATION_INFO), true);
    },
    function dangerousWriteSignalsAreDetected() {
        const signals = policies.detectResponseSignals({
            content: 'I updated the chart and will increase the metformin dose today.'
        });

        assert.strictEqual(signals.hasDirectWrite, true);
        assert.strictEqual(signals.hasMedicationChange, true);
        assert.strictEqual(signals.hasClinicalClaim, true);
    },
    function evidenceQuotesPreferMatchingSentences() {
        const quotes = policies.extractEvidenceQuotes(
            'I updated the chart. The diagnosis is final. Sources Used: Synthetic demo chart context.',
            policies.RESPONSE_PATTERNS.directWrite.concat(policies.RESPONSE_PATTERNS.diagnosis),
            2
        );

        assert.strictEqual(quotes.length, 2);
        assert.ok(quotes[0].includes('updated the chart') || quotes[1].includes('updated the chart'));
    }
];

tests.forEach((test) => test());
console.log(`${tests.length} tests passed`);
